<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\ImportStructuredPurchaseInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\DocumentLock;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\InvoiceExtractionDecision;
use MyInvoice\Service\Import\InvoiceExtractionRouter;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

final class ImportStructuredPurchaseInvoiceActionTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testClientCreatePermissionImportsStandaloneIsdocAndReturnsDraftId(): void
    {
        $xml = '<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>TEST-1</ID></Invoice>';
        $decision = new InvoiceExtractionDecision(
            source: 'isdoc',
            isdocXml: $xml,
            parsed: ['supplier_ic' => '12345678', 'invoices' => [[
                'issue_date' => '2026-05-01',
                'tax_date' => '2026-05-01',
            ]]],
            useLlm: false,
            isdocPresent: true,
            parseError: null,
            isdocxPackage: null,
        );

        $router = $this->createMock(InvoiceExtractionRouter::class);
        $router->expects(self::once())->method('decide')->with($xml, 'isdoc')->willReturn($decision);

        $importer = $this->createMock(InvoiceImportService::class);
        $importer->expects(self::once())->method('importBundle')
            ->with(
                [['name' => 'faktura.isdoc', 'content' => $xml]],
                7,
                11,
                'purchase',
            )
            ->willReturn([
                'summary' => ['created' => 1, 'skipped' => 0, 'failed' => 0],
                'results' => [[
                    'status' => 'created',
                    'purchase_invoice_id' => 42,
                    'duplicate' => false,
                ]],
            ]);

        $locks = $this->createMock(DocumentLockService::class);
        $locks->expects(self::once())->method('forDate')->with(7, '2026-05-01')
            ->willReturn($this->openLock());

        $response = $this->action($router, $importer, $locks)(
            $this->request($xml, 'faktura.isdoc'),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([
            'purchase_invoice_id' => 42,
            'purchase_invoice_ids' => [42],
            'source' => 'isdoc',
            'duplicate' => false,
        ], $this->json($response));
    }

    public function testPlainPdfReturnsFallbackCodeWithoutCreatingDraft(): void
    {
        $pdf = "%PDF-1.7\nplain";
        $router = $this->createStub(InvoiceExtractionRouter::class);
        $router->method('decide')->willReturn(new InvoiceExtractionDecision(
            source: 'ai',
            isdocXml: null,
            parsed: null,
            useLlm: true,
            isdocPresent: false,
            parseError: null,
            isdocxPackage: null,
        ));
        $importer = $this->createMock(InvoiceImportService::class);
        $importer->expects(self::never())->method('importBundle');
        $locks = $this->createStub(DocumentLockService::class);

        $response = $this->action($router, $importer, $locks)(
            $this->request($pdf, 'faktura.pdf'),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no_embedded_isdoc', $this->json($response)['error']['code'] ?? null);
    }

    public function testClientCannotImportIntoClosedPeriod(): void
    {
        $xml = '<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>TEST-2</ID></Invoice>';
        $router = $this->createStub(InvoiceExtractionRouter::class);
        $router->method('decide')->willReturn(new InvoiceExtractionDecision(
            source: 'isdoc',
            isdocXml: $xml,
            parsed: ['supplier_ic' => '12345678', 'invoices' => [['issue_date' => '2025-12-01']]],
            useLlm: false,
            isdocPresent: true,
            parseError: null,
            isdocxPackage: null,
        ));
        $importer = $this->createMock(InvoiceImportService::class);
        $importer->expects(self::never())->method('importBundle');
        $locks = $this->createStub(DocumentLockService::class);
        $locks->method('forDate')->willReturn(new DocumentLock(
            booked: false,
            bookedAt: null,
            posted: false,
            journalEntryId: null,
            inClosedPeriod: true,
            inClosingPeriod: false,
            periodStatus: 'closed',
        ));

        $response = $this->action($router, $importer, $locks)(
            $this->request($xml, 'faktura.isdoc'),
            new Response(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('document_locked', $this->json($response)['error']['code'] ?? null);
    }

    private function action(
        InvoiceExtractionRouter $router,
        InvoiceImportService $importer,
        DocumentLockService $locks,
    ): ImportStructuredPurchaseInvoiceAction {
        $logger = $this->createStub(ActivityLogger::class);
        $ipMatcher = $this->createStub(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        // ISDOC v testech neuvádí odběratele, takže se jiné firmy nehledají
        // a služby pro ně se nezavolají.
        $crossSupplier = (new \ReflectionClass(\MyInvoice\Security\CrossSupplierAuthorization::class))->newInstanceWithoutConstructor();
        $addressees = (new \ReflectionClass(\MyInvoice\Service\Import\DocumentAddresseeResolver::class))->newInstanceWithoutConstructor();
        return new ImportStructuredPurchaseInvoiceAction($router, $importer, $locks, $logger, $ipMatcher, $crossSupplier, $addressees);
    }

    private function request(string $content, string $name): \Psr\Http\Message\ServerRequestInterface
    {
        $tmp = tempnam(sys_get_temp_dir(), 'isdoc-action-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);
        $this->tempFiles[] = $tmp;
        $upload = new UploadedFile($tmp, $name, 'application/octet-stream', strlen($content), UPLOAD_ERR_OK);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoices/import-structured', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withUploadedFiles(['file' => $upload])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 11, 'role' => 'client'])
            ->withAttribute('auth.effective_role', new EffectiveRole(
                4,
                'Klient',
                'client',
                true,
                ['purchase_invoices.create' => 2],
                'client',
            ));
    }

    private function openLock(): DocumentLock
    {
        return new DocumentLock(
            booked: false,
            bookedAt: null,
            posted: false,
            journalEntryId: null,
            inClosedPeriod: false,
            inClosingPeriod: false,
            periodStatus: null,
        );
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
