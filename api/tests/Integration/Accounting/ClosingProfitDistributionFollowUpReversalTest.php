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

final class ClosingProfitDistributionFollowUpReversalTest extends TestCase
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

    #[\PHPUnit\Framework\Attributes\TestWith(['2097-08-01',false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2097-09-01',false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2097-08-01',true])]
    public function testActualReversedUsageAllowsDecisionRevert(string $date,bool $red): void
    {
        $this->distribute();$usage=$this->usage($date,$red);
        $reversal=$this->posting->reverse($this->supplier,$usage);
        $this->revert();
        self::assertNotNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->find($usage,$this->supplier));
        self::assertNotNull(Bootstrap::buildContainer()->get(JournalEntryRepository::class)->find($reversal,$this->supplier));
        self::assertSame(49.99,$this->closing->profitDistributionPreview($this->supplier,$this->source)['available_profit']);
        self::assertSame(0.0,Bootstrap::buildContainer()->get(ClosingRepository::class)->accountBalance($this->supplier,'428','2098-06-30'));
    }

    public function testActualReversedUsageAlsoAllowsChangedDistribution(): void
    {
        $id=$this->distribute();$usage=$this->usage();$this->posting->reverse($this->supplier,$usage);
        $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        $result=$this->closing->runProfitDistribution($this->supplier,$this->source,[
            'decision_date'=>'2097-08-01','target_row_version'=>$preview['target_period']['row_version'],
            'allocations'=>[['account_code'=>'421','amount'=>49.99,'kind'=>'retained']],
        ]);
        self::assertSame($id,$result['entry_id']);
        self::assertSame(0.0,Bootstrap::buildContainer()->get(ClosingRepository::class)->accountBalance($this->supplier,'428','2098-06-30'));
    }

    public function testUnrelatedEqualCreditDoesNotCancelUsage(): void
    {
        $this->distribute();$this->usage();
        $this->posting->postDocument($this->supplier,'manual',null,[
            ['account_code'=>'428','side'=>'credit','amount'=>40],['account_code'=>'221','side'=>'debit','amount'=>40],
        ],['entry_date'=>'2097-08-01','posted'=>true]);
        $this->assertBlocked();
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['counter_amount'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['counter_account'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['currency'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['red_storno'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['source_id'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['unposted'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['source_type'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['outside_period'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['before_decision'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['duplicate'])]
    public function testDamagedPairCannotClearFollowUp(string $damage): void
    {
        $this->distribute();$usage=$this->usage();$reversal=$this->posting->reverse($this->supplier,$usage);
        $pdo=$this->db->pdo();
        $stmt=$pdo->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id=? AND a.account_code='221'");
        $stmt->execute([$reversal]);$line=(int)$stmt->fetchColumn();
        match($damage) {
            'counter_amount'=>$pdo->prepare('UPDATE journal_entry_lines SET amount=39.99 WHERE id=?')->execute([$line]),
            'counter_account'=>$pdo->prepare("UPDATE journal_entry_lines SET account_id=(SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='211') WHERE id=?")->execute([$this->supplier,$line]),
            'currency'=>$pdo->prepare("UPDATE journal_entry_lines SET currency_code='EUR',fx_rate=25,amount_foreign=1.6 WHERE id=?")->execute([$line]),
            'red_storno'=>$pdo->prepare('UPDATE journal_entry_lines SET is_red_storno=1 WHERE id=?')->execute([$line]),
            'source_id'=>$pdo->prepare('UPDATE journal_entries SET source_id=987654 WHERE id=?')->execute([$reversal]),
            'unposted'=>$pdo->prepare('UPDATE journal_entries SET posted_at=NULL WHERE id=?')->execute([$reversal]),
            'source_type'=>$pdo->prepare("UPDATE journal_entries SET source_type='bank' WHERE id=?")->execute([$reversal]),
            'outside_period'=>$pdo->prepare("UPDATE journal_entries SET entry_date='2098-07-01' WHERE id=?")->execute([$reversal]),
            'before_decision'=>$pdo->prepare("UPDATE journal_entries SET entry_date='2097-07-31' WHERE id=?")->execute([$reversal]),
            'duplicate'=>$pdo->prepare('INSERT INTO journal_entry_lines(entry_id,supplier_id,account_id,side,amount,line_no) SELECT entry_id,supplier_id,account_id,side,amount,99 FROM journal_entry_lines WHERE id=?')->execute([$line]),
        };
        $this->assertBlocked();
    }

    public function testReversalInAnotherPeriodCannotClearFollowUp(): void
    {
        $this->distribute();$usage=$this->usage();
        Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->create($this->supplier,2098,'2098-07-01','2099-06-30');
        $this->posting->reverse($this->supplier,$usage,['entry_date'=>'2098-07-01']);
        $this->assertBlocked();
    }

    public function testReversedReversalDoesNotEraseLiveUsage(): void
    {
        $this->distribute();$usage=$this->usage();$reversal=$this->posting->reverse($this->supplier,$usage);
        $this->posting->reverse($this->supplier,$reversal);
        $this->assertBlocked();
    }

    public function testExtraUsageCannotHideBehindValidReversalPair(): void
    {
        $this->distribute();$usage=$this->usage();$this->posting->reverse($this->supplier,$usage);$this->usage();
        $this->assertBlocked();
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testPairRequiresExactCurrentAssignments(bool $split): void
    {
        $this->distribute();
        $c=Bootstrap::buildContainer();$dimensions=$c->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $dimensions->setEnabled($this->supplier,true);
        $type=$dimensions->ensureDefaultTypes($this->supplier,['stredisko'])['cost_center'];
        $one=$dimensions->createValue($this->supplier,$type,['code'=>'ONE','name'=>'Synthetic one'])['id'];
        $two=$dimensions->createValue($this->supplier,$type,['code'=>'TWO','name'=>'Synthetic two'])['id'];
        $this->posting=Bootstrap::buildContainer()->get(PostingService::class);
        $usage=$this->usage('2097-08-01',false,$split?[]:[$type=>$one],$split?[$type=>[$one=>0.4,$two=>0.6]]:[]);
        $reversal=$this->posting->reverse($this->supplier,$usage);
        $this->closing->assertProfitDistributionUnsettled($this->supplier,$this->source);
        $lines=$c->get(JournalEntryRepository::class)->linesForEntry($reversal,$this->supplier,true);$line=(int)$lines[0]['id'];
        if($split) {
            $this->db->pdo()->prepare('UPDATE journal_entry_line_dimension_splits SET share=0.5 WHERE line_id=?')->execute([$line]);
        } else {
            $this->db->pdo()->prepare('UPDATE journal_entry_line_dimensions SET dimension_value_id=? WHERE line_id=?')->execute([$two,$line]);
        }
        $this->assertBlocked();
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false,false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true,false])]
    #[\PHPUnit\Framework\Attributes\TestWith([false,true])]
    #[\PHPUnit\Framework\Attributes\TestWith([true,true])]
    public function testCurrentPairProofAfterEarlierSnapshot(bool $isolation,bool $corrupt): void
    {
        $decision=$this->distribute();$usage=$this->usage();
        $reversal=$corrupt?$this->posting->reverse($this->supplier,$usage):null;
        $pdo=$this->db->pdo();$pdo->commit();$this->committed=true;
        $previous=(int)$pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation='.($isolation?'ON':'OFF'));$pdo->beginTransaction();
            $this->history();
            Connection::withoutSharedTestConnection(function()use($usage,$reversal,$corrupt):void {
                $c=Bootstrap::buildContainer();$db=$c->get(Connection::class);
                try {
                    self::assertNotSame($this->db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),$db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
                    if($corrupt) {
                        $db->pdo()->prepare("UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id SET l.amount=39.99 WHERE l.entry_id=? AND a.account_code='221'")->execute([$reversal]);
                    } else {$c->get(PostingService::class)->reverse($this->supplier,$usage);}
                } finally {$db->close();}
            });
            try {
                $this->revert();
                self::assertFalse($corrupt||$isolation,'Only a complete current pair with snapshot isolation OFF may pass.');
            } catch(\PDOException $e) {
                self::assertTrue($isolation);self::assertSame(1020,(int)($e->errorInfo[1]??0));self::assertFalse($pdo->inTransaction());
            } catch(ClosingException $e) {
                self::assertTrue($corrupt&&!$isolation);self::assertSame('distribution_settled',$e->errorCode);self::assertTrue($pdo->inTransaction());
            }
            $entry=Bootstrap::buildContainer()->get(JournalEntryRepository::class)->findForUpdate($decision,$this->supplier);
            self::assertSame($corrupt||$isolation,$entry!==null);
        } finally {
            if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('SET SESSION innodb_snapshot_isolation='.$previous);
        }
    }

    public function testTwoOriginalsCannotClaimOneMirror(): void
    {
        $this->distribute();$first=$this->usage();$mirror=$this->posting->reverse($this->supplier,$first);
        $other=$this->usage();$this->db->pdo()->prepare('UPDATE journal_entries SET reversed_by=? WHERE id=?')->execute([$mirror,$other]);
        $this->assertBlocked();
    }

    private function usage(string $date='2097-08-01',bool $red=false,array $dimensions=[],array $splits=[]): int
    {
        return $this->posting->postDocument($this->supplier,'manual',null,[
            ['account_code'=>'428','side'=>'debit','amount'=>40,'is_red_storno'=>$red,'dimensions'=>$dimensions,'dimension_splits'=>$splits],
            ['account_code'=>'221','side'=>'credit','amount'=>40,'is_red_storno'=>$red,'dimensions'=>$dimensions,'dimension_splits'=>$splits],
        ],['entry_date'=>$date,'posted'=>true]);
    }

    private function revert(): void
    {
        $preview=$this->closing->profitDistributionPreview($this->supplier,$this->source);
        $this->closing->revertProfitDistribution($this->supplier,$this->source,$preview['target_period']['row_version']);
    }

    private function assertBlocked(): void
    {
        $before=$this->history();
        try {$this->revert();self::fail('Unverified or remaining follow-up must block decision removal.');}
        catch(ClosingException $e){self::assertSame('distribution_settled',$e->errorCode);}
        self::assertSame($before,$this->history());
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
