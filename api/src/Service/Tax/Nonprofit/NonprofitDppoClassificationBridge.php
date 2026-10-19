<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoClassificationBridge
{
    public function build(array $result,array $documentedLines=[]):array
    {
        if(($result['ruleset']??null)!==NonprofitTaxRules::VERSION||($result['scope']??null)!=='classification_bridge_before_general_adjustments'||!is_array($result['rows']??null))self::invalid();
        foreach($documentedLines as $documented)if(!is_array($documented))self::invalid();
        $lines=array_fill_keys([10,40,100,101,109,110,120],'0');$seen=[];$excluded='0';$costs='0';$details=[];
        foreach($result['rows'] as $row){
            if(!is_array($row))self::invalid();$projection=$this->projectRow($row,$documentedLines[$row['id']??'']??null);$id=$projection['source_id'];if(isset($seen[$id]))self::invalid();$seen[$id]=true;
            $lines[10]=bcadd($lines[10],$projection['line_10_delta'],0);
            if($projection['line']!==null){$lines[$projection['line']]=bcadd($lines[$projection['line']],$projection['line_delta'],0);}
            $excluded=bcadd($excluded,$projection['excluded_delta'],0);$costs=bcadd($costs,$projection['cost_delta'],0);$details[]=$projection['detail'];
        }
        if(array_diff_key($documentedLines,$seen)!==[])self::invalid();
        $this->assertTotals($result,$lines[10],$excluded,$costs);
        foreach($lines as &$amount)$amount=(int)self::cents($this->integer($amount));unset($amount);
        return ['line_cents'=>$lines,'source_lines'=>$details,'external_adjustments'=>$result['external_adjustments']??[],'scope'=>'classification_only','general_adjustments_included'=>false];
    }

    /** @return array{source_id:string,line:?int,line_10_delta:string,line_delta:string,excluded_delta:string,cost_delta:string,taxable:bool,detail:array{source_id:string,line:?int,amount_cents:int}} */
    public function projectRow(array $row,?array $documented=null):array
    {
        $id=$row['id']??null;if(!is_string($id)||$id==='')self::invalid();
        $amount=self::cents($row['amount_cents']??null);$adjustment=self::cents($row['adjustment_cents']??null);
        if(($row['kind']??null)==='expense'){
            $deductible=self::cents($row['deductible_cents']??null);if(bcsub($amount,$deductible,0)!==$adjustment)self::invalid();
            $line=40;$line10=bcsub('0',$amount,0);$lineDelta=$adjustment;$excluded='0';$cost=$adjustment;$taxable=false;
        }elseif(($row['kind']??null)==='revenue'){
            $line=$this->revenueLine($row,$documented);$expected=$line===null?'0':bcsub('0',$amount,0);if($adjustment!==$expected)self::invalid();
            $line10=$amount;$lineDelta=$line===null?'0':$amount;$excluded=$line===null?'0':$amount;$cost='0';
            $taxable=$line===null&&($row['classification']['regime']??null)==='subject'&&($row['classification']['method']??null)==='return'&&$amount!=='0';
        }else self::invalid();
        return['source_id'=>$id,'line'=>$line,'line_10_delta'=>$line10,'line_delta'=>$lineDelta,'excluded_delta'=>$excluded,'cost_delta'=>$cost,'taxable'=>$taxable,
            'detail'=>['source_id'=>$id,'line'=>$line,'amount_cents'=>(int)($line===40?$adjustment:$amount)]];
    }

    public function assertTotals(array $result,string $accounting,string $excluded,string $costs):void
    {
        foreach(['accounting_result_cents'=>$accounting,'excluded_revenue_cents'=>$excluded,'cost_addbacks_cents'=>$costs,
            'assessed_result_cents'=>bcadd(bcsub($accounting,$excluded,0),$costs,0)]as$key=>$value)if(self::cents($result[$key]??null)!==$value)self::invalid();
    }

    private function revenueLine(array $row,?array $documented):?int
    {
        $classification=$row['classification']??[];$regime=$classification['regime']??null;$method=$classification['method']??null;$basis=$classification['legal_basis']??'';if(!is_string($basis))self::invalid();
        if($regime==='subject'&&$method==='return')$line=null;
        elseif($regime==='subject'&&$method==='final_withholding')$line=120;
        elseif($regime==='exempt'&&$method==='none'&&str_starts_with($basis,'zdp_19b_'))$line=109;
        elseif($regime==='exempt'&&$method==='none'&&str_starts_with($basis,'zdp_19_'))$line=110;
        elseif($regime==='out_of_scope'&&$method==='none'&&str_starts_with($basis,'zdp_18a_'))$line=101;
        elseif($regime==='out_of_scope'&&$method==='none'&&str_starts_with($basis,'zdp_18_2f_'))$line=100;
        else{
            if($documented===null||!in_array($documented['line']??null,$regime==='exempt'?[109,110]:($regime==='out_of_scope'?[100,101]:[]),true)||$method!=='none')self::invalid();
            NonprofitTaxRules::evidence($documented['evidence']??null);return$documented['line'];
        }
        if($documented!==null&&($documented['line']??null)!==$line)self::invalid();
        return$line;
    }

    private function integer(string $amount):int
    {
        if(bccomp($amount,'9999999999999900',0)>0||bccomp($amount,'-9999999999999900',0)<0)self::invalid();return(int)$amount;
    }

    private static function cents(mixed $amount):string
    {
        if(!is_int($amount)||$amount>9999999999999900||$amount< -9999999999999900)self::invalid();return(string)$amount;
    }

    private static function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_bridge_invalid','Daňový můstek vyžaduje úplné a vzájemně shodné posouzení zdrojových řádků a jejich řádků přiznání.',422);}
}
