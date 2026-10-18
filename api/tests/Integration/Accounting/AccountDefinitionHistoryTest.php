<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository,AccountingPeriodRepository,ChartOfAccountsRepository,ClosingRepository,LedgerReportRepository,SaldoRepository};
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder,PostingService,AccountDefinitionException};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Reports\{FinancialStatementService,AccountDetailService,AccountStatementService,BalanceInventoryService,SaldoService};
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\TestCase;
final class AccountDefinitionHistoryTest extends TestCase
{
    use IsolatedSupplierTrait;
    private \DI\Container $container;
    private Connection $db;
    private int $supplier;
    private int $actor;
    private array $periods=[];
    private array $ids=[];
    private AccountDefinitionRepository $definitions;
    private bool $committed=false;
    private ?int $originalIsolation=null;
    protected function setUp(): void
    {
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);$p=$this->db->pdo();self::assertSame(getenv('MYINVOICE_DB_NAME'),$p->query('SELECT DATABASE()')->fetchColumn());$p->beginTransaction();
        $seed=(int)$p->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();$this->supplier=$this->createIsolatedSupplier($p,$seed);$p->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);$this->actor=(int)$p->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier,'2025-01-01');$chart=$this->container->get(ChartOfAccountsRepository::class);$parent=$chart->findByCode($this->supplier,'336');$chart->insert($this->supplier,['account_code'=>'336.007','name'=>'Synthetic custom debit account','account_type'=>'liability','normal_side'=>'debit','is_synthetic'=>false,'parent_id'=>$parent['id']]);foreach($chart->listForTenant($this->supplier,true) as $a)$this->ids[$a['account_code']]=$a['id'];
        foreach([2025,2026] as $year)$this->periods[$year]=$this->container->get(AccountingPeriodRepository::class)->create($this->supplier,$year,"$year-01-01","$year-12-31");$this->definitions=new AccountDefinitionRepository($this->db);
        foreach([['336','25.00'],['574','10.00'],['336.007','7.00']] as [$code,$amount])$this->post('2025-07-01',$code,'221',$amount);
    }
    protected function tearDown(): void
    {
        if(!isset($this->db))return;$p=$this->db->pdo();if($p->inTransaction())$p->rollBack();if($this->committed){foreach(['accounting_account_definition_history','activity_log','journal_entries'] as $table)$p->prepare('DELETE FROM '.$table.' WHERE supplier_id=?')->execute([$this->supplier]);$p->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);}if($this->originalIsolation!==null)$p->exec('SET SESSION innodb_snapshot_isolation='.$this->originalIsolation);
    }
    private function post(string $date,string $debit,string $credit,string $amount,array $dimensions=[]): void
    {
        $this->container->get(PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>$debit,'side'=>'debit','amount'=>$amount,'dimensions'=>$dimensions],['account_code'=>$credit,'side'=>'credit','amount'=>$amount,'dimensions'=>$dimensions]],['entry_date'=>$date,'posted'=>true,'posted_by'=>$this->actor]);
    }
    private function targetDefinitions(): void
    {
        foreach(['336'=>null,'574'=>'credit'] as $code=>$side){$id=$this->ids[$code];$this->definitions->change($this->supplier,$id,'2026-01-01',['normal_side'=>$side,'name'=>'Synthetic approved 504 '.$code],$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic explicit target definition');}
    }
    private function reports2025(): array
    {
        $s=$this->supplier;$p=$this->periods[2025];$c=$this->container;$report=$c->get(FinancialStatementService::class);
        $data=['bs'=>$report->balanceSheet($s,$p,'2025-12-31','full'),'pl'=>$report->incomeStatement($s,$p,'2025-12-31','full'),'trial'=>$c->get(LedgerReportRepository::class)->trialBalanceRows($s,'2025-01-01','2025-12-31','2025-01-01',true,[],true),'unusual'=>$c->get(ClosingRepository::class)->accountsOnUnusualSide($s,$p,'2025-12-31'),'saldo'=>$c->get(SaldoService::class)->build($s,$p,'2025-12-31','336'),'saldo574'=>$c->get(SaldoService::class)->build($s,$p,'2025-12-31','574'),'open_items'=>$c->get(\MyInvoice\Service\Accounting\Reports\OpenItemsService::class)->build($s,$this->ids['336'],'2025-12-31',false,1,50),'detail'=>$c->get(AccountDetailService::class)->build($s,$this->ids['336'],'2025-01-01','2025-12-31'),'statement'=>$c->get(AccountStatementService::class)->build($s,$this->ids['336'],'2025-01-01','2025-12-31',1,50),'inventory'=>$c->get(BalanceInventoryService::class)->build($s,$p)];
        foreach($data as &$report)unset($report['entity']['prepared_at']);unset($report);return $data;
    }
    public function testNative500HistoryAnd504ReportsUseExplicitDatedSidesWithoutRewritingCustomAnalytics(): void
    {
        $before=$this->reports2025();$base=$this->db->pdo()->query('SELECT * FROM chart_of_accounts WHERE supplier_id='.$this->supplier.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);$this->targetDefinitions();
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);$catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2026-01-01');$this->db->pdo()->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'synthetic','Synthetic confirmed activity kind')")->execute([$catalog['activity_kind']['id'],$this->supplier]);$dimensions=[$catalog['activity']['id']=>$catalog['activity']['values']['main'],$catalog['activity_kind']['id']=>(int)$this->db->pdo()->lastInsertId(),$catalog['tax_regime']['id']=>$catalog['tax_regime']['values']['subject']];$this->post('2026-02-01','336','221','5.00',$dimensions);$this->post('2026-02-01','221','574','2.00',$dimensions);$this->post('2026-02-01','336.007','221','1.00',$dimensions);
        self::assertSame($before,$this->reports2025());self::assertSame($base,$this->db->pdo()->query('SELECT * FROM chart_of_accounts WHERE supplier_id='.$this->supplier.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        $trial=$this->container->get(LedgerReportRepository::class)->trialBalanceRows($this->supplier,'2026-01-01','2026-12-31','2026-01-01',true,[],true);$byCode=array_column($trial,null,'account_code');self::assertNull($byCode['336']['normal_side']);self::assertSame('credit',$byCode['574']['normal_side']);self::assertSame('debit',$byCode['336.007']['normal_side']);$saldo=$this->container->get(SaldoService::class)->build($this->supplier,$this->periods[2026],'2026-12-31','574');self::assertSame('credit',$saldo['accounts'][0]['account']['normal_side']);self::assertSame(2.0,$saldo['accounts'][0]['gl_balance']);
        $unusual=$this->container->get(ClosingRepository::class)->accountsOnUnusualSide($this->supplier,$this->periods[2026],'2026-12-31');self::assertNotContains('336',array_column($unusual,'account_code'));self::assertSame('cz_504',$this->container->get(FinancialStatementService::class)->balanceSheet($this->supplier,$this->periods[2026],'2026-12-31','full')['accounting_framework']);
    }
    public function testSqlAndPhpReadersPreserveNullNewestBoundaryAndTenantIsolation(): void
    {
        $this->targetDefinitions();$this->definitions->change($this->supplier,$this->ids['336'],'2027-01-01',['normal_side'=>'debit'],$this->definitions->stateHash($this->supplier,$this->ids['336']),$this->actor,'Synthetic next approved definition');
        foreach(['2025-12-31'=>'credit','2026-01-01'=>null,'2027-01-01'=>'debit'] as $date=>$side){$php=[];foreach($this->definitions->listForDate($this->supplier,$date) as $row)$php[$row['account_code']]=$row['normal_side'];$sql=$this->db->pdo()->query('SELECT account_code,normal_side FROM '.AccountDefinitionRepository::relation($date,$this->supplier).' a')->fetchAll(\PDO::FETCH_KEY_PAIR);ksort($php);ksort($sql);self::assertSame($php,$sql);self::assertSame($side,$php['336']);self::assertSame('debit',$php['336.007']);}
        $other=$this->createIsolatedSupplier($this->db->pdo(),$this->supplier);$chart=$this->container->get(ChartOfAccountsRepository::class);$id=$chart->insert($other,['account_code'=>'336','name'=>'Synthetic other tenant','account_type'=>'liability','normal_side'=>'credit','is_synthetic'=>true]);self::assertSame('credit',$chart->findById($other,$id,'2026-12-31')['normal_side']);self::assertNull($this->definitions->findForDate($other,$this->ids['336'],'2026-12-31'));
    }
    public function testCreatedHistoricalAccountDoesNotExistBeforeItsExplicitBirthDate(): void
    {
        $this->targetDefinitions();$chart=$this->container->get(ChartOfAccountsRepository::class);$id=$chart->insert($this->supplier,['account_code'=>'336.008','name'=>'Synthetic new dated account','account_type'=>'liability','normal_side'=>'credit','parent_id'=>$this->ids['336']],'2027-03-01',$this->actor);
        self::assertNull($chart->findById($this->supplier,$id,'2027-02-28'));self::assertSame('credit',$chart->findById($this->supplier,$id,'2027-03-01')['normal_side']);$q=$this->db->pdo()->query('SELECT account_code FROM '.AccountDefinitionRepository::relation('2027-02-28',$this->supplier).' a');self::assertNotContains('336.008',$q->fetchAll(\PDO::FETCH_COLUMN));
        try{$this->definitions->change($this->supplier,$id,'2026-01-01',['name'=>'Synthetic forbidden invented past'],$this->definitions->stateHash($this->supplier,$id),$this->actor,'Synthetic past boundary');self::fail('A new account was given invented previous existence.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_before_creation',$e->errorCode);}
    }
    public function testMetadataCasRejectsStaleApprovalAndAccountIdentityChanges(): void
    {
        $this->targetDefinitions();$id=$this->ids['336'];$before=$this->definitions->stateHash($this->supplier,$id);$after=$this->definitions->change($this->supplier,$id,'2027-01-01',['name'=>'Synthetic first approval'],$before,$this->actor,'Synthetic first decision');self::assertNotSame($before,$after);
        try{$this->definitions->change($this->supplier,$id,'2028-01-01',['normal_side'=>'debit'],$before,$this->actor,'Synthetic stale decision');self::fail('Stale metadata was accepted.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_stale',$e->errorCode);}
        foreach(['account_type'=>'asset','parent_id'=>$this->ids['574'],'account_code'=>'999'] as $field=>$value){try{$this->definitions->change($this->supplier,$id,'2028-01-01',[$field=>$value],$after,$this->actor,'Synthetic identity decision');self::fail('Account identity was changed.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_identity_immutable',$e->errorCode);}}
    }
    public function testHistoricalEditAndNativeCsvImportAreDatedWhileLegacyUpdateKeepsItsBehavior(): void
    {
        $chart=$this->container->get(ChartOfAccountsRepository::class);$chart->update($this->supplier,$this->ids['336.007'],['name'=>'Synthetic legacy update'],'2027-01-01');self::assertSame('Synthetic legacy update',$chart->findById($this->supplier,$this->ids['336.007'],'2025-12-31')['name']);self::assertFalse($this->definitions->hasHistory($this->supplier));
        $this->targetDefinitions();$before=$chart->findById($this->supplier,$this->ids['336'],'2025-12-31');$base=$this->db->pdo()->query('SELECT name FROM chart_of_accounts WHERE id='.$this->ids['336'])->fetchColumn();$chart->update($this->supplier,$this->ids['336'],['name'=>'Synthetic dated edit'],'2027-01-01');self::assertSame($before,$chart->findById($this->supplier,$this->ids['336'],'2025-12-31'));self::assertSame('Synthetic dated edit',$chart->findById($this->supplier,$this->ids['336'],'2027-01-01')['name']);
        $import=$this->container->get(\MyInvoice\Service\Accounting\Codebooks\ChartOfAccountsImportService::class);$csv="ucet;nazev\n336;Synthetic dated import\n";$state=$this->definitions->stateHash($this->supplier,$this->ids['336']);$preview=$import->import($this->supplier,$this->actor,$csv,'synthetic.csv',true,'2028-01-01');self::assertTrue($preview['ok']);self::assertSame($state,$this->definitions->stateHash($this->supplier,$this->ids['336']));$done=$import->import($this->supplier,$this->actor,$csv,'synthetic.csv',false,'2028-01-01');self::assertTrue($done['ok']);self::assertSame('Synthetic dated import',$chart->findById($this->supplier,$this->ids['336'],'2028-01-01')['name']);self::assertSame('Synthetic dated edit',$chart->findById($this->supplier,$this->ids['336'],'2027-12-31')['name']);self::assertSame($base,$this->db->pdo()->query('SELECT name FROM chart_of_accounts WHERE id='.$this->ids['336'])->fetchColumn());
    }
    public function testPostedIntervalAndClosedPeriodCannotBeReinterpreted(): void
    {
        $this->targetDefinitions();$id=$this->ids['336'];$hash=$this->definitions->stateHash($this->supplier,$id);
        try{$this->definitions->change($this->supplier,$id,'2025-01-01',['normal_side'=>'debit'],$hash,$this->actor,'Synthetic posted history rewrite');self::fail('Posted history metadata was rewritten.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_in_use',$e->errorCode);}
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier,$this->periods[2026]]);try{$this->definitions->change($this->supplier,$id,'2026-01-01',['normal_side'=>'debit'],$hash,$this->actor,'Synthetic closed period rewrite');self::fail('Closed period metadata was rewritten.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_period_locked',$e->errorCode);}
    }

    public function testCurrentLockedReaderAndCasSeeDefinitionCommittedAfterCallerSnapshot(): void
    {
        $this->targetDefinitions();$p=$this->db->pdo();$this->originalIsolation=(int)$p->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();$p->exec('SET SESSION innodb_snapshot_isolation=OFF');$p->commit();$this->committed=true;$p->beginTransaction();$old=$this->definitions->stateHash($this->supplier,$this->ids['336']);self::assertNull($this->definitions->findForDate($this->supplier,$this->ids['336'],'2027-01-01')['normal_side']);
        $other=Connection::withoutSharedTestConnection(fn()=>new Connection(\MyInvoice\Infrastructure\Config\Config::load(Bootstrap::rootDir())));try{self::assertSame(getenv('MYINVOICE_DB_NAME'),$other->pdo()->query('SELECT DATABASE()')->fetchColumn());$writer=new AccountDefinitionRepository($other);$writer->change($this->supplier,$this->ids['336'],'2027-01-01',['normal_side'=>'debit'],$writer->stateHash($this->supplier,$this->ids['336']),$this->actor,'Synthetic concurrent approved definition');}finally{$other->close();}
        self::assertSame('debit',$this->definitions->findForDate($this->supplier,$this->ids['336'],'2027-01-01',true)['normal_side']);try{$this->definitions->change($this->supplier,$this->ids['336'],'2028-01-01',['name'=>'Synthetic stale snapshot overwrite'],$old,$this->actor,'Synthetic stale RR decision');self::fail('An RR snapshot overwrote a newer committed definition.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_stale',$e->errorCode);}
    }
    public function testAnExistingPostedAccountCannotBeInitializedAsANewAccount(): void
    {
        try{$this->definitions->initializeCreated($this->supplier,$this->ids['336'],'2027-01-01',$this->actor);self::fail('A posted historical account was given a new birth date.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_existing_account',$e->errorCode);}
    }

    public function testFutureAccountBirthReservesPhysicalIdentityAndCannotBeRecreatedByEarlierImport(): void
    {
        $this->targetDefinitions();$chart=$this->container->get(ChartOfAccountsRepository::class);$data=['account_code'=>'336.009','name'=>'Synthetic future physical identity','account_type'=>'liability','normal_side'=>'credit','parent_id'=>$this->ids['336']];$id=$chart->insert($this->supplier,$data,'2029-01-01',$this->actor);self::assertNull($chart->findById($this->supplier,$id,'2028-01-01'));
        try{$chart->insert($this->supplier,$data,'2028-01-01',$this->actor);self::fail('A future account physical code was reused.');}catch(AccountDefinitionException $e){self::assertSame('account_definition_code_exists',$e->errorCode);}
        $import=$this->container->get(\MyInvoice\Service\Accounting\Codebooks\ChartOfAccountsImportService::class);$before=$this->definitions->stateHash($this->supplier,$id);$preview=$import->import($this->supplier,$this->actor,"ucet;nazev;nadrizeny_ucet\n336.009;Synthetic wrong earlier identity;336\n",'synthetic.csv',true,'2028-01-01');self::assertFalse($preview['ok']);self::assertSame(1,$preview['failed']);self::assertSame($before,$this->definitions->stateHash($this->supplier,$id));
    }

    public function testNativePostingCannotUseAccountDeactivatedAfterCallerSnapshot(): void
    {
        $this->targetDefinitions();$this->periods[2027]=$this->container->get(AccountingPeriodRepository::class)->create($this->supplier,2027,'2027-01-01','2027-12-31');$p=$this->db->pdo();$this->originalIsolation=(int)$p->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();$p->exec('SET SESSION innodb_snapshot_isolation=OFF');$p->commit();$this->committed=true;$p->beginTransaction();self::assertTrue((bool)$this->definitions->findForDate($this->supplier,$this->ids['336'],'2027-01-01')['is_active']);
        $other=Connection::withoutSharedTestConnection(fn()=>new Connection(\MyInvoice\Infrastructure\Config\Config::load(Bootstrap::rootDir())));try{$writer=new AccountDefinitionRepository($other);$writer->change($this->supplier,$this->ids['336'],'2027-01-01',['is_active'=>false],$writer->stateHash($this->supplier,$this->ids['336']),$this->actor,'Synthetic concurrent deactivation');}finally{$other->close();}
        try{$this->post('2027-02-01','336','221','1.00');self::fail('Native posting used stale active account metadata.');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('account_inactive',$e->errorCode);}
    }

    public function testDatabaseRejectsMissingCanonicalMetadataFieldsInsteadOfSqlNullCheckPass(): void
    {
        $date='2029-01-01';$json='{}';$hash=hash('sha256',$this->supplier.':'.$this->ids['336'].':'.$date.':'.$json);$q=$this->db->pdo()->prepare("INSERT INTO accounting_account_definition_history(supplier_id,account_id,effective_from,name,normal_side,tax_deductibility,is_active,definition_json,definition_hash,evidence,recorded_at) VALUES(?,?,?,'Synthetic missing JSON',NULL,'deductible',1,?,?,'Synthetic malformed payload',UTC_TIMESTAMP(6))");
        try{$q->execute([$this->supplier,$this->ids['336'],$date,$json,$hash]);self::fail('SQL NULL checks accepted an incomplete canonical metadata payload.');}catch(\PDOException $e){self::assertSame(4025,(int)($e->errorInfo[1]??0));}
    }

}
