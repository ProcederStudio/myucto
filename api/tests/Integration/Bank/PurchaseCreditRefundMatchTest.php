<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Regrese: PŘÍCHOZÍ platba = vratka přeplatku od dodavatele se musí spárovat
 * s přijatým dobropisem, i když je dobropis už označený za uhrazený ručně.
 *
 * matchPurchaseCreditRefund() filtroval na status IN ('received','booked'), zatímco
 * sesterská matchPurchase() má v setu i 'paid' — a to s výslovným odůvodněním, že
 * doklad bývá označený za zaplacený dřív, než dorazí výpis, a transakce pak visí
 * ve výpisu nespárovaná. Vratka dobropisu tuhle výjimku neměla, takže reálný případ
 * (dobropis od pojišťovny odklikaný ručně, výpis stažený až potom) skončil jako
 * 'no_purchase_credit_refund' a úhrada se nezaúčtovala — závazek 321 zůstal v deníku
 * otevřený, přestože peníze na účtu byly.
 *
 * Izolace: rok 2099, vlastní statement/transakce/doklad + úklid v tearDown.
 */
#[Group('integration')]
final class PurchaseCreditRefundMatchTest extends TestCase
{
    private Connection $db;
    private BankStatementAction $action;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    private int $creditId = 0;
    private int $statementId = 0;
    private int $transactionId = 0;

    private const FILE_MARKER = '__creditrefund2099__';
    private const TEST_VS = '2099000777';
    private const AMOUNT = 2307.00;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->action = $c->get(BankStatementAction::class);
            $this->matcher = new StatementMatcher(
                $this->db,
                $c->get(FinalFromProformaCreator::class),
                null,
                null,
                null,
                $c->get(ActivityLogger::class),
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('Chybí CZK currency s account_number.');
        }
        $this->currencyId = (int) $cur['id'];
        $this->supplierId = (int) $cur['supplier_id'];
        $this->account = (string) $cur['account_number'];
        $this->bankCode = $cur['bank_code'] !== null ? (string) $cur['bank_code'] : null;

