<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository, ClosingRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\{ClosingException, ClosingService};
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\TestCase;

final class ClosingProfitDistributionReversalTest extends TestCase
{
    private Connection $db;
    private ClosingService $closing;
    private PostingService $posting;
    private int $supplier;
    private int $source;
    private int $target;
    private bool $committed = false;

    protected function setUp(): void
    {
        $c=Bootstrap::buildContainer();
        $this->db=$c->get(Connection::class);
        $this->closing=$c->get(ClosingService::class);
        $this->posting=$c->get(PostingService::class);
        $pdo=$this->db->pdo();$pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic result reversal','Test 1','Test','10000','result-reversal@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier=(int)$pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $periods=$c->get(AccountingPeriodRepository::class);
        $this->source=$periods->create($this->supplier,2096,'2096-07-01','2097-06-30');
        $this->target=$periods->create($this->supplier,2097,'2097-07-01','2098-06-30');
        $pdo->prepare("UPDATE accounting_periods SET status='approved' WHERE id=?")->execute([$this->source]);
        $this->posting->postDocument($this->supplier,'opening',$this->target,[
            ['account_code'=>'431','side'=>'credit','amount'=>49.99],
            ['account_code'=>'221','side'=>'debit','amount'=>49.99],
        ],['entry_date'=>'2097-07-01','posted'=>true]);
    }

