<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\CreditCard\CreditCardAccounts;
use MyInvoice\Service\Accounting\CreditCard\CreditCardConversionService;
use MyInvoice\Service\Accounting\CreditCard\CreditCardSettingsService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\CreditCard\CreditCardOverview;
use MyInvoice\Service\Bank\CreditCard\CreditCardStatementImportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kreditní karty - úvěrové účty ke kartě (Firma → Kreditní karty):
 *   GET  /api/credit-cards                  - přehled (?include_archived=1)
 *   GET  /api/credit-cards/{id}             - detail s výpisy a pohyby
 *   PUT  /api/credit-cards/{id}             - úprava (název, limit, účet a VS pro splátku, poznámka)
 *   PUT  /api/credit-cards/{id}/analytic    - ruční výběr analytiky 231
 *   PUT  /api/credit-cards/{id}/purchase-mode - režim nákupů účtu (clearing | direct | null = výchozí firmy)
 *   PUT  /api/credit-cards/{id}/clearing-analytic - ruční výběr analytiky mezičlenu (account_code, confirm)
 *   POST /api/credit-cards/{id}/post-pending - zaúčtovat čekající pohyby výpisů účtu automatikou
 *   POST /api/credit-cards/{id}/opening     - zaúčtovat počáteční dluh z prvního výpisu (contra_account_code, entry_date)
 *   POST /api/credit-cards/{id}/archive     - archivace
 *   POST /api/credit-cards/{id}/restore     - obnovení
 *   POST /api/credit-cards/import           - načtení PDF výpisu (multipart file, ?credit_card_account_id)
 *   POST /api/credit-cards/convert          - převod bankovního účtu na kreditní kartu (bank_account_id)
 *   GET  /api/credit-cards/settings         - nastavení účtování
 *   PUT  /api/credit-cards/settings         - uložení nastavení účtování
 *
 * RBAC: RoutePermissionMap (čtení `bank`, import `bank.import`, účtování `bank.post`,
 * evidence účtu `settings.bank_accounts`); Action ho u zápisů do účetnictví ověřuje znovu.
 */
final class CreditCardAction
{
    use AccountingActionSupport;