        $this->vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vendorId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/user pro supplier.');
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "DELETE al FROM activity_log al
               JOIN purchase_invoices pi ON pi.id = al.entity_id
              WHERE al.entity_type = 'purchase_invoice' AND pi.vendor_invoice_number = ?"
        )->execute([self::TEST_VS]);
        $pdo->prepare(
            'DELETE FROM payment_matches WHERE supplier_id = ?
              AND purchase_invoice_id IN (SELECT id FROM purchase_invoices WHERE vendor_invoice_number = ?)'
        )->execute([$this->supplierId, self::TEST_VS]);
        $pdo->prepare("DELETE FROM bank_statements WHERE file_name LIKE ?")->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?')
            ->execute([$this->supplierId, self::TEST_VS]);
        $this->creditId = $this->statementId = $this->transactionId = 0;
    }

    /**
     * Dobropis nese ZÁPORNÉ částky (vrácení přeplatku), platba na účtu je KLADNÁ.
     *
     * @param 'received'|'booked'|'paid' $status
     * @param ?string $paidAt Datum ruční úhrady — musí zůstat nepřepsané.
     */
    private function seed(string $status = 'received', ?string $paidAt = null, float $transactionAmount = self::AMOUNT): void
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';

        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, paid_at, created_by)
             VALUES (?, ?, ?, ?, 'credit_note', ?, ?, ?, ?, ?, '{}', ?, ?, ?, ?, ?)"
        )->execute([
            $this->supplierId, $this->vendorId, self::TEST_VS, self::TEST_VS,
            $d, $d, $d, $d, $this->currencyId,
            -self::AMOUNT, -self::AMOUNT, $status, $paidAt, $this->userId,
        ]);
        $this->creditId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . '.gpc',
            hash('sha256', self::FILE_MARKER . self::TEST_VS . $status),
            $this->account, $this->bankCode, $d,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        // PŘÍCHOZÍ platba = kladná částka → matcher jde nejdřív na vratku dobropisu.
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', ?)"
        )->execute([$this->statementId, $d, $transactionAmount, self::TEST_VS]);
        $this->transactionId = (int) $pdo->lastInsertId();
    }

    private function addTransaction(float $amount, string $status = 'unmatched'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, match_status)
             VALUES (?, "2099-06-15", ?, "CZK", ?, ?)'
        )->execute([$this->statementId, $amount, self::TEST_VS, $status]);

        return (int) $pdo->lastInsertId();
    }

    private function manualMatch(int $transactionId): Psr7Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $transactionId . '/match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['purchase_invoice_id' => $this->creditId]);

        return $this->action->manualMatch($request, new Psr7Response(), ['id' => (string) $transactionId]);
    }

    public function testIncomingRefundMatchesOpenPurchaseCreditNote(): void
    {
        $this->seed('received');

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null, 'Vratka přeplatku se musí spárovat s přijatým dobropisem.');
        self::assertSame($this->creditId, $res['purchase_invoice_id'] ?? null);
        self::assertTrue($res['credit_refund'] ?? false);
        self::assertSame('paid', $this->db->pdo()->query(
            "SELECT status FROM purchase_invoices WHERE id = {$this->creditId}"
        )->fetchColumn());
    }

    /**
     * Jádro regrese: dobropis odklikaný ručně jako uhrazený dřív, než dorazil výpis.
     * Před opravou tady matcher vracel unmatched / 'no_purchase_credit_refund'.
     */
    public function testIncomingRefundMatchesCreditNoteAlreadyMarkedPaidManually(): void
    {
        $this->seed('paid', '2099-06-01');

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null,
            'Ručně uhrazený dobropis musí jít spárovat, jinak transakce visí ve výpisu a 321 zůstane otevřený.');
        self::assertSame($this->creditId, $res['purchase_invoice_id'] ?? null);

        $pmCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches
              WHERE bank_transaction_id = {$this->transactionId} AND purchase_invoice_id = {$this->creditId}"
        )->fetchColumn();
        self::assertSame(1, $pmCount, 'Spárování musí založit alokaci, aby se úhrada dala zaúčtovat.');

        self::assertSame('auto_exact', $this->db->pdo()->query(
            "SELECT match_status FROM bank_transactions WHERE id = {$this->transactionId}"
        )->fetchColumn());

        // Ruční datum úhrady je uživatelův údaj — spárování ho nesmí přepsat datem výpisu.
        self::assertSame('2099-06-01', $this->db->pdo()->query(
            "SELECT paid_at FROM purchase_invoices WHERE id = {$this->creditId}"
        )->fetchColumn());
    }

    public function testAutomaticRefundUsesOnlyRemainderAfterManualPartialRefund(): void
    {
        $this->seed(transactionAmount: 700.0);
        $priorTx = $this->transactionId;
        $partial = $this->manualMatch($priorTx);
        self::assertSame(200, $partial->getStatusCode(), (string) $partial->getBody());

        $fullAmountTx = $this->addTransaction(self::AMOUNT);
        $fullAmountResult = $this->matcher->match($fullAmountTx);
        self::assertSame('unmatched', $fullAmountResult['status'] ?? null, json_encode($fullAmountResult));
        self::assertEqualsWithDelta(700.0, (float) $this->db->pdo()->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE purchase_invoice_id = {$this->creditId}"
        )->fetchColumn(), 0.001);

        $remainderTx = $this->addTransaction(1607.0);
        $result = $this->matcher->match($remainderTx);

        self::assertSame('auto_exact', $result['status'] ?? null, json_encode($result));
        $allocations = $this->db->pdo()->query(
            "SELECT bank_transaction_id, amount FROM payment_matches
              WHERE purchase_invoice_id = {$this->creditId} ORDER BY bank_transaction_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(700.0, (float) $allocations[$priorTx]);
        self::assertSame(1607.0, (float) $allocations[$remainderTx]);
        self::assertEqualsWithDelta(self::AMOUNT, array_sum(array_map('floatval', $allocations)), 0.001);

        $duplicateTx = $this->addTransaction(1607.0);
        $duplicate = $this->matcher->match($duplicateTx);
        self::assertSame('unmatched', $duplicate['status'] ?? null, json_encode($duplicate));
        self::assertEqualsWithDelta(self::AMOUNT, (float) $this->db->pdo()->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE purchase_invoice_id = {$this->creditId}"
        )->fetchColumn(), 0.001);
    }

    public function testManualRefundUsesOnlyRemainderAfterEarlierPartialRefund(): void
    {
        $this->seed(transactionAmount: 700.0);
        $partial = $this->manualMatch($this->transactionId);
        self::assertSame(200, $partial->getStatusCode(), (string) $partial->getBody());
        self::assertSame('received', $this->db->pdo()->query(
            "SELECT status FROM purchase_invoices WHERE id = {$this->creditId}"
        )->fetchColumn());

        $fullAmountTx = $this->addTransaction(self::AMOUNT);
        $fullAmount = $this->manualMatch($fullAmountTx);
        self::assertSame(200, $fullAmount->getStatusCode(), (string) $fullAmount->getBody());

        $allocations = $this->db->pdo()->query(
            "SELECT bank_transaction_id, amount FROM payment_matches
              WHERE purchase_invoice_id = {$this->creditId} ORDER BY bank_transaction_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(700.0, (float) $allocations[$this->transactionId]);
        self::assertSame(1607.0, (float) $allocations[$fullAmountTx]);
        self::assertEqualsWithDelta(self::AMOUNT, array_sum(array_map('floatval', $allocations)), 0.001);
        self::assertSame('paid', $this->db->pdo()->query(
            "SELECT status FROM purchase_invoices WHERE id = {$this->creditId}"
        )->fetchColumn());
    }

    public function testManualPurchaseRefundRejectsOutgoingPayment(): void
    {
        $this->seed(transactionAmount: -self::AMOUNT);
        $response = $this->manualMatch($this->transactionId);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id = {$this->creditId}"
        )->fetchColumn());
        self::assertSame('received', $this->db->pdo()->query(
            "SELECT status FROM purchase_invoices WHERE id = {$this->creditId}"
        )->fetchColumn());
    }
}
