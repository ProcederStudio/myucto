<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use PHPUnit\Framework\TestCase;

final class FxTransactionAtomicityTest extends TestCase
{
    private Connection $db;
    private int $supplier;
    private int $period;
    private int $user;
    private ClosingService $service;
    private bool $committed = false;
    private ?int $lockSupplier = null;

    protected function setUp(): void
    {
        $c=Bootstrap::buildContainer();
        $this->db=$c->get(Connection::class);
        $pdo=$this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic FX channels','Test1','Test','10000','fx-channels@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier=(int)$pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $this->period=$c->get(AccountingPeriodRepository::class)->create($this->supplier,2098,'2098-01-01','2098-12-31');
        $this->user=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2098-06-15','EUR',24),('2098-09-15','EUR',24),('2098-12-31','EUR',25) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
        $this->invoice($this->supplier, 24, '2098-06-15', '2098000951');
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $pdo->prepare("UPDATE supplier SET city='Caller before FX' WHERE id=?")->execute([$this->supplier]);
        $this->service=$c->get(ClosingService::class);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if ($this->committed) {
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id=?')->execute([$this->supplier]);
            foreach (['invoice_payments', 'invoices', 'clients', 'currencies'] as $table) $pdo->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
            if ($this->lockSupplier !== null) $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->lockSupplier]);
        }
    }

    public function testLateInactiveBankAccountRollsBackSaldoAndKeepsCallerWork(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active=0 WHERE supplier_id=? AND account_code='221'")->execute([$this->supplier]);
        $before = $this->snapshot();
        $bank = [['account_code' => '221', 'currency_code' => 'EUR', 'foreign_balance' => 10]];
        try {
            $this->service->runFxRevaluation($this->supplier, $this->period, $bank, 1);
            self::fail('The second FX slot must reject the inactive account.');
        } catch (ClosingException $failure) {
            self::assertSame('account_inactive', $failure->errorCode);
        }
        self::assertTrue($pdo->inTransaction());
        self::assertSame($before, $this->snapshot());
        self::assertSame('Caller before FX', $pdo->query('SELECT city FROM supplier WHERE id=' . $this->supplier)->fetchColumn());
        $pdo->prepare("UPDATE chart_of_accounts SET is_active=1 WHERE supplier_id=? AND account_code='221'")->execute([$this->supplier]);
        $result = $this->service->runFxRevaluation($this->supplier, $this->period, $bank, 1);
        self::assertCount(2, $result['entry_ids']);
        self::assertSame(['loss' => 0.0, 'gain' => 1250.0], $result['totals']);
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
                $this->service->runFxRevaluation($this->supplier, $this->period, [], 2);
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

    public function testNativeLateDeadlockRetainsOriginalErrorAndRollsBackFx(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id)
            SELECT 'Synthetic FX deadlock','Test 1','Test','10000','fx-deadlock@example.com',country_id,default_currency_id,default_vat_rate_id FROM supplier ORDER BY id LIMIT 1");
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
                    if ($action === 'accounting.fx_revalued') ($this->callback)();
                    parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
                }
            };
            $container = Bootstrap::buildContainer();
            $container->set(ActivityLogger::class, $logger);
            $service = $container->get(ClosingService::class);
            try {
                $service->runFxRevaluation($this->supplier, $this->period, [], 1);
                self::fail('Native deadlock must abort the FX transaction.');
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
    private function invoice(int $sid, float $rate, string $date, string $symbol): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id=? AND code='EUR'");
        $stmt->execute([$sid]);
        $eur = (int) $stmt->fetchColumn();
        if (!$eur) {
            $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES (?,'EUR','EUR','EUR','Euro','Euro',2,1,0)")->execute([$sid]);
            $eur = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT id,'Synthetic FX client','Test','Test','10000',country_id,'fx-client@example.com',? FROM supplier WHERE id=?")->execute([$eur, $sid]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,exchange_rate,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,created_by)
            VALUES (?,?,'invoice',?,?,?,'2098-06-30',?,?,1000,0,1000,'issued','1',(SELECT id FROM users ORDER BY id LIMIT 1))")->execute([$sid, $symbol, $client, $date, $date, $eur, $rate]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index)
            VALUES (?,'Synthetic service',1,'ks',1000,(SELECT id FROM vat_rates ORDER BY id LIMIT 1),0,1000,0,1000,0)")->execute([$id]);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($sid, 'invoice', $id, $posting->buildFromInvoice($sid, $id), ['entry_date' => $date, 'posted' => true]);
        return $id;
    }
}
