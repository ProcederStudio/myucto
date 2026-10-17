<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingEntryBuilder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use PHPUnit\Framework\TestCase;

final class OpeningTransactionAtomicityTest extends TestCase
{
    private Connection $db;
    private int $supplier;
    private int $period;
    private ClosingService $service;
    private bool $committed = false;
    private ?int $lockSupplier = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic opening atomicity','Test 1','Test','10000','opening-atomic@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry'
            FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2098, '2098-01-01', '2098-12-31');
        $posting = $container->get(PostingService::class);
        $posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 24000],
            ['account_code' => '411', 'side' => 'credit', 'amount' => 24000],
        ], ['entry_date' => '2098-06-01', 'posted' => true]);
        $posting->postDocument($this->supplier, 'fx_revaluation', ClosingSourceId::fxSaldo($this->period), [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000, 'currency_code' => 'EUR', 'amount_foreign' => 0, 'fx_rate' => 25],
            ['account_code' => '663', 'side' => 'credit', 'amount' => 1000],
        ], ['entry_date' => '2098-12-31', 'posted' => true]);
        $built = (new ClosingEntryBuilder())->closingLines([['account_code' => '663', 'bal' => -1000]], [
            ['account_code' => '311', 'bal' => 25000], ['account_code' => '411', 'bal' => -24000],
        ]);
        $entry = $posting->postDocument($this->supplier, 'closing', $this->period, $built['lines'], ['entry_date' => '2098-12-31', 'posted' => true]);
        $container->get(ClosingRepository::class)->upsertStep($this->supplier, $this->period, 'close_books', 'done', ['entry_id' => $entry, 'profit' => 1000], null, null);
        $pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $pdo->prepare("UPDATE supplier SET city='Caller work before opening' WHERE id=?")->execute([$this->supplier]);
        $this->service = $container->get(ClosingService::class);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if ($this->committed) {
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
            if ($this->lockSupplier !== null) $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->lockSupplier]);
        }
    }

    public function testLateFxFailureRollsBackAllOpeningWorkButKeepsCallerTransaction(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active=0 WHERE supplier_id=? AND account_code='663'")->execute([$this->supplier]);
        $before = $this->snapshot();
        try {
            $this->service->openNext($this->supplier, $this->period, 1);
            self::fail('Inactive FX result account must reject the late reversal.');
        } catch (ClosingException $failure) {
            self::assertSame('account_inactive', $failure->errorCode);
        }
        self::assertTrue($pdo->inTransaction());
        self::assertSame($before, $this->snapshot());
        $city = $pdo->prepare('SELECT city FROM supplier WHERE id=?');
        $city->execute([$this->supplier]);
        self::assertSame('Caller work before opening', $city->fetchColumn());
        $pdo->prepare("UPDATE chart_of_accounts SET is_active=1 WHERE supplier_id=? AND account_code='663'")->execute([$this->supplier]);
        $result = $this->service->openNext($this->supplier, $this->period, 1);
        self::assertGreaterThan(0, $result['entry_id']);
        self::assertGreaterThan(0, $result['fx_reversal_entry_id']);
        self::assertTrue($pdo->inTransaction());
    }

    public function testNativeSnapshotAbortIsNotMaskedByMissingSavepoint(): void
    {
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $previous = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $other = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=ON');
            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM accounting_periods')->fetchColumn();
            $other->pdo()->prepare('UPDATE accounting_periods SET row_version=row_version+1 WHERE supplier_id=? AND id=?')->execute([$this->supplier, $this->period]);
            try {
                $this->service->openNext($this->supplier, $this->period, 2);
                self::fail('Current period lock must abort an obsolete native snapshot.');
            } catch (\PDOException $failure) {
                self::assertSame(1020, (int) $failure->errorInfo[1]);
            }
            self::assertFalse($pdo->inTransaction());
            self::assertSame(1, count($this->snapshot()['accounting_periods']));
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('SET SESSION innodb_snapshot_isolation=' . $previous);
            $other->close();
        }
    }

    public function testNativeLateDeadlockRetainsOriginalErrorAndRollsBackOpening(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id)
            SELECT 'Synthetic opening deadlock','Test 1','Test','10000','opening-deadlock@example.com',country_id,default_currency_id,default_vat_rate_id FROM supplier ORDER BY id LIMIT 1");
        $this->lockSupplier = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES (?,?,'Synthetic lock','asset')");
        $ids = [];
        for ($i = 0; $i <= 2000; ++$i) {
            $insert->execute([$this->lockSupplier, 'SYN' . $i]);
            $ids[] = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        $this->committed = true;
        $before = $this->snapshot();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE chart_of_accounts SET name='Synthetic caller lock' WHERE id=?")->execute([$ids[0]]);
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/Accounting/opening-deadlock-worker.php', (string) $this->lockSupplier, (string) $ids[0]],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 15);
        try {
            self::assertSame("READY\n", fgets($pipes[1]));
            $callback = static function () use ($pdo, $ids, $pipes): void {
                fwrite($pipes[0], "GO\n");
                fflush($pipes[0]);
                $pdo->prepare("UPDATE chart_of_accounts SET name='Synthetic late lock' WHERE id=?")->execute([$ids[1]]);
            };
            $logger = new class($this->db, $callback) extends ActivityLogger {
                public function __construct(Connection $db, private readonly \Closure $callback) { parent::__construct($db); }
                public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null, ?array $payload = null, ?string $ip = null, ?string $userAgent = null, ?int $supplierId = null): void
                {
                    if ($action === 'accounting.books_opened') ($this->callback)();
                    parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
                }
            };
            $container = Bootstrap::buildContainer();
            $container->set(ActivityLogger::class, $logger);
            $service = $container->get(ClosingService::class);
            try {
                $service->openNext($this->supplier, $this->period, 1);
                self::fail('Native deadlock must abort the opening transaction.');
            } catch (\PDOException $failure) {
                self::assertSame(1213, (int) $failure->errorInfo[1]);
            }
            self::assertFalse($pdo->inTransaction());
            self::assertSame("DONE\n", fgets($pipes[1]));
            self::assertSame($before, $this->snapshot());
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fclose($pipes[0]);
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['accounting_periods', 'journal_entries', 'journal_entry_lines', 'accounting_closing_steps', 'accounting_document_series', 'activity_log'] as $table) {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=? ORDER BY id');
            $stmt->execute([$this->supplier]);
            $result[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
