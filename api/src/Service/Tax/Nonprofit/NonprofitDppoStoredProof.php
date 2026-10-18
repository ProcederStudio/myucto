<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\{DppoReturnCalculator,DppoXmlBuilder,TaxReturnException};
use MyInvoice\Service\Validation\XmlSchemaValidator;

final class NonprofitDppoStoredProof
{
    public static function capture(array $data,array $inputs,array $constants,array $entityProof):array
    {
        $record=['version'=>2,'data'=>$data,'inputs'=>$inputs,'constants'=>$constants,'entity_proof'=>$entityProof];
        self::constantsForRecord($record);
        return$record+['filing_proof_hash'=>NonprofitTaxRules::hash($record)];
    }

    public static function assertCalculation(array $computed,array $record):void
    {
        $hash=$record['filing_proof_hash']??null;unset($record['filing_proof_hash']);
        if(!is_string($hash)||!hash_equals($hash,NonprofitTaxRules::hash($record))||($record['data']['accounting_decree']??null)!=='504')self::invalid();
        $expected=(new DppoReturnCalculator())->compute($record['data'],$record['inputs'],self::constantsForRecord($record));
        if(self::financial($computed)!==self::financial($expected))self::invalid();
    }

    public static function verify(array $snapshot,array $manifest,string $xml):array
    {
        $record=$snapshot['podklady']['nonprofit_calculation']??null;
        if(!is_array($record)||!is_array($record['data']??null)||!is_array($record['inputs']??null)||!is_array($record['constants']??null)||!is_array($record['entity_proof']??null))self::invalid();
        $hash=$record['filing_proof_hash']??null;unset($record['filing_proof_hash']);
        if(!is_string($hash)||!hash_equals($hash,NonprofitTaxRules::hash($record))||($manifest['nonprofit_filing_proof_hash']??null)!==$hash||($record['data']['accounting_decree']??null)!=='504')self::invalid();
        if(NonprofitTaxRules::hash($record['data']['source_manifest']??[])!==($manifest['nonprofit_data_manifest_hash']??null))self::invalid();
        $expected=(new DppoReturnCalculator())->compute($record['data'],$record['inputs'],self::constantsForRecord($record));$actual=$snapshot['computed']??null;if(!is_array($actual)||self::financial($actual)!==self::financial($expected))self::invalid();
        if(($expected['nonprofit']['proof_hash']??null)!==($manifest['nonprofit_proof_hash']??null)||($record['data']['source_manifest']['statements_hash']??null)!==NonprofitTaxRules::hash($record['data']['statements']??[]))self::invalid();
        $previous=libxml_use_internal_errors(true);try{$dom=new \DOMDocument();$loaded=$dom->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}if(!$loaded||(new XmlSchemaValidator())->validate($xml,'dppdp9')['status']!=='passed')self::invalid();
        $xp=new \DOMXPath($dom);$header=$xp->query('/Pisemnost/DPPDP9/VetaD')?->item(0);$form=$xp->query('/Pisemnost/DPPDP9/VetaO')?->item(0);
        if(!$header instanceof \DOMElement||!$form instanceof \DOMElement||$header->getAttribute('uv_vyhl')!=='504')self::invalid();
        if($xp->query('/Pisemnost/DPPDP9/VetaUA|/Pisemnost/DPPDP9/VetaUB|/Pisemnost/DPPDP9/VetaUD|/Pisemnost/DPPDP9/VetaUZ')->length!==0)self::invalid();
        $profile=$expected['nonprofit']['legal_profile']['profile'];$type=in_array($profile['kind'],['narrow_vpp','broad_vpp'],true)?'3':'1';if($header->getAttribute('typ_popldpp')!==$type)self::invalid();
        $period=$record['data']['period'];foreach(['zdobd_od'=>'starts_on','zdobd_do'=>'ends_on']as$attribute=>$key){try{$date=new \DateTimeImmutable($header->getAttribute($attribute));}catch(\Throwable){self::invalid();}if($date->format('Y-m-d')!==$period[$key])self::invalid();}
        foreach($expected['lines']as$line){$attribute=DppoXmlBuilder::LINE_ATTR[$line['line']]??null;if($attribute!==null&&(string)(int)$line['value']!==(string)(int)$form->getAttribute($attribute))self::invalid();}
        $rows=(new NonprofitDppoStatementRows())->build($record['data']['statements']['balance_sheet'],$record['data']['statements']['income_statement']);
        foreach(['UR','US','UT']as$sentence){$actualRows=[];foreach($xp->query('/Pisemnost/DPPDP9/Veta'.$sentence)as$node){$row=[];foreach($node->attributes as$attribute)$row[$attribute->name]=(int)$attribute->value;$actualRows[]=$row;}if(NonprofitTaxRules::hash($actualRows)!==NonprofitTaxRules::hash($rows[$sentence]))self::invalid();}
        return['filing_proof_hash'=>$hash,'nonprofit'=>$expected['nonprofit'],'period'=>$period,'financial'=>self::financial($expected)];
    }

    public static function constantsForRecord(array $record): array
    {
        $constants=$record['constants']??null;if(!is_array($constants))self::invalid();
        $versioned=array_key_exists('version',$record);if($versioned&&$record['version']!==2)self::invalid();
        $keys=['nonprofit_reduction_rate','nonprofit_reduction_min','nonprofit_reduction_max','nonprofit_reduction_university_min','nonprofit_reduction_university_max'];
        $missing=array_diff($keys,array_keys($constants));
        if($missing!==[]){
            $year=$constants['year']??null;$ruleset=$record['data']['nonprofit']['schema']??null;
            if($versioned||count($missing)!==count($keys)||!is_int($year)||!is_string($ruleset)||($record['data']['accounting_decree']??null)!=='504'||($record['data']['period']['fiscal_year']??null)!==$year)self::invalid();
            try{$legacy=\MyInvoice\Service\Tax\TaxConstants::legacyNonprofitReduction($ruleset,$year);}catch(\OutOfRangeException){self::invalid();}
            $constants+=$legacy;
        }
        NonprofitDppoReduction::configuration($constants);
        return$constants;
    }

    private static function financial(array $computed):array
    {
        $lines=[];foreach($computed['lines']??[]as$row){if(!is_array($row)||!is_int($row['line']??null)||!is_numeric($row['value']??null)||isset($lines[$row['line']]))self::invalid();$lines[$row['line']]=(string)(int)$row['value'];}ksort($lines,SORT_NUMERIC);
        return['lines'=>$lines,'tax'=>(string)(int)($computed['tax']??-1),'advances_paid'=>(string)(int)($computed['advances_paid']??-1),'balance_due'=>(string)(int)($computed['balance_due']??-1),'saving'=>$computed['nonprofit']['saving']??null,'filing_obligation'=>$computed['nonprofit']['filing_obligation']??null];
    }
    private static function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_stored_proof_invalid','Finální přiznání 504 nemá úplný shodný důkaz výpočtu a nativního XML. Ověřte původní snapshot.',409);}
}
