<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ClosingProfitDistributionConcurrencyTest extends TestCase
{
    private Connection $db;
    private ClosingService $closing;
    private int $supplier;
    private int $source;
    private int $target;
    private int $entry;
    private int $snapshotIsolation;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->closing = $container->get(ClosingService::class);
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic result concurrency','Test 1','Test','10000','result-concurrency@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry'
            FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        self::assertGreaterThan(0, $this->supplier);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $periods = $container->get(AccountingPeriodRepository::class);
        $this->source = $periods->create($this->supplier, 2096, '2096-07-01', '2097-06-30');
        $this->target = $periods->create($this->supplier, 2097, '2097-07-01', '2098-06-30');
        $pdo->prepare("UPDATE accounting_periods SET status='approved' WHERE id=?")->execute([$this->source]);
        $container->get(PostingService::class)->postDocument($this->supplier, 'opening', null, [
            ['account_code'=>'431','side'=>'credit','amount'=>49.99],
            ['account_code'=>'221','side'=>'debit','amount'=>49.99],
        ], ['entry_date'=>'2097-07-01','posted'=>true]);
        $preview = $this->closing->profitDistributionPreview($this->supplier, $this->source);
        $result = $this->closing->runProfitDistribution($this->supplier, $this->source, [
            'decision_date'=>'2097-08-01', 'target_row_version'=>$preview['target_period']['row_version'],
            'allocations'=>[['account_code'=>'428','amount'=>49.99,'kind'=>'retained']],
        ]);
        $this->entry = $result['entry_id'];
        $pdo->commit();
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('SET SESSION innodb_snapshot_isolation='.$this->snapshotIsolation);
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id=?')->execute([$this->supplier]);
        $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id=?')->execute([$this->supplier]);
        $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
    }

    public function testCurrentFollowUpBlocksRevertWithSnapshotIsolationOff(): void { $this->concurrentFollowUp(false); }
    public function testCurrentFollowUpConflictAbortsOuterTransactionWithSnapshotIsolationOn(): void { $this->concurrentFollowUp(true); }

    private function concurrentFollowUp(bool $snapshotIsolation): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET SESSION innodb_snapshot_isolation='.($snapshotIsolation?'ON':'OFF'));
        $pdo->beginTransaction();
        $preview = $this->closing->profitDistributionPreview($this->supplier, $this->source);
        $this->other(function (PostingService $posting): void {
            $posting->postDocument($this->supplier, 'manual', null, [
                ['account_code'=>'428','side'=>'debit','amount'=>1],
                ['account_code'=>'221','side'=>'credit','amount'=>1],
            ], ['entry_date'=>'2097-09-01','posted'=>true]);
        });
        try {
            $this->closing->revertProfitDistribution($this->supplier, $this->source, $preview['target_period']['row_version']);
            self::fail('Concurrent use of the distributed result must prevent its deletion.');
        } catch (ClosingException $e) {
            self::assertFalse($snapshotIsolation);
            self::assertSame('distribution_settled', $e->errorCode);
            self::assertTrue($pdo->inTransaction());
        } catch (\PDOException $e) {
            self::assertTrue($snapshotIsolation);
            self::assertSame(1020, (int) $e->errorInfo[1]);
            self::assertFalse($pdo->inTransaction());
        }
        if ($pdo->inTransaction()) $pdo->rollBack();
        self::assertTrue($this->entryExists());
    }

    public function testFollowUpUsesCurrentDistributionAccountAfterRewrite(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $pdo->beginTransaction();
        $preview = $this->closing->profitDistributionPreview($this->supplier, $this->source);
        $this->other(function (PostingService $posting): void {
            $posting->postDocument($this->supplier, 'profit_distribution', $this->source, [
                ['account_code'=>'431','side'=>'debit','amount'=>49.99],
                ['account_code'=>'364','side'=>'credit','amount'=>49.99],
            ], ['entry_date'=>'2097-08-01','posted'=>true]);
            $posting->postDocument($this->supplier, 'manual', null, [
                ['account_code'=>'364','side'=>'debit','amount'=>1],
                ['account_code'=>'221','side'=>'credit','amount'=>1],
            ], ['entry_date'=>'2097-09-01','posted'=>true]);
        });
        try {
            $this->closing->revertProfitDistribution($this->supplier, $this->source, $preview['target_period']['row_version']);
            self::fail('Follow-up guard must use the current distribution accounts.');
        } catch (ClosingException $e) {
            self::assertSame('distribution_settled', $e->errorCode);
        }
        self::assertTrue($this->entryExists());
    }

    public function testAuditDumpUsesCurrentRewrittenDateAndAmounts(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $pdo->beginTransaction();
        $preview = $this->closing->profitDistributionPreview($this->supplier, $this->source);
        $this->other(function (PostingService $posting): void {
            $posting->postDocument($this->supplier, 'profit_distribution', $this->source, [
                ['account_code'=>'431','side'=>'debit','amount'=>75.01],
                ['account_code'=>'428','side'=>'credit','amount'=>75.01],
            ], ['entry_date'=>'2097-08-15','posted'=>true]);
        });
        $result = $this->closing->revertProfitDistribution($this->supplier, $this->source, $preview['target_period']['row_version']);
        self::assertSame('2097-08-15', $result['dump']['entry']['entry_date']);
        self::assertSame(['75.01','75.01'], array_column($result['dump']['lines'], 'amount'));
        self::assertFalse($this->entryExists());
    }

    public function testCurrentUnapprovedSourceCannotBeRevertedFromOldSnapshot(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $pdo->beginTransaction();
        $preview = $this->closing->profitDistributionPreview($this->supplier, $this->source);
        $this->other(function (PostingService $posting, PDO $other): void {
            $other->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->source]);
        });
        try {
            $this->closing->revertProfitDistribution($this->supplier, $this->source, $preview['target_period']['row_version']);
            self::fail('Current source approval must be required.');
        } catch (ClosingException $e) {
            self::assertSame('invalid_status_transition', $e->errorCode);
        }
        self::assertTrue($this->entryExists());
    }

    public function testOrdinaryRevertKeepsDumpAndTargetVersionContract(): void
    {
        $preview = $this->closing->profitDistributionPreview($this->supplier, $this->source);
        $result = $this->closing->revertProfitDistribution($this->supplier, $this->source, $preview['target_period']['row_version']);
        self::assertSame($this->target, $result['target_period_id']);
        self::assertSame(['49.99','49.99'], array_column($result['dump']['lines'], 'amount'));
        self::assertSame($this->entry, (int) $result['dump']['entry']['id']);
        self::assertFalse($this->entryExists());
        self::assertSame($preview['target_period']['row_version'] + 1, $this->closing->profitDistributionPreview($this->supplier, $this->source)['target_period']['row_version']);
    }

    private function other(callable $work): void
    {
        Connection::withoutSharedTestConnection(function () use ($work): void {
            $container = Bootstrap::buildContainer();
            $db = $container->get(Connection::class);
            try {
                self::assertNotSame($this->db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
                $work($container->get(PostingService::class), $db->pdo());
            } finally {
                $db->close();
            }
        });
    }

    private function entryExists(): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE');
        $stmt->execute([$this->supplier,$this->entry]);
        return $stmt->fetchColumn() !== false;
    }
}
