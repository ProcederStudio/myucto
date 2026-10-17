<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Faktura k vyplacení (vyúčtování vratných obalů, amount_to_pay −608) se s odchozí
 * platbou pod svým VS páruje stejně jako dobropis: automaticky i ručně vznikne alokace
 * v payment_matches a doklad je `paid`. Dřív automatické párování hledalo jen
 * `invoice_type = 'credit_note'` a ruční vracelo 409 (canBeMarkedPaid).
 */
#[Group('integration')]
final class RefundInvoiceBankMatchTest extends TestCase
{
    private const FILE_MARKER = '__refund_invoice_bank_match_test__';
    private const CLIENT_MARKER = '__refund_invoice_bank_match_client__';
    private const TEST_VS = ['209990001', '209990002', '209990003', '209990004'];

    private Connection $db;
    private BankStatementAction $action;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $countryId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(BankStatementAction::class);
            $this->matcher = $container->get(StatementMatcher::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $account = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            $this->markTestSkipped('Chybí CZK měna s bankovním účtem.');
        }
        $this->supplierId = (int) $account['supplier_id'];
        $this->currencyId = (int) $account['id'];
        $this->account = (string) $account['account_number'];
        $this->bankCode = $account['bank_code'] !== null ? (string) $account['bank_code'] : null;
        $this->countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->countryId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí country/user pro integrační test.');
        }

        $this->cleanup();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::CLIENT_MARKER, $this->countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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
        $placeholders = implode(',', array_fill(0, count(self::TEST_VS), '?'));
        $invoiceParams = [$this->supplierId, ...self::TEST_VS];

        $pdo->prepare(
            "DELETE FROM activity_log
              WHERE entity_type = 'invoice'
                AND entity_id IN (SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol IN ($placeholders))"
        )->execute($invoiceParams);
        $pdo->prepare(
            "DELETE FROM activity_log
              WHERE entity_type = 'bank_transaction'
                AND entity_id IN (
                    SELECT bt.id FROM bank_transactions bt
                    JOIN bank_statements bs ON bs.id = bt.statement_id
                    WHERE bs.file_name LIKE ?
                )"
        )->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare(
            "DELETE pm FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name LIKE ?"
        )->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')
            ->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($placeholders)")
            ->execute($invoiceParams);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND company_name = ?')
            ->execute([$this->supplierId, self::CLIENT_MARKER]);
        $this->clientId = 0;
    }

    /** @return array{invoice_id:int,statement_id:int,transaction_id:int} */
    private function seed(string $varsymbol, float $txAmount): array
    {
        $pdo = $this->db->pdo();
        $date = '2099-07-15';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, exchange_rate_date, status,
                 total_without_vat, total_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 1, ?, 'issued', -734, 126, -608, 0, ?)"
        )->execute([
            $varsymbol, $this->clientId, $this->supplierId, $date, $date, $date,
            $this->currencyId, $date, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, "CZK", ?)'
        )->execute([
            self::FILE_MARKER . $varsymbol . '.gpc',
            hash('sha256', self::FILE_MARKER . $varsymbol),
            $this->account,
            $this->bankCode,
            $date,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_bank)
             VALUES (?, ?, ?, "CZK", ?, "1000000005", "0100")'
        )->execute([$statementId, $date, $txAmount, $varsymbol]);

        return [
            'invoice_id' => $invoiceId,
            'statement_id' => $statementId,
            'transaction_id' => (int) $pdo->lastInsertId(),
        ];
    }

    private function addTransaction(int $statementId, string $varsymbol, float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_bank)
             VALUES (?, "2099-07-15", ?, "CZK", ?, "1000000005", "0100")'
        )->execute([$statementId, $amount, $varsymbol]);

        return (int) $pdo->lastInsertId();
    }

    private function manualMatch(int $transactionId, int $invoiceId): Psr7Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $transactionId . '/match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['invoice_id' => $invoiceId]);

        return $this->action->manualMatch($request, new Psr7Response(), ['id' => (string) $transactionId]);
    }

    private function assertRefunded(array $seed, string $matchType): void
    {
        $pdo = $this->db->pdo();
        $invoice = $pdo->query("SELECT status, paid_at FROM invoices WHERE id = {$seed['invoice_id']}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('paid', $invoice['status'] ?? null);
        self::assertSame('2099-07-15', substr((string) ($invoice['paid_at'] ?? ''), 0, 10));
        $matches = $pdo->query(
            "SELECT invoice_id, amount, match_type FROM payment_matches WHERE bank_transaction_id = {$seed['transaction_id']}"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $matches);
        self::assertSame($seed['invoice_id'], (int) $matches[0]['invoice_id']);
        self::assertSame(608.0, (float) $matches[0]['amount']);
        self::assertSame($matchType, $matches[0]['match_type']);
        self::assertSame(
            $seed['invoice_id'],
            (int) $pdo->query("SELECT matched_invoice_id FROM bank_transactions WHERE id = {$seed['transaction_id']}")->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$seed['invoice_id']}")->fetchColumn(),
        );
    }

    public function testOutgoingPaymentAutoMatchesRefundInvoice(): void
    {
        $seed = $this->seed(self::TEST_VS[0], -608.0);

        $result = $this->matcher->match($seed['transaction_id']);

        self::assertSame('auto_exact', $result['status'] ?? null, json_encode($result));
        $this->assertRefunded($seed, 'auto');
    }

    public function testOutgoingPaymentManuallyMatchesRefundInvoice(): void
    {
        $seed = $this->seed(self::TEST_VS[1], -608.0);

        $response = $this->manualMatch($seed['transaction_id'], $seed['invoice_id']);
        $response->getBody()->rewind();

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertRefunded($seed, 'manual');
    }

    public function testIncomingPaymentDoesNotAutoMatchRefundInvoice(): void
    {
        $seed = $this->seed(self::TEST_VS[2], 608.0);

        $result = $this->matcher->match($seed['transaction_id']);

        self::assertNotSame('auto_exact', $result['status'] ?? null, json_encode($result));
        self::assertSame(
            'issued',
            $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$seed['invoice_id']}")->fetchColumn(),
        );
    }

    public function testAutomaticRefundUsesOnlyRemainderAfterManualPartialRefund(): void
    {
        $seed = $this->seed(self::TEST_VS[3], -200.0);
        $manual = $this->manualMatch($seed['transaction_id'], $seed['invoice_id']);
        self::assertSame(200, $manual->getStatusCode(), (string) $manual->getBody());
        self::assertSame('issued', $this->db->pdo()->query(
            "SELECT status FROM invoices WHERE id = {$seed['invoice_id']}"
        )->fetchColumn());

        $fullAmountTx = $this->addTransaction($seed['statement_id'], self::TEST_VS[3], -608.0);
        $fullAmountResult = $this->matcher->match($fullAmountTx);
        self::assertSame('unmatched', $fullAmountResult['status'] ?? null, json_encode($fullAmountResult));
        self::assertEqualsWithDelta(200.0, (float) $this->db->pdo()->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE invoice_id = {$seed['invoice_id']}"
        )->fetchColumn(), 0.001);

        $remainderTx = $this->addTransaction($seed['statement_id'], self::TEST_VS[3], -408.0);
        $result = $this->matcher->match($remainderTx);

        self::assertSame('auto_exact', $result['status'] ?? null, json_encode($result));
        $allocations = $this->db->pdo()->query(
            "SELECT bank_transaction_id, amount FROM payment_matches
              WHERE invoice_id = {$seed['invoice_id']} ORDER BY bank_transaction_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(200.0, (float) $allocations[$seed['transaction_id']]);
        self::assertSame(408.0, (float) $allocations[$remainderTx]);
        self::assertEqualsWithDelta(608.0, array_sum(array_map('floatval', $allocations)), 0.001);
        self::assertSame('paid', $this->db->pdo()->query(
            "SELECT status FROM invoices WHERE id = {$seed['invoice_id']}"
        )->fetchColumn());

        $duplicateTx = $this->addTransaction($seed['statement_id'], self::TEST_VS[3], -408.0);
        $duplicate = $this->matcher->match($duplicateTx);
        self::assertSame('unmatched', $duplicate['status'] ?? null, json_encode($duplicate));
        self::assertEqualsWithDelta(608.0, (float) $this->db->pdo()->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE invoice_id = {$seed['invoice_id']}"
        )->fetchColumn(), 0.001);
    }
}
