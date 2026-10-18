<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository as R;
use MyInvoice\Service\Anonymization\{AnonymizationService,AnonymizationOptions,DatabaseCloner};
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\TestCase;
final class FrameworkConversionCloneTest extends TestCase
{
    private array $owned=[];
    private PDO $server;
    private Config $config;
    private Connection $source;
    private ?Connection $target=null;
    private FrameworkConversionWorkflowTest $fixture;
    private string|false $priorDatabase;
    protected function setUp(): void
    {
        $this->priorDatabase=getenv('MYINVOICE_DB_NAME');$this->config=Config::load(Bootstrap::rootDir());$base=(string)$this->config->get('db.name');
        self::assertStringEndsWith('_test',$base);self::assertContains($this->config->get('db.host'),['localhost','127.0.0.1','::1']);
        $token=bin2hex(random_bytes(5));$this->owned=['myucto_nno_conversion_clone_'.$token.'_source_test','myucto_nno_conversion_clone_'.$token.'_target_test'];
        $this->server=new PDO('mysql:host='.$this->config->get('db.host').';port='.$this->config->get('db.port',3306).';charset=utf8mb4',$this->config->get('db.user'),$this->config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$q->execute($this->owned);self::assertSame(0,(int)$q->fetchColumn());
        $cloner=new DatabaseCloner($this->server);$plan=$cloner->plan($base);$cloner->copyTables($plan,$this->owned[0],static function():void{});$cloner->finalize($plan,$this->owned[0]);
        putenv('MYINVOICE_DB_NAME='.$this->owned[0]);$this->fixture=new FrameworkConversionWorkflowTest('testNext504ForeignNativeCaseUsesProvedConversionOpening');
        Connection::withoutSharedTestConnection(fn()=>(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture));$this->source=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);
    }
    protected function tearDown(): void
    {
        try{
            if(isset($this->fixture))(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);
            if(isset($this->source))$this->source->close();
            if($this->target!==null){if($this->target->pdo()->inTransaction())$this->target->pdo()->rollBack();$this->target->close();}
            if(isset($this->server))foreach(array_reverse($this->owned) as $name)OwnedTestDatabaseCleanup::drop($this->server,$name,$this->owned);
        }finally{if(isset($this->priorDatabase))putenv($this->priorDatabase===false?'MYINVOICE_DB_NAME':'MYINVOICE_DB_NAME='.$this->priorDatabase);}
    }
    private function call(string $method,mixed ...$args): mixed{return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    private function supplier(): int{return(new \ReflectionProperty($this->fixture,'supplier'))->getValue($this->fixture);}
    private function prepare(bool $decision=false,bool $laterConfig=false):array
    {
        $supplier=$this->supplier();$actor=(new \ReflectionProperty($this->fixture,'actor'))->getValue($this->fixture);$container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);$periods=$container->get(\MyInvoice\Repository\AccountingPeriodRepository::class);$source=(new \ReflectionProperty($this->fixture,'source'))->getValue($this->fixture);$target=(new \ReflectionProperty($this->fixture,'target'))->getValue($this->fixture);
        $this->source->pdo()->prepare("UPDATE chart_of_accounts SET name='Synthetic conversion-account@synthetic.invalid' WHERE supplier_id=? AND account_code='221'")->execute([$supplier]);$command=$this->call('command');$command['evidence']='Synthetic conversion-evidence@synthetic.invalid';foreach($command['mapping'] as &$mapping)$mapping['activity_kind']['name']='Synthetic conversion-kind@synthetic.invalid';unset($mapping);
        $workflow=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class);$preview=$workflow->preview($supplier,$command,$actor);$result=$workflow->confirm($supplier,$command,$actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-clone-conversion');
        if($decision){self::assertTrue($periods->setStatusCas($source,$supplier,'approved',(int)$periods->findById($supplier,$source)['row_version'],$actor,['body'=>'Synthetic conversion-authority@synthetic.invalid','decision_ref'=>'Synthetic conversion-approval@synthetic.invalid','document_hash'=>hash('sha256','synthetic-conversion-approved')]));$decisions=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionResultDecision::class);$choices=$decisions->choices($supplier,$result['conversion_id'],'2026-03-01');$body=['decision_date'=>'2026-03-01','decision_authority'=>'Synthetic conversion-decision-authority@synthetic.invalid','decision_reference'=>'Synthetic conversion-decision-reference@synthetic.invalid','allocations'=>array_map(fn($cell)=>['cell_key'=>$cell['key'],'role'=>'result.retained_profit','amount'=>\MyInvoice\Service\Accounting\Closing\Fx504Builder::decimal(abs($cell['available_cents']))],$choices['cells'])];$preview=$decisions->preview($supplier,$result['conversion_id'],$body,$actor);$result['decision']=$decisions->confirm($supplier,$result['conversion_id'],$body,$actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-clone-decision');}
        if($laterConfig){$definitions=new \MyInvoice\Repository\AccountDefinitionRepository($this->source);$account=$container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class)->findByCode($supplier,'221','2026-01-01');$definitions->change($supplier,$account['id'],'2027-01-01',['name'=>'Synthetic later independent name'],$definitions->stateHash($supplier,$account['id']),$actor,'Synthetic later config owner');}
        $this->source->pdo()->commit();return $result+['actor'=>$actor,'source'=>$source,'target'=>$target];
    }
    private function cloneTarget():\Psr\Container\ContainerInterface
    {
        $before=$this->fingerprint($this->source);(new AnonymizationService($this->config))->run(new AnonymizationOptions($this->owned[0],$this->owned[1],seed:'synthetic-conversion-clone'),static function():void{});self::assertSame($before,$this->fingerprint($this->source));
        putenv('MYINVOICE_DB_NAME='.$this->owned[1]);$container=Connection::withoutSharedTestConnection(function(){$container=Bootstrap::buildContainer();$this->target=$container->get(Connection::class);return $container;});$this->target->pdo()->beginTransaction();self::assertSame($this->owned[1],$this->target->pdo()->query('SELECT DATABASE()')->fetchColumn());return $container;
    }
    public function testActualFullClonePreservesFinancialOriginProofAndOwnedCancellation():void
    {
        $result=$this->prepare();$container=$this->cloneTarget();$read=new \MyInvoice\Repository\FrameworkConversionRepository($this->target);$row=$read->receipt($this->supplier(),$result['conversion_id'],true);$source=$read->source($this->supplier(),$result['source'],$result['target'],true);self::assertSame($source['source_hash'],$row['source_hash']);
        $fx=(new \MyInvoice\Repository\Fx504ReadRepository($this->target))->snapshot($this->supplier(),$result['target'],true);self::assertSame([],$fx['case_snapshot']['findings']);self::assertNotEmpty($fx['case_snapshot']['origins']);
        $all='';foreach(['accounting_framework_conversions','accounting_framework_conversion_cells','accounting_account_definition_history'] as $table)$all.=json_encode($this->target->pdo()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);foreach(['conversion-account@synthetic.invalid','conversion-evidence@synthetic.invalid','conversion-kind@synthetic.invalid'] as $private)self::assertStringNotContainsString($private,$all);self::assertSame(0,(int)$this->target->pdo()->query('SELECT COUNT(*) FROM accounting_framework_conversion_commands')->fetchColumn());
        $workflow=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class);$preview=$workflow->cancellationPreview($this->supplier(),$result['conversion_id'],$result['actor']);$cancel=$workflow->cancel($this->supplier(),$result['conversion_id'],$result['actor'],$preview['receipt_hash'],$preview['preview_hash'],'synthetic-target-cancel');self::assertSame('cancelled',$cancel['status']);self::assertSame('cz_500',$container->get(\MyInvoice\Service\Accounting\AccountingFrameworkService::class)->forDate($this->supplier(),'2026-01-01'));
    }
    public function testActualFullClonePreserves931DecisionAndCannotCancelConsumedOpening():void
    {
        $result=$this->prepare(true);$container=$this->cloneTarget();$q=$this->target->pdo()->prepare('SELECT * FROM accounting_framework_conversion_decisions WHERE supplier_id=?');$q->execute([$this->supplier()]);$decision=$q->fetch(PDO::FETCH_ASSOC);$receipt=json_decode($decision['receipt_json'],true,512,JSON_THROW_ON_ERROR);self::assertSame(\MyInvoice\Repository\FrameworkConversionRepository::hash($receipt),$decision['receipt_hash']);self::assertSame($receipt['ledger'],(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->target))->current($this->supplier(),(int)$decision['journal_entry_id']));self::assertStringNotContainsString('conversion-decision-authority@synthetic.invalid',$decision['request_json']);self::assertStringNotContainsString('conversion-decision-reference@synthetic.invalid',$decision['request_json']);
        try{$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class)->cancellationPreview($this->supplier(),$result['conversion_id'],$result['actor']);self::fail('Native result decision consumed the converted opening');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_already_consumed',$e->errorCode);}
    }
    public function testActualFullCloneDoesNotEraseLaterMetadataConsumption():void
    {
        $result=$this->prepare(false,true);$container=$this->cloneTarget();try{$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class)->cancellationPreview($this->supplier(),$result['conversion_id'],$result['actor']);self::fail('Independent later metadata must remain protected after cloning');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_configuration_consumed',$e->errorCode);}
    }
    public function testCancelledThenReopenedSourceCloneRequiresHistoricalSourceProofAndDoesNotMutateSource():void
    {
        $result=$this->prepare();$container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);$supplier=$this->supplier();$workflow=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class);$preview=$workflow->cancellationPreview($supplier,$result['conversion_id'],$result['actor']);$workflow->cancel($supplier,$result['conversion_id'],$result['actor'],$preview['receipt_hash'],$preview['preview_hash'],'synthetic-clone-cancel-release');$periods=$container->get(\MyInvoice\Repository\AccountingPeriodRepository::class);$version=(int)$periods->findById($supplier,$result['source'])['row_version'];$container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)->revertStep($supplier,$result['source'],'close_books',$version,['user_id'=>$result['actor']]);$before=$this->fingerprint($this->source);
        try{(new AnonymizationService($this->config))->run(new AnonymizationOptions($this->owned[0],$this->owned[1],seed:'synthetic-cancelled-source-history'),static function():void{});self::fail('A reopened historical source must not be silently rebased as the original conversion source');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $error){self::assertSame('conversion_cancelled_source_history_required',$error->errorCode);}self::assertSame($before,$this->fingerprint($this->source));
    }
    private function fingerprint(Connection $db):string
    {
        $out=[];foreach(['accounting_framework_conversions','accounting_framework_conversion_cells','accounting_framework_conversion_commands','accounting_framework_conversion_decisions','accounting_account_definition_history','chart_of_accounts','journal_entries','journal_entry_lines'] as $table)$out[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY supplier_id'.($table==='accounting_framework_conversion_cells'?',conversion_id,cell_key':($table==='accounting_framework_conversion_commands'?',command_token':',id')))->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
    }
}
