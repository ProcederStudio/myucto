<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository, JournalEntryRepository, JournalEntryDocumentLinkRepository, SaldoRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder, PostingService};
use MyInvoice\Service\Accounting\Closing\{ClosingException, ClosingService};
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ClosingProvisionCurrentReadTest extends TestCase
{
    private ContainerInterface $container;
    private ?ContainerInterface $other = null;
    private Connection $db;
    private int $supplier;
    private int $client;
    private int $period;
    private int $invoice;
    private int $originalIsolation;
    private bool $committed = false;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $this->originalIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic provision current','Test 1','Test','10000','current-op@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $this->period = $this->container->get(AccountingPeriodRepository::class)->create($this->supplier, 2096, '2096-01-01', '2096-12-31');
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT id,'Synthetic client','Test','Test','10000',country_id,'client@example.com',default_currency_id FROM supplier WHERE id=?")->execute([$this->supplier]);
        $this->client = (int) $pdo->lastInsertId();
        $this->invoice = $this->invoice();
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE id=?")->execute([$this->period]);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($this->other !== null) $this->other->get(Connection::class)->close();
        if ($this->committed) {
            foreach (['activity_log', 'journal_entries', 'cash_documents', 'cash_registers', 'invoice_payments', 'bank_statements', 'invoices', 'clients'] as $table) {
                $pdo->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->supplier]);
            }
            $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
        }
        $pdo->exec('SET SESSION innodb_snapshot_isolation=' . $this->originalIsolation);
    }

    public function testNewPaymentAfterSnapshotMustLimitProvision(): void
    {
        $this->snapshot();
        $this->payment($this->other->get(Connection::class)->pdo(), 30000);
        $saldo = $this->container->get(SaldoRepository::class);
        $account = $saldo->resolveAccount($this->supplier, '311')['id'];
        $rows = iterator_to_array($saldo->iterateOpenInvoiceItems($this->supplier, $account, '2096-12-31', [$this->invoice]));
        self::assertCount(1, $rows);
        self::assertSame(0.0, $rows[0]['paid_ratio']);
        $this->reject('provision_exceeds_receivable');
    }

    public function testChangedPaymentUnderSnapshotIsolationAbortsOuterTransaction(): void
    {
        $id = $this->payment($this->db->pdo(), 1000);
        $this->snapshot(true);
        $this->other->get(Connection::class)->pdo()->prepare('UPDATE invoice_payments SET amount=30000 WHERE id=?')->execute([$id]);
        try {
            $this->runProvision(25000);
            self::fail('A current conflicting read must abort the stale outer transaction.');
        } catch (\PDOException $e) {
            self::assertSame(1020, (int) $e->errorInfo[1]);
            self::assertFalse($this->db->pdo()->inTransaction());
        }
        self::assertNull($this->container->get(JournalEntryRepository::class)->findBySource($this->supplier, 'provision', $this->invoice));
    }

    public function testCurrentInvoicePostingAmountLimitsProvision(): void
    {
        $this->snapshot();
        $this->other->get(PostingService::class)->postDocument($this->supplier, 'invoice', $this->invoice, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 20000],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 20000],
        ], ['entry_date' => '2096-01-01', 'posted' => true, 'allow_closing_period' => true]);
        $this->reject('provision_exceeds_receivable');
    }

    public function testCurrentInvoiceReversalRemovesReceivable(): void
    {
        $id = $this->container->get(JournalEntryRepository::class)->findBySource($this->supplier, 'invoice', $this->invoice)['id'];
        $this->snapshot();
        $this->other->get(PostingService::class)->reverse($this->supplier, $id,
            ['entry_date' => '2096-06-01', 'allow_closing_period' => true]);
        $this->reject('invoice_not_open_receivable');
    }

    public function testPriorProvisionReversedAfterSnapshotIsNotReleasedTwice(): void
    {
        $first = $this->runProvision(10000);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE id=?")->execute([$this->period]);
        $this->period = $this->container->get(AccountingPeriodRepository::class)->create($this->supplier, 2097, '2097-01-01', '2097-12-31');
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE id=?")->execute([$this->period]);
        $this->snapshot();
        $reversal = $this->other->get(PostingService::class)->reverse($this->supplier, $first['entries'][0]['entry_id'],
            ['entry_date' => '2097-06-01', 'allow_closing_period' => true]);
        $result = $this->runProvision(0);
        self::assertSame([], $result['entries']);
        self::assertCount(2, $this->container->get(JournalEntryRepository::class)->linesForEntry($reversal, $this->supplier, true));
    }

    public function testPartialRunPreservesCurrentOtherInvoiceDecision(): void
    {
        $second = $this->invoice();
        $this->snapshot();
        $written = $this->other->get(ClosingService::class)->runProvisions($this->supplier, $this->period,
            [['invoice_id' => $second, 'legal_amount' => 10000]], $this->version($this->other));
        $result = $this->container->get(ClosingService::class)->runProvisions($this->supplier, $this->period,
            [['invoice_id' => $this->invoice, 'legal_amount' => 5000]], $this->version($this->other), partial: true);
        self::assertCount(2, $result['entries']);
        $rows = array_column($result['entries'], null, 'invoice_id');
        self::assertSame($written['entries'][0]['entry_id'], $rows[$second]['entry_id']);
        self::assertSame(10000.0, (float) $rows[$second]['legal_amount']);
    }

    public function testCurrentBankPostingDateOverridesLaterPaymentDate(): void
    {
        $bank = $this->bankPayment($this->container, $this->invoice, '2097-01-02');
        $this->snapshot();
        $this->bankPosting($this->other, $bank);
        $this->reject('provision_exceeds_receivable');
    }

    public function testCurrentAdvancePaymentAndItsPostingLimitProvision(): void
    {
        $parent = $this->invoice(false);
        $this->db->pdo()->prepare("UPDATE invoices SET invoice_type='proforma' WHERE id=?")->execute([$parent]);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id=?,advance_paid_amount=30000 WHERE id=?')->execute([$parent, $this->invoice]);
        $this->snapshot();
        $bank = $this->bankPayment($this->other, $parent, '2096-06-30');
        $this->bankPosting($this->other, $bank);
        $this->reject('provision_exceeds_receivable');
    }

    public function testCurrentLinkedManualSettlementLimitsProvision(): void
    {
        $this->snapshot();
        $this->manualSettlement($this->other);
        $this->reject('provision_exceeds_receivable');
    }

    public function testCurrentCashAdvancePostingAndNewChildLinkLimitProvision(): void
    {
        $parent = $this->invoice(false);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET invoice_type='proforma' WHERE id=?")->execute([$parent]);
        $pdo->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source)
            VALUES (?,?,'2096-06-30',30000,'CZK','manual')")->execute([$this->supplier, $parent]);
        $payment = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_registers(supplier_id,name,currency_code,account_code,is_default,is_active)
            VALUES (?,'Synthetic cash','CZK','211',1,1)")->execute([$this->supplier]);
        $register = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_documents(supplier_id,register_id,doc_type,purpose,issue_date,description,total_amount,status,invoice_payment_id)
            VALUES (?,?,'in','sale','2096-06-30','Synthetic advance',30000,'draft',?)")->execute([$this->supplier, $register, $payment]);
        $cash = (int) $pdo->lastInsertId();
        $this->snapshot();
        $this->other->get(Connection::class)->pdo()->prepare('UPDATE invoices SET parent_invoice_id=?,advance_paid_amount=30000 WHERE id=?')
            ->execute([$parent, $this->invoice]);
        $this->other->get(PostingService::class)->postDocument($this->supplier, 'cash', $cash, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 30000],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 30000],
        ], ['entry_date' => '2096-06-30', 'posted' => true, 'allow_closing_period' => true]);
        $this->reject('provision_exceeds_receivable');
    }

    public function testCurrentProvisionMappingReusesOtherConnectionEntry(): void
    {
        $this->runProvision(10000);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE id=?")->execute([$this->period]);
        $this->period = $this->container->get(AccountingPeriodRepository::class)->create($this->supplier, 2097, '2097-01-01', '2097-12-31');
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE id=?")->execute([$this->period]);
        $this->snapshot();
        $written = $this->other->get(ClosingService::class)->runProvisions($this->supplier, $this->period,
            [['invoice_id' => $this->invoice, 'legal_amount' => 20000]], $this->version($this->other));
        $result = $this->container->get(ClosingService::class)->runProvisions($this->supplier, $this->period,
            [['invoice_id' => $this->invoice, 'legal_amount' => 25000]], $this->version($this->other));
        self::assertSame($written['entries'][0]['entry_id'], $result['entries'][0]['entry_id']);
        self::assertSame($written['entries'][0]['document_no'], $result['entries'][0]['document_no']);
        $lines = $this->container->get(JournalEntryRepository::class)->linesForEntry($result['entries'][0]['entry_id'], $this->supplier, true);
        self::assertCount(2, $lines);
        self::assertSame(15000.0, (float) $lines[0]['amount']);
    }

    public function testNewSecondDocumentLinkRemovesAmbiguousManualSettlement(): void
    {
        $second = $this->invoice();
        $entry = $this->manualSettlement($this->container);
        $this->snapshot();
        $this->other->get(JournalEntryDocumentLinkRepository::class)->add($entry, $this->supplier, 'invoice', $second, null, null);
        $result = $this->runProvision(25000);
        self::assertCount(1, $result['entries']);
        self::assertSame(25000.0, $result['entries'][0]['legal_amount']);
    }

    public function testCurrentRefundClosesNegativeInvoice(): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET invoice_type='credit_note',total_with_vat=-50000 WHERE id=?")->execute([$this->invoice]);
        $this->container->get(PostingService::class)->postDocument($this->supplier, 'invoice', $this->invoice, [
            ['account_code' => '311', 'side' => 'credit', 'amount' => 50000],
            ['account_code' => '602', 'side' => 'debit', 'amount' => 50000],
        ], ['entry_date' => '2096-01-01', 'posted' => true, 'allow_closing_period' => true]);
        $saldo = $this->container->get(SaldoRepository::class);
        $account = $saldo->resolveAccount($this->supplier, '311')['id'];
        self::assertCount(1, iterator_to_array($saldo->iterateOpenInvoiceItems($this->supplier, $account, '2096-12-31', [$this->invoice])));
        $this->snapshot();
        $this->other->get(Connection::class)->pdo()->prepare("UPDATE invoices SET status='paid',paid_at='2096-06-30' WHERE id=?")->execute([$this->invoice]);
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($this->supplier);
        self::assertSame([], iterator_to_array($saldo->iterateOpenInvoiceItems($this->supplier, $account, '2096-12-31', [$this->invoice], true)));
    }

    private function snapshot(bool $isolation = false): void
    {
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ($isolation ? 'ON' : 'OFF'));
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM invoices WHERE id=?');
        $stmt->execute([$this->invoice]);
        self::assertSame($this->invoice, (int) $stmt->fetchColumn());
        $this->other = Connection::withoutSharedTestConnection(static function (): ContainerInterface {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class)->pdo();
            return $container;
        });
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->other->get(Connection::class)->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
    }

    private function invoice(bool $post = true): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,client_id,issue_date,due_date,currency_id,created_by,total_with_vat,status)
            SELECT id,?,?,'2096-01-01','2096-01-31',default_currency_id,(SELECT id FROM users ORDER BY id LIMIT 1),50000,'issued' FROM supplier WHERE id=?")
            ->execute([(string) random_int(1000000000, 1999999999), $this->client, $this->supplier]);
        $id = (int) $pdo->lastInsertId();
        if ($post) $this->container->get(PostingService::class)->postDocument($this->supplier, 'invoice', $id,
            [['account_code' => '311', 'side' => 'debit', 'amount' => 50000], ['account_code' => '602', 'side' => 'credit', 'amount' => 50000]],
            ['entry_date' => '2096-01-01', 'posted' => true, 'allow_closing_period' => true]);
        return $id;
    }

    private function payment(\PDO $pdo, int $amount): int
    {
        $pdo->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source) VALUES (?,?,'2096-06-30',?,'CZK','manual')")
            ->execute([$this->supplier, $this->invoice, $amount]);
        return (int) $pdo->lastInsertId();
    }

    private function bankPayment(ContainerInterface $container, int $invoice, string $date): int
    {
        $pdo = $container->get(Connection::class)->pdo();
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,source,file_name,file_hash,account_number,bank_code,statement_date)
            VALUES (?,'gpc','synthetic.gpc',?,'1000000005','0100',?)")->execute([$this->supplier, hash('sha256', random_bytes(16)), $date]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,match_status,matched_invoice_id)
            VALUES (?,'statement',?,30000,'CZK','manual',?)")->execute([$statement, $date, $invoice]);
        $bank = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source,bank_transaction_id)
            VALUES (?,?,?,30000,'CZK','bank',?)")->execute([$this->supplier, $invoice, $date, $bank]);
        return $bank;
    }

    private function bankPosting(ContainerInterface $container, int $bank): void
    {
        $container->get(PostingService::class)->postDocument($this->supplier, 'bank', $bank, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 30000],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 30000],
        ], ['entry_date' => '2096-06-30', 'posted' => true, 'allow_closing_period' => true]);
    }

    private function manualSettlement(ContainerInterface $container): int
    {
        $entry = $container->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '546', 'side' => 'debit', 'amount' => 30000],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 30000],
        ], ['entry_date' => '2096-06-30', 'posted' => true, 'allow_closing_period' => true]);
        $container->get(JournalEntryDocumentLinkRepository::class)->add($entry, $this->supplier, 'invoice', $this->invoice, null, null);
        return $entry;
    }

    private function version(?ContainerInterface $container = null): int
    {
        return (int) ($container ?? $this->container)->get(AccountingPeriodRepository::class)->findById($this->supplier, $this->period)['row_version'];
    }

    private function runProvision(int $amount): array
    {
        return $this->container->get(ClosingService::class)->runProvisions($this->supplier, $this->period,
            [['invoice_id' => $this->invoice, 'legal_amount' => $amount, 'acct_amount' => 0]], $this->version());
    }

    private function reject(string $code): void
    {
        try {
            $this->runProvision(25000);
            self::fail('The current receivable cannot support the stale provision amount.');
        } catch (ClosingException $e) { self::assertSame($code, $e->errorCode); }
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertNull($this->container->get(JournalEntryRepository::class)->findBySource($this->supplier, 'provision', $this->invoice));
    }
}
