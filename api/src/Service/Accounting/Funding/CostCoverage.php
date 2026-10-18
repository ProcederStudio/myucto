<?php
declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Funding;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Accounting\PostingException;
final class CostCoverage
{
    public function __construct(private readonly Connection $db) {}
    public function capacity(int $supplier,int $line,array $context=[]):array
    {
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$context):array{
            $guard->lockPeriods($supplier);$native=(new NativeCostCoverage($this->db))->forCost($supplier,$line);$cost=null;
            foreach($native['ledger']['lines']as$row)if($row['id']===$line)$cost=\MyInvoice\Service\Accounting\JournalLineAmount::signedCents($row);
            if($cost===null)throw new \LogicException('Cost line is missing.');$claimed=$this->claimed($supplier,$line,$context);$direct=$native['covered_cents'];$available=max(0,$cost-$direct-(int)$claimed);$c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);$envelope=$this->readJ6(fn()=>$c->get(J6FundingCapacity::class)->costEnvelope($supplier,$line,$context['j6_period_id']??null));$pool=null;
            if($envelope!==null){$poolClaimed='0';$poolDirect='0';foreach($envelope['line_amounts']as$id=>$amount){if($amount<=0)continue;$poolClaimed=bcadd($poolClaimed,$this->claimed($supplier,(int)$id,$context),0);$poolDirect=bcadd($poolDirect,(string)(new NativeCostCoverage($this->db))->forCost($supplier,(int)$id)['covered_cents'],0);}$pool=$envelope+['claimed_cents'=>$poolClaimed,'direct_cents'=>$poolDirect];$available=min($available,max(0,$envelope['net_cents']-(int)$poolClaimed-(int)$poolDirect));}
            return ['cost_cents'=>$cost,'direct_cents'=>$direct,'claimed_cents'=>$claimed,'available_cents'=>$native['eligible']?$available:0,'pool'=>$pool];
        });
    }
    public function claimed(int $supplier,int $line,array $context=[]):string
    {
        if(array_diff(array_keys($context),['j6_period_id'])!==[]||array_key_exists('j6_period_id',$context)&&(!is_int($context['j6_period_id'])||$context['j6_period_id']<=0))throw new \InvalidArgumentException('Invalid coverage replacement context.');
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$context):string{
            $guard->lockPeriods($supplier);
            if(isset($context['j6_period_id'])){$q=$this->db->pdo()->prepare('SELECT e.period_id FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id WHERE l.supplier_id=? AND l.id=? FOR UPDATE');$q->execute([$supplier,$line]);if((int)$q->fetchColumn()!==$context['j6_period_id'])throw new \InvalidArgumentException('Coverage context does not own this cost.');}
            $c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);
            return $this->readJ6(fn()=>$c->get(J6FundingCapacity::class)->claimedCost($supplier,$line,$context['j6_period_id']??null));
        });
    }
    private function readJ6(callable $read):mixed
    {
        try{return $read();}catch(NonprofitTaxAssessmentException $e){throw new PostingException($e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
}