    protected function tearDown(): void
    {
        if($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if($this->committed) {
            $this->db->pdo()->prepare('DELETE FROM activity_log WHERE supplier_id=?')->execute([$this->supplier]);
            $this->db->pdo()->prepare('DELETE FROM journal_entries WHERE supplier_id=?')->execute([$this->supplier]);
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
        }
    }

    public function testPreviewAfterRealReversalHasOriginalProfitAndNoActiveDecision(): void
    {
        $before=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        self::assertSame(49.99,$before['available_profit']);
        $entry=$this->distribute();
        self::assertSame(0.0,$this->closing->profitDistributionPreview($this->supplier,$this->source)['available_profit']);
        $this->posting->reverse($this->supplier,$entry);
        $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        self::assertSame(49.99,$preview['available_profit']);
        self::assertNull($preview['existing_entry_id']);
        self::assertSame($preview,$this->closing->profitDistributionPreview($this->supplier,$this->target));
    }

    public function testReapplyAfterRealReversalPreservesHistoryAndAllocatesOnlyOriginalProfit(): void
    {
        $first=$this->distribute();
        $reversal=$this->posting->reverse($this->supplier,$first);
        $history=$this->history();
        $second=$this->distribute();
        self::assertNotSame($first,$second);
        self::assertSame($history,array_slice($this->history(),0,count($history)));
        self::assertSame($reversal,Bootstrap::buildContainer()->get(JournalEntryRepository::class)->find($first,$this->supplier)['reversed_by']);
        self::assertSame(0.0,Bootstrap::buildContainer()->get(ClosingRepository::class)->accountBalance($this->supplier,'431','2098-06-30'));
        self::assertSame(-49.99,Bootstrap::buildContainer()->get(ClosingRepository::class)->accountBalance($this->supplier,'428','2098-06-30'));
        self::assertSame($second,$this->closing->profitDistributionPreview($this->supplier,$this->source)['existing_entry_id']);
        $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        $this->closing->revertProfitDistribution($this->supplier,$this->source,$preview['target_period']['row_version']);
        self::assertSame($history,$this->history());
        self::assertNull($this->closing->profitDistributionPreview($this->supplier,$this->source)['existing_entry_id']);
        self::assertSame(49.99,$this->closing->profitDistributionPreview($this->supplier,$this->source)['available_profit']);
    }

    public function testRevertAfterRealReversalCannotDeleteHistoricalOriginal(): void
    {
        $entry=$this->distribute();$this->posting->reverse($this->supplier,$entry);
        $history=$this->history();
        $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        try {
            $this->closing->revertProfitDistribution($this->supplier,$this->source,$preview['target_period']['row_version']);
            self::fail('A reversed historical decision is not an active decision to delete.');
        } catch(ClosingException $e) {self::assertSame('not_found',$e->errorCode);}
        self::assertSame($history,$this->history());
    }

    public function testAnotherReversedDecisionDoesNotChangeAvailableResourcesOrReapply(): void
    {
        $other=$this->posting->postDocument($this->supplier,'profit_distribution',$this->target,[
            ['account_code'=>'431','side'=>'debit','amount'=>10.01],
            ['account_code'=>'428','side'=>'credit','amount'=>10.01],
        ],['entry_date'=>'2097-07-15','posted'=>true]);
        $this->posting->reverse($this->supplier,$other);
        $before=$this->history();
        $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        self::assertSame(49.99,$preview['available_profit']);
        self::assertSame(0.0,$preview['retained_profit']);
        self::assertSame(49.99,$preview['distributable_resources']);
        $this->distribute();
        self::assertSame($before,array_slice($this->history(),0,count($before)));
    }

    public function testClosedTargetRefusesRealReversalAndDistribution(): void
    {
        $entry=$this->distribute();$history=$this->history();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE id=?")->execute([$this->target]);
        try {
            $this->posting->reverse($this->supplier,$entry);
            self::fail('Closed-period reversal must be refused.');
        } catch(\MyInvoice\Service\Accounting\PostingException $e) {self::assertSame('period_not_open',$e->errorCode);}
        try {
            $this->closing->runProfitDistribution($this->supplier,$this->source,['decision_date'=>'2097-08-01','target_row_version'=>2,
                'allocations'=>[['account_code'=>'428','amount'=>49.99,'kind'=>'retained']]]);
            self::fail('A closed target cannot receive a repeated distribution.');
        } catch(ClosingException $e) {self::assertSame('next_period_not_open',$e->errorCode);}
        self::assertSame($history,$this->history());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['2097-08-01',false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2097-09-01',false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2097-08-01',true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2097-09-01',true])]
    public function testUsedDistributionCannotBeReallocatedOrRedated(string $usageDate,bool $dateOnly): void
    {
        $this->distribute();$this->useResult($usageDate);
        $before=$this->history();$preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        try {
            $this->closing->runProfitDistribution($this->supplier,$this->source,[
                'decision_date'=>$dateOnly?'2097-08-02':'2097-08-01','target_row_version'=>$preview['target_period']['row_version'],
                'allocations'=>[['account_code'=>$dateOnly?'428':'421','amount'=>49.99,'kind'=>'retained']],
            ]);
            self::fail('An already used distribution cannot change its financial prescription or date.');
        } catch(ClosingException $e) {self::assertSame('distribution_settled',$e->errorCode);}
        self::assertSame($before,$this->history());
    }

    public function testExactRetryOfUsedDistributionRemainsAllowed(): void
    {
        $entry=$this->distribute();$this->useResult('2097-08-01');
        self::assertSame($entry,$this->distribute());
        self::assertSame(-9.99,Bootstrap::buildContainer()->get(ClosingRepository::class)->accountBalance($this->supplier,'428','2098-06-30'));
    }

    public function testPreparationMatchesActualDefaultAndDistributionRetryPreservesSourcePolicy(): void
    {
        $c=Bootstrap::buildContainer();$dimensions=$c->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $dimensions->setEnabled($this->supplier,true);
        $type=$dimensions->ensureDefaultTypes($this->supplier,['stredisko'])['cost_center'];
        $value=$dimensions->createValue($this->supplier,$type,['code'=>'SYNTHETIC','name'=>'Synthetic result centre'])['id'];
        $c->get(\MyInvoice\Service\Accounting\Dimension\DimensionRuleService::class)->create($this->supplier,[
            'dimension_type_id'=>$type,'enforcement'=>'error','account_mask'=>'428','default_value_id'=>$value,
        ]);
        $c=Bootstrap::buildContainer();$this->posting=$c->get(PostingService::class);$this->closing=$c->get(ClosingService::class);
        $raw=[['account_code'=>'428','side'=>'credit','amount'=>1],['account_code'=>'221','side'=>'debit','amount'=>1]];
        $prepared=$this->posting->prepareDocumentLines($this->supplier,'manual',null,$raw,'2097-07-15');
        self::assertContains([$type=>$value],array_column($prepared,'dimensions'));
        $manual=$this->posting->postDocument($this->supplier,'manual',null,$raw,['entry_date'=>'2097-07-15','posted'=>true]);
        $actual=$c->get(JournalEntryRepository::class)->linesForEntry($manual,$this->supplier);
        $assigned=$c->get(\MyInvoice\Repository\DimensionAssignmentRepository::class)->entryLineDimensions($this->supplier,$manual);
        foreach($actual as &$line)$line['dimensions']=$assigned[$line['id']]??[];unset($line);
        self::assertTrue(\MyInvoice\Service\Accounting\JournalLineComparison::sameLines($prepared,$actual));
        $entry=$this->distribute();
        $assignments=$c->get(\MyInvoice\Repository\DimensionAssignmentRepository::class)->entryLineDimensions($this->supplier,$entry);
        self::assertSame([],$assignments);
        $this->useResult('2097-08-01');
        self::assertSame($entry,$this->distribute());
        $after=$c->get(\MyInvoice\Repository\DimensionAssignmentRepository::class)->entryLineDimensions($this->supplier,$entry);
        self::assertSame(array_values($assignments),array_values($after));
    }

    public function testChangedAnalyticRedirectCannotRewriteUsedDistribution(): void
    {
        $this->distribute();$this->useResult('2097-08-01');$before=$this->history();
        $accounts=Bootstrap::buildContainer()->get(\MyInvoice\Repository\ChartOfAccountsRepository::class);
        $parent=$accounts->findByCode($this->supplier,'428');
        $accounts->insert($this->supplier,['account_code'=>'428.100','name'=>'Synthetic retained analytic','account_type'=>$parent['account_type'],
            'normal_side'=>$parent['normal_side'],'is_synthetic'=>false,'parent_id'=>$parent['id'],'is_active'=>true]);
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings(supplier_id,single_analytic_redirect) VALUES (?,1)
            ON DUPLICATE KEY UPDATE single_analytic_redirect=1')->execute([$this->supplier]);
        $c=Bootstrap::buildContainer();$this->posting=$c->get(PostingService::class);$this->closing=$c->get(ClosingService::class);
        self::assertSame('428.100',$this->posting->redirectedAccountCode($this->supplier,'428'));
        try {
            $this->distribute();
            self::fail('An unchanged raw body can still change its resolved account.');
        } catch(ClosingException $e) {self::assertSame('distribution_settled',$e->errorCode);}
        self::assertSame($before,$this->history());
    }

    public function testSameTransactionPreparationCannotDriftAfterConcurrentRedirectChange(): void
    {
        $accounts=Bootstrap::buildContainer()->get(\MyInvoice\Repository\ChartOfAccountsRepository::class);
        $parent=$accounts->findByCode($this->supplier,'428');
        $accounts->insert($this->supplier,['account_code'=>'428.100','name'=>'Synthetic retained analytic','account_type'=>$parent['account_type'],
            'normal_side'=>$parent['normal_side'],'is_synthetic'=>false,'parent_id'=>$parent['id'],'is_active'=>true]);
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings(supplier_id,single_analytic_redirect) VALUES (?,1)
            ON DUPLICATE KEY UPDATE single_analytic_redirect=1')->execute([$this->supplier]);
        $c=Bootstrap::buildContainer();$this->posting=$c->get(PostingService::class);$this->closing=$c->get(ClosingService::class);
        $entry=$this->distribute();$this->useResult('2097-08-01');
        $pdo=$this->db->pdo();$pdo->commit();$this->committed=true;
        $previous=(int)$pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $raw=[['account_code'=>'428','side'=>'credit','amount'=>49.99],['account_code'=>'431','side'=>'debit','amount'=>49.99]];
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');$pdo->beginTransaction();
            (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($this->supplier);
            $c=Bootstrap::buildContainer();$posting=$c->get(PostingService::class);$closing=$c->get(ClosingService::class);
            $prepared=$posting->prepareDocumentLines($this->supplier,'profit_distribution',$this->source,$raw,'2097-08-01');
            $closing->assertProfitDistributionUnsettled($this->supplier,$this->source,'2097-08-01',$prepared);
            Connection::withoutSharedTestConnection(function():void {
                $db=Bootstrap::buildContainer()->get(Connection::class);
                try {
                    self::assertNotSame($this->db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),$db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
                    $db->pdo()->prepare('UPDATE accounting_supplier_settings SET single_analytic_redirect=0 WHERE supplier_id=?')->execute([$this->supplier]);
                } finally {$db->close();}
            });
            self::assertSame($entry,$posting->postDocument($this->supplier,'profit_distribution',$this->source,$raw,['entry_date'=>'2097-08-01','posted'=>true]));
            $actual=$c->get(JournalEntryRepository::class)->linesForEntry($entry,$this->supplier,true);
            self::assertTrue(\MyInvoice\Service\Accounting\JournalLineComparison::sameLines($prepared,$actual));
            $pdo->commit();$pdo->beginTransaction();
            $c=Bootstrap::buildContainer();$posting=$c->get(PostingService::class);$closing=$c->get(ClosingService::class);
            $prepared=$posting->prepareDocumentLines($this->supplier,'profit_distribution',$this->source,$raw,'2097-08-01');
            try {
                $closing->assertProfitDistributionUnsettled($this->supplier,$this->source,'2097-08-01',$prepared);
                self::fail('A new transaction must see the changed redirect and refuse the changed financial lines.');
            } catch(ClosingException $e) {self::assertSame('distribution_settled',$e->errorCode);}
        } finally {
            if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('SET SESSION innodb_snapshot_isolation='.$previous);
        }
    }

    public function testSameDateUsageAlsoPreventsHardRevert(): void
    {
        $this->distribute();$this->useResult('2097-08-01');$before=$this->history();
        $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        try {
            $this->closing->revertProfitDistribution($this->supplier,$this->source,$preview['target_period']['row_version']);
            self::fail('Same-date later journal usage must prevent hard delete.');
        } catch(ClosingException $e) {self::assertSame('distribution_settled',$e->errorCode);}
        self::assertSame($before,$this->history());
    }

    private function useResult(string $date): void
    {
        $this->posting->postDocument($this->supplier,'manual',null,[
            ['account_code'=>'428','side'=>'debit','amount'=>40.00],
            ['account_code'=>'221','side'=>'credit','amount'=>40.00],
        ],['entry_date'=>$date,'posted'=>true]);
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testDistributionUsesCurrentProfitAfterEarlierSnapshot(bool $isolation): void
    {
        $pdo=$this->db->pdo();$pdo->commit();$this->committed=true;
        $previous=(int)$pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation='.($isolation?'ON':'OFF'));$pdo->beginTransaction();
            $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
            Connection::withoutSharedTestConnection(function():void {
                $c=Bootstrap::buildContainer();$db=$c->get(Connection::class);
                try {
                    self::assertNotSame($this->db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),$db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
                    $c->get(PostingService::class)->postDocument($this->supplier,'opening',$this->target,[
                        ['account_code'=>'431','side'=>'credit','amount'=>50.99],
                        ['account_code'=>'221','side'=>'debit','amount'=>50.99],
                    ],['entry_date'=>'2097-07-01','posted'=>true]);
                } finally {$db->close();}
            });
            try {
                $this->closing->runProfitDistribution($this->supplier,$this->source,['decision_date'=>'2097-08-01','target_row_version'=>$preview['target_period']['row_version'],
                    'allocations'=>[['account_code'=>'428','amount'=>49.99,'kind'=>'retained']]]);
                self::fail('Old snapshot cannot approve an outdated profit amount.');
            } catch(ClosingException $e) {
                self::assertFalse($isolation);self::assertSame('distribution_mismatch',$e->errorCode);self::assertTrue($pdo->inTransaction());
            } catch(\PDOException $e) {
                self::assertTrue($isolation);self::assertSame(1020,(int)$e->errorInfo[1]);self::assertFalse($pdo->inTransaction());
            }
            if($pdo->inTransaction())$pdo->rollBack();
            self::assertNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findBySource($this->supplier,'profit_distribution',$this->source));
        } finally {
            if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('SET SESSION innodb_snapshot_isolation='.$previous);
        }
    }

    private function distribute(): int
    {
        $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        $result=$this->closing->runProfitDistribution($this->supplier,$this->source,[
            'decision_date'=>'2097-08-01','target_row_version'=>$preview['target_period']['row_version'],
            'allocations'=>[['account_code'=>'428','amount'=>49.99,'kind'=>'retained']],
        ]);
        self::assertSame(49.99,$result['distributed']);
        return $result['entry_id'];
    }

    private function history(): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT e.id,e.reversed_by,e.document_no,l.account_id,l.side,l.amount FROM journal_entries e
            JOIN journal_entry_lines l ON l.entry_id=e.id WHERE e.supplier_id=? ORDER BY e.id,l.id');
        $stmt->execute([$this->supplier]);return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
