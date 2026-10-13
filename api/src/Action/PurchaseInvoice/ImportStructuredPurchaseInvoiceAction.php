<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\InvoiceExtractionRouter;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Support\PdfBytes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/purchase-invoices/import-structured
 *
 * Jednosouborový deterministický import pro editor nové přijaté faktury. Přijímá
 * samostatný ISDOC, ISDOCX balíček nebo PDF/A-3 s vloženým ISDOC, vytvoří draft
 * přes stejný mapper jako dávkový import a vrátí jeho ID k otevření v editoru.
 *
 * Obyčejné PDF bez ISDOC vrací rozlišitelný kód `no_embedded_isdoc`, aby ho
 * frontend ponechal jako běžnou přílohu k ručně vyplněnému dokladu. Tato cesta
 * nikdy nevolá LLM.
 */
final class ImportStructuredPurchaseInvoiceAction
{
    use GuardsDocumentLock;

    private const MAX_FILE_SIZE = 20 * 1024 * 1024;
    private const ALLOWED_EXTENSIONS = ['isdoc', 'isdocx', 'pdf'];

    public function __construct(
        private readonly InvoiceExtractionRouter $router,
        private readonly InvoiceImportService $importer,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Security\CrossSupplierAuthorization $crossSupplier,
        private readonly \MyInvoice\Service\Import\DocumentAddresseeResolver $addressees,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění vytvořit přijatou fakturu.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Soubor nebyl odeslán (field name: file).', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
        }

