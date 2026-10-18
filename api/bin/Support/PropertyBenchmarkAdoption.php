<?php

declare(strict_types=1);
namespace MyInvoice\Benchmark;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,ChartOfAccountsRepository};
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use MyInvoice\Service\Property\Receivables\PropertyReconciliation;
use Psr\Container\ContainerInterface;
final class PropertyBenchmarkAdoption
{
    public function __construct(private readonly Connection $db,private readonly ContainerInterface $container){}
    public function apply(array $f,int $months,string $firstMonth,string $adoptionDate='2026-01-01'):array
    {
        if($months<1||$months>12||count($f['units'])*$months>4800)throw new \InvalidArgumentException('Bounded portfolio adoption requires at most4800 items');
        $s=$f['supplier'];$actor=$f['actor'];$periods=new AccountingPeriodRepository($this->db);$year=(int)substr($adoptionDate,0,4);if($periods->findByYear($s,$year)===null)$periods->create($s,$year,$year.'-01-01',$year.'-12-31');$chart=new ChartOfAccountsRepository($this->db);
        if($chart->findByCode($s,'315.100')===null){$parent=$chart->findByCode($s,'315');$chart->insert($s,['account_code'=>'315.100','name'=>'Synthetic historical unit receivables','account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
        $items=[];$date=new \DateTimeImmutable($firstMonth.'-01',new \DateTimeZone('Europe/Prague'));
        for($n=0;$n<$months;$n++){$from=$date->format('Y-m-d');$to=$date->modify('last day of this month')->format('Y-m-d');$occurrence=$date->format('Y-m');foreach($f['units'] as $index=>$unit){$groups=[];foreach($f['states'][$unit]['ownerships'] as $g)$groups[]=['group_key'=>$g['group_key'],'role'=>'debtor','mode'=>$g['kind']==='marital_community'?'solidary':'individual','basis'=>$g['kind']==='marital_community'?'sjm_713':'documented_title','members'=>$g['members'],'extent'=>$g['share'],'evidence_ref'=>'Synthetic assessed historical deed','legal_reason'=>'Synthetic documented historical expense reimbursement','valid_from'=>$f['start'].'T00:00:00+01:00','valid_to'=>null];
                $items[]=['case_ref'=>sprintf('SYNTHETIC-HIST-U%03d',$index+1),'occurrence_ref'=>$occurrence,'kind'=>'debt','unit_id'=>$unit,'service_id'=>null,'origin_at'=>$date->format('c'),'period_from'=>$from,'period_to'=>$to,'due_date'=>$date->format('Y-m').'-15','title_ref'=>'Synthetic historical documented reimbursement '.$occurrence,'evidence_ref'=>'Synthetic itemized signed transfer and original source ledger','liability_groups'=>$groups,'legal'=>['basis'=>'legal_act','legal_act_date'=>$from,'evidence'=>'Synthetic contemporary legal title','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic documented security assessment'],'reminders'=>[],'components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'180000']]];
            }$date=$date->modify('first day of next month');}
        $total=(string)(count($items)*180000);$request=['mode'=>'post_new','accounting_date'=>$adoptionDate,'effective_at'=>(new \DateTimeImmutable($adoptionDate,new \DateTimeZone('Europe/Prague')))->format('c'),'expected_revision'=>0,'evidence_ref'=>'Synthetic approved native S6 transfer: each historical reimbursement and retained-funds source ledger','reason'=>'Synthetic native adopted historical reimbursement balances, distinct from new monthly charge generation','items'=>$items,'other_balances'=>[['account_code'=>'932','side'=>'credit','amount_cents'=>$total,'dimensions'=>[]]]];
        $owner=$this->container->get(OpeningRepository::class);$before=PropertyBenchmarkRead::counters($this->db->pdo());$start=hrtime(true);$preview=$owner->preview($s,$request);$previewMs=(hrtime(true)-$start)/1e6;$previewHash=$preview['preview_hash'];unset($preview);$applyStart=hrtime(true);$result=$owner->apply($s,$request,$previewHash,'SYNTHETIC-HISTORICAL-ADOPTION',$actor);$applyMs=(hrtime(true)-$applyStart)/1e6;$after=PropertyBenchmarkRead::counters($this->db->pdo());
        if($result['status']!=='posted'||count($result['items'])!==count($items))throw new \RuntimeException('Native S6 adoption incomplete');
        $delta=[];foreach($after as $key=>$value)$delta[$key]=(int)$value-(int)$before[$key];$p=$this->db->pdo();$p->beginTransaction();try{(new OpeningReadProof($this->db))->batch($s,$result['id'],true);$p->commit();}catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
        $q=$p->prepare('SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=? AND opening_item_id IS NOT NULL');$q->execute([$s]);if((int)$q->fetchColumn()!==count($items))throw new \RuntimeException('Native S6 debt cardinality mismatch');
        return ['dataset_type'=>'native_s6_adopted_historical_reimbursement_claims','supplier_id'=>$s,'units'=>count($f['units']),'origin_months'=>$months,'origin_first'=>$firstMonth,'origin_last'=>$date->modify('-1 month')->format('Y-m'),'adoption_effective_at'=>$request['effective_at'],'claims'=>count($items),'total_cents'=>$total,'batch_id'=>$result['id'],'preview_ms'=>$previewMs,'apply_ms'=>$applyMs,'total_ms'=>$previewMs+$applyMs,'sql_delta'=>$delta,'physical_origin_proof'=>true,'historical_gl_before_adoption'=>false];
    }
}
