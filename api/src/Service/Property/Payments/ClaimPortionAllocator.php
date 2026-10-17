<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Service\Property\PropertyInput;
final class ClaimPortionAllocator
{
    public static function distribute(string $amount,array $capacities,?array $declared):array
    {
        StatutoryAllocationPolicy::cents($amount);$sum='0';foreach($capacities as $id=>$capacity){PropertyInput::id($id,'portion_id');$sum=bcadd($sum,StatutoryAllocationPolicy::cents($capacity),0);}if(bccomp($amount,$sum,0)>0)throw new \DomainException('distribution_capacity');
        $capacities=array_filter($capacities,fn($c)=>$c!=='0');$out=[];
        if($declared===null){
            if($amount==='0')return [];
            if($amount===$sum)$out=$capacities;
            elseif(count($capacities)===1)$out=[array_key_first($capacities)=>$amount];
            else throw new \DomainException('distribution_required');
        }else{
            PropertyInput::fields($declared,['evidence','parts']);PropertyInput::text($declared['evidence']??null,'distribution.evidence',4000);$actual='0';
            foreach(PropertyInput::list($declared['parts']??null,'parts') as $part){PropertyInput::fields($part,['id','amount_cents']);$id=PropertyInput::id($part['id']??null,'portion_id');$value=StatutoryAllocationPolicy::cents($part['amount_cents']??null);
                if(isset($out[$id])||!isset($capacities[$id])||$value==='0'||bccomp($value,$capacities[$id],0)>0)throw new \DomainException('distribution_capacity');$out[$id]=$value;$actual=bcadd($actual,$value,0);}
            if($actual!==$amount)throw new \DomainException('distribution_margin');
        }
        ksort($out,SORT_NUMERIC);return $out;
    }
}