    private const MAX_PDF_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly CreditCardAccountRepository $accounts,
        private readonly CreditCardOverview $overview,
        private readonly CreditCardStatementImportService $importer,
        private readonly CreditCardConversionService $conversion,
        private readonly CreditCardSettingsService $settings,
        private readonly CreditCardAccounts $analytics,
        private readonly \MyInvoice\Service\Accounting\CreditCard\CreditCardPostingService $postingActions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Security\CrossSupplierAuthorization $crossSupplier,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $includeArchived = !empty($request->getQueryParams()['include_archived']);
        return Json::ok($response, ['accounts' => $this->overview->list($this->currentSupplierId($request), $includeArchived)]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $detail = $this->overview->detail($this->currentSupplierId($request), (int) ($args['id'] ?? 0));
        if ($detail === null) {
            return Json::error($response, 'not_found', 'Úvěrový účet nenalezen.', 404);
        }
        return Json::ok($response, $detail);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->accounts->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Úvěrový účet nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $errors] = self::normalize($body);
        if ($errors !== []) {
            return Json::error($response, 'validation_failed', (string) reset($errors), 422, ['errors' => $errors]);
        }
        $this->accounts->update($supplierId, $id, $data);
        $this->log($request, 'credit_card.updated', $id, ['label' => $data['label'], 'credit_limit' => $data['credit_limit']]);
        return Json::ok($response, $this->overview->detail($supplierId, $id));
    }

    public function setAnalytic(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $code = trim((string) (((array) ($request->getParsedBody() ?? []))['account_code'] ?? ''));
        try {
            $this->analytics->assignManually($supplierId, $id, $code);
            $this->log($request, 'credit_card.analytic_changed', $id, ['account_code' => $code]);
            return Json::ok($response, $this->overview->detail($supplierId, $id));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    public function setPurchaseMode(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $raw = ((array) ($request->getParsedBody() ?? []))['purchase_mode'] ?? null;
        $mode = $raw === null || $raw === '' ? null : (string) $raw;
        try {
            $this->postingActions->setPurchaseMode($supplierId, $id, $mode);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'credit_card.purchase_mode_changed', $id, ['purchase_mode' => $mode]);
        return Json::ok($response, $this->overview->detail($supplierId, $id));
    }

    public function setClearingAnalytic(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($body['account_code'] ?? ''));
        try {
            $this->postingActions->setClearingAnalytic($supplierId, $id, $code !== '' ? $code : null, !empty($body['confirm']));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'credit_card.clearing_analytic_changed', $id, ['account_code' => $code !== '' ? $code : null]);
        return Json::ok($response, $this->overview->detail($supplierId, $id));
    }

    public function postPending(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        try {
            $result = $this->postingActions->postPending($supplierId, $id, $this->userId($request));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'credit_card.pending_posted', $id, $result);
        return Json::ok($response, ['result' => $result, 'detail' => $this->overview->detail($supplierId, $id)]);
    }

    public function postOpening(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->postingActions->postOpening(
                $supplierId,
                $id,
                isset($body['contra_account_code']) ? (string) $body['contra_account_code'] : null,
                isset($body['entry_date']) ? (string) $body['entry_date'] : null,
                $this->userId($request),
            );
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'credit_card.opening_posted', $id, $result);
        return Json::ok($response, ['result' => $result, 'detail' => $this->overview->detail($supplierId, $id)]);
    }

    public function archive(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->accounts->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Úvěrový účet nenalezen.', 404);
        }
        $this->accounts->archive($supplierId, $id);
        $this->log($request, 'credit_card.archived', $id, []);
        return Json::ok($response, $this->overview->detail($supplierId, $id));
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->accounts->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Úvěrový účet nenalezen.', 404);
        }
        $this->accounts->restore($supplierId, $id);
        $this->log($request, 'credit_card.restored', $id, []);
        return Json::ok($response, $this->overview->detail($supplierId, $id));
    }

    public function import(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.import', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Soubor chybí.', 400);
        }
        $name = (string) $file->getClientFilename();
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            return Json::error($response, 'invalid_extension', 'Nepovolená přípona souboru. Povolené: pdf', 400);
        }
        $size = $file->getSize() ?? $file->getStream()->getSize();
        if ($size !== null && $size > self::MAX_PDF_BYTES) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 5 MiB).', 413);
        }
        $bytes = (string) $file->getStream()->getContents();
        if (strlen($bytes) > self::MAX_PDF_BYTES) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 5 MiB).', 413);
        }
        if (!str_starts_with($bytes, '%PDF')) {
            return Json::error($response, 'invalid_pdf', 'Soubor není platné PDF.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $expected = isset($body['credit_card_account_id']) && (int) $body['credit_card_account_id'] > 0
            ? (int) $body['credit_card_account_id'] : null;
        $current = $this->currentSupplierId($request);
        // Výpis úvěrového účtu, který eviduje jiná firma uživatele, se naimportuje tam.
        $others = array_values(array_filter(
            $this->crossSupplier->suppliersAllowing($request, ['bank.import']),
            static fn (int $id): bool => $id !== $current,
        ));
        try {
            $result = $this->importer->importPdf($current, $bytes, $name, $this->userId($request), $expected, $others);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $owner = (int) ($result['supplier_id'] ?? $current);
        unset($result['supplier_id']);
        if ($owner !== $current) {
            $firm = $this->crossSupplier->describe([$owner])[0] ?? null;
            $result += ['target_supplier_id' => $owner, 'target_supplier_name' => $firm['name'] ?? null];
            $this->logger->log('credit_card.statement_imported', $this->userId($request) ?: null, 'credit_card_account',
                (int) ($result['credit_card_account_id'] ?? 0), [
                    'statement_id' => $result['statement_id'] ?? null,
                    'transactions' => $result['transactions'] ?? 0,
                    'duplicate'    => $result['duplicate'] ?? false,
                    'uploaded_in_supplier_id' => $current,
                ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $owner);
            return Json::ok($response, $result);
        }
        $this->log($request, 'credit_card.statement_imported', (int) ($result['credit_card_account_id'] ?? 0), [
            'statement_id' => $result['statement_id'] ?? null,
            'transactions' => $result['transactions'] ?? 0,
            'duplicate'    => $result['duplicate'] ?? false,
        ]);
        return Json::ok($response, $result);
    }

    public function convert(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $bankAccountId = (int) ($body['bank_account_id'] ?? 0);
        try {
            $result = $this->conversion->convert(
                $this->currentSupplierId($request),
                $bankAccountId,
                $this->userId($request),
                (string) ($body['issuer'] ?? 'other'),
            );
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'credit_card.converted', $result['credit_card_account_id'], $result + ['bank_account_id' => $bankAccountId]);
        return Json::ok($response, $result);
    }

    public function getSettings(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->settings->settings($this->currentSupplierId($request)));
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        try {
            $result = $this->settings->save($this->currentSupplierId($request), (array) ($request->getParsedBody() ?? []), $this->userId($request));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'credit_card.settings_updated', null, $result['settings']);
        return Json::ok($response, $result);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0: array{label:string, credit_limit:?float, repayment_account:?string, repayment_bank_code:?string, repayment_vs:?string, note:?string}, 1: array<string,string>}
     */
    public static function normalize(array $body): array
    {
        $errors = [];
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'] = 'Název účtu je povinný (nejvýše 120 znaků).';
        }
        $limit = $body['credit_limit'] ?? null;
        if ($limit === '' || $limit === null) {
            $limit = null;
        } elseif (!is_numeric($limit) || (float) $limit < 0) {
            $errors['credit_limit'] = 'Úvěrový limit musí být nezáporné číslo.';
            $limit = null;
        } else {
            $limit = round((float) $limit, 2);
        }
        $account = trim((string) ($body['repayment_account'] ?? ''));
        if ($account !== '' && preg_match('/^(?:\d{1,6}-)?\d{2,10}$/', $account) !== 1) {
            $errors['repayment_account'] = 'Účet pro splátku zadejte ve tvaru předčíslí-číslo (bez kódu banky).';
        }
        $bank = trim((string) ($body['repayment_bank_code'] ?? ''));
        if ($bank !== '' && preg_match('/^\d{4}$/', $bank) !== 1) {
            $errors['repayment_bank_code'] = 'Kód banky má 4 číslice.';
        }
        $vs = trim((string) ($body['repayment_vs'] ?? ''));
        if ($vs !== '' && preg_match('/^\d{1,10}$/', $vs) !== 1) {
            $errors['repayment_vs'] = 'Variabilní symbol smí mít nejvýše 10 číslic.';
        }
        $note = trim((string) ($body['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            $errors['note'] = 'Poznámka smí mít nejvýše 500 znaků.';
        }
        return [[
            'label'               => $label,
            'credit_limit'        => $limit,
            'repayment_account'   => $account !== '' ? $account : null,
            'repayment_bank_code' => $bank !== '' ? $bank : null,
            'repayment_vs'        => $vs !== '' ? $vs : null,
            'note'                => $note !== '' ? $note : null,
        ], $errors];
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'credit_card_account',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
