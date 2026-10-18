<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use PDO;

final class NonprofitSvjSourceFacts
{
    public function __construct(private readonly Connection $db) {}

    public function attach(int $supplier,array $snapshot):array
    {
        if(($snapshot['legal_profile']['profile']['kind']??null)!=='svj')return$snapshot;
        $ids=[];foreach($snapshot['sources']as$source)$ids[(int)($source['origin']['line_id']??$source['line_id'])]=true;
        if($ids===[])return$snapshot;
        $s=$this->db->pdo()->prepare('SELECT DISTINCT e.case_id,e.receipt_json FROM property_service_lifecycle_events e WHERE e.supplier_id=? AND e.receipt_json IS NOT NULL ORDER BY e.case_id,e.id');$s->execute([$supplier]);$cases=[];
        foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row){$receipt=json_decode($row['receipt_json'],true,512,JSON_THROW_ON_ERROR);if(self::touches($receipt,$ids))$cases[(int)$row['case_id']]=true;}
        $map=[];$owner=new ServiceLifecycleRepository($this->db);
        foreach(array_keys($cases)as$id){
            $state=$owner->reconciliationSources($supplier,$id);
            foreach($state['pending']as$pending)if(($pending['accounting_date']??'')>=$snapshot['period']['starts_on']&&($pending['accounting_date']??'')<=$snapshot['period']['ends_on'])NonprofitTaxRules::invalid('Vyúčtování služeb obsahuje nedokončenou operaci v posuzovaném období.');
            foreach(self::project($state)as$line=>$facts){if(isset($map[$line])&&$map[$line]!==$facts)NonprofitTaxRules::invalid('Řádek služby má více právních vlastníků.');$map[$line]=$facts;}
        }
        foreach($snapshot['sources']as&$source){$line=(int)($source['origin']['line_id']??$source['line_id']);if(isset($map[$line]))$source['svj_owner_facts']=$map[$line];}unset($source);
        return$snapshot;
    }

    public static function project(array $state):array
    {
        if(($state['case']['policy']??null)!=='service_profit_and_loss')return[];
        $case=$state['case'];$base=['owner'=>'property_service_lifecycle','case_id'=>$case['id'],'unit_id'=>$case['unit_id'],'service_id'=>$case['service_id'],'period_from'=>$case['period_from'],'period_to'=>$case['period_to'],'legal_basis'=>'zdp_18_2f_3','case_hash'=>NonprofitTaxRules::hash($case)];$out=[];
        $add=static function(array $ledger,int $event)use(&$out,$base):void{
            foreach($ledger['lines']as$line){$code=$line['account_code'];if(!str_starts_with($code,'5')&&!str_starts_with($code,'6'))continue;
                if(str_starts_with($code,'59')||(str_starts_with($code,'6')&&!str_starts_with($code,'649')))NonprofitTaxRules::invalid('Nativní vyúčtování obsahuje nepodporovaný výsledkový titul.');
                $out[(int)$line['id']]=$base+['event_id'=>$event,'entry_id'=>$ledger['entry']['id'],'line_id'=>$line['id'],'ledger_hash'=>NonprofitTaxRules::hash($ledger),'kind'=>str_starts_with($code,'6')?'revenue':'expense'];
            }
        };
        if($state['estimate']!==null)foreach($state['estimate']['receipt']['sources']as$ledger)$add($ledger,(int)$state['estimate']['event_id']);
        foreach($state['unwinds']as$unwind)$add($unwind['ledger'],(int)($unwind['event_id']??$unwind['id']));
        if($state['final']!==null){$final=$state['final'];$add($final['ledger'],(int)$final['event_id']);$line=$final['invoice']['line'];
            if($final['amount_cents']!==bcmul($line['amount'],'100',0))NonprofitTaxRules::invalid('Právní titul nákladu musí pokrýt přesný původní řádek služby.');
            $out[(int)$line['id']]=$base+['event_id'=>$final['event_id'],'entry_id'=>$final['invoice']['ledger']['entry']['id'],'line_id'=>$line['id'],'ledger_hash'=>NonprofitTaxRules::hash($final['invoice']),'kind'=>'expense'];
        }
        ksort($out,SORT_NUMERIC);return$out;
    }

    private static function touches(array $value,array $ids):bool
    {
        if(isset($value['entry'],$value['lines'])&&is_array($value['entry'])&&is_array($value['lines']))foreach($value['lines']as$line)if(isset($ids[(int)($line['id']??0)]))return true;
        foreach($value as$item)if(is_array($item)&&self::touches($item,$ids))return true;return false;
    }
}