        $reportedSize = (int) ($file->getSize() ?? 0);
        if ($reportedSize > self::MAX_FILE_SIZE) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 20 MiB).', 413);
        }

        $originalName = str_replace('\\', '/', (string) ($file->getClientFilename() ?? ''));
        $originalName = basename($originalName);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return Json::error(
                $response,
                'unsupported_format',
                'Podporovány jsou pouze soubory ISDOC, ISDOCX a PDF s vloženým ISDOC.',
                415,
            );
        }

        $bytes = (string) $file->getStream()->getContents();
        $size = strlen($bytes);
        if ($size === 0) {
            return Json::error($response, 'empty_file', 'Soubor je prázdný.', 400);
        }
        if ($size > self::MAX_FILE_SIZE) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 20 MiB).', 413);
        }
        if ($extension === 'pdf') {
            $bytes = PdfBytes::normalize($bytes);   // hlavička posunutá o prázdný řádek / BOM
        }
        if ($extension === 'pdf' && !str_starts_with($bytes, '%PDF-')) {
            return Json::error($response, 'invalid_document', 'Soubor není platný PDF dokument.', 422);
        }

        try {
            $decision = $this->router->decide($bytes, $extension);
        } catch (\Throwable $e) {
            return Json::error($response, 'invalid_document', $e->getMessage(), 422);
        }

        if ($decision->useLlm) {
            if ($extension === 'pdf' && !$decision->isdocPresent) {
                return Json::error(
                    $response,
                    'no_embedded_isdoc',
                    'PDF neobsahuje vložený ISDOC. Bude použit jako příloha ručně vyplněné faktury.',
                    422,
                );
            }
            return Json::error(
                $response,
                'invalid_isdoc',
                $decision->parseError !== null
                    ? 'ISDOC se nepodařilo načíst: ' . $decision->parseError
                    : 'Soubor neobsahuje platný ISDOC.',
                422,
            );
        }

        // ISDOC s odběratelem jiné firmy uživatele se naimportuje do ní (jen do firmy,
        // kde smí přijaté faktury zakládat). Odběratel je ve strojových datech, nic se neodhaduje.
        $current = $supplierId;
        $client = (array) (((($decision->parsed ?? [])['invoices'] ?? [])[0] ?? [])['client'] ?? []);
        $others = ($client['ic'] ?? $client['dic'] ?? null) !== null
            ? array_values(array_filter(
                $this->crossSupplier->suppliersAllowing($request, ['purchase_invoices.create']),
                static fn (int $id): bool => $id !== $current,
            ))
            : [];
        if ($others !== []) {
            $routed = $this->addressees->byBuyer(
                isset($client['ic']) ? (string) $client['ic'] : null,
                isset($client['dic']) ? (string) $client['dic'] : null,
                [$current, ...$others],
            )['supplier_id'];
            if ($routed !== null) {
                $supplierId = $routed;
            }
        }

        // Stejný zámek jako u ručně vytvořeného dokladu. Administrátorský dávkový
        // import je migrační nástroj, ale klientský editor nesmí obejít uzavřené období.
        foreach ((array) (($decision->parsed ?? [])['invoices'] ?? []) as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }
            $refDate = DocumentLockService::purchaseRefDate($invoice);
            if ($refDate !== null) {
                $denied = $this->denyIfLocked(
                    $request,
                    $response,
                    $this->locks->forDate($supplierId, $refDate),
                    'purchase_invoice',
                    null,
                );
                if ($denied !== null) {
                    return $denied;
                }
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        // Cross-tenant guard v IsdocToPurchaseInvoiceMapper porovnává IČO odběratele
        // z ISDOC proti IČO tenanta, ale jen když ho doklad UVÁDÍ — bez něj mlčky
        // projde. U administrátorského dávkového importu je ta shovívavost záměr
        // (migrační nástroj), tady je endpoint dostupný i roli `client`, takže si
        // aspoň zaznamenáme, že se guard neuplatnil. Blokovat to nechceme: ISDOC bez
        // IČO odběratele je legitimní doklad a odmítat ho by rozbilo funkční import
        // kvůli riziku, které uživatel s právem `purchase_invoices.create` stejně
        // naplní ručním založením dokladu.
        foreach ((array) (($decision->parsed ?? [])['invoices'] ?? []) as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }
            $buyerIc = preg_replace('/\D+/', '', (string) ($invoice['client']['ic'] ?? ''));
            if ($buyerIc === null || $buyerIc === '') {
                $this->logger->log(
                    'purchase_invoice.structured_import_without_buyer_ic',
                    $userId,
                    'purchase_invoice',
                    null,
                    ['filename' => $originalName, 'source' => $decision->source],
                    $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                    $request->getHeaderLine('User-Agent'),
                );
                break;
            }
        }

        try {
            $report = $this->importer->importBundle(
                [['name' => $originalName, 'content' => $bytes]],
                $supplierId,
                $userId,
                'purchase',
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return Json::error($response, 'structured_import_failed', $e->getMessage(), 422);
        }

        $createdRows = array_values(array_filter(
            (array) ($report['results'] ?? []),
            static fn(mixed $row): bool => is_array($row) && (int) ($row['purchase_invoice_id'] ?? 0) > 0,
        ));
        if ($createdRows === []) {
            $first = (array) (($report['results'] ?? [])[0] ?? []);
            return Json::error(
                $response,
                'structured_import_failed',
                (string) ($first['reason'] ?? 'ISDOC se nepodařilo importovat.'),
                422,
            );
        }

        $ids = array_values(array_map(
            static fn(array $row): int => (int) $row['purchase_invoice_id'],
            $createdRows,
        ));
        $duplicate = !empty($createdRows[0]['duplicate']);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            'purchase_invoice.structured_imported',
            $userId,
            'purchase_invoice',
            $ids[0],
            [
                'source' => $decision->source,
                'filename' => $originalName,
                'purchase_invoice_ids' => $ids,
                'duplicate' => $duplicate,
            ] + ($supplierId !== $current ? ['uploaded_in_supplier_id' => $current] : []),
            $ip,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        $target = [];
        if ($supplierId !== $current) {
            $firm = $this->crossSupplier->describe([$supplierId])[0] ?? null;
            $target = ['target_supplier_id' => $supplierId, 'target_supplier_name' => $firm['name'] ?? null];
        }
        return Json::ok($response, [
            'purchase_invoice_id' => $ids[0],
            'purchase_invoice_ids' => $ids,
            'source' => $decision->source,
            'duplicate' => $duplicate,
        ] + $target, $duplicate ? 200 : 201);
    }
}
