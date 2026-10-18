<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\{AccountingFrameworkService,JournalLineAmount};
use MyInvoice\Service\Accounting\Closing\{SecuritiesDebt504Terms as T,SecuritiesDebt504PaymentProof,ClosingException};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use PDO;

final class SecuritiesDebt504Repository
{
    public function __construct(private readonly Connection $db,private readonly bool $currentRead=true) {}

    public function transaction(int $supplier,callable $work): mixed
    {
        T::need($supplier>0,'debt_tenant_required');
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier,$work) {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
            $this->rows('SELECT id FROM supplier WHERE id=? FOR UPDATE',[$supplier]);
            return $work();
        });
    }

    public function acquisition(int $supplier,array $terms,int $entryId,int $lineId): array
    {
        $terms=T::normalize($terms); $this->assertFramework($supplier,$terms['acquired_on']);
        $ledger=(new PaymentJournalProof($this->db))->current($supplier,$entryId);
        T::need($ledger['entry']['entry_date']===$terms['acquired_on'] && $ledger['entry']['reversed_by']===null && !in_array($ledger['entry']['source_type'],['closing','opening'],true),'debt_acquisition_required');
        $matches=array_values(array_filter($ledger['lines'],fn($line)=>$line['id']===$lineId));
        T::need(count($matches)===1,'debt_acquisition_required');$line=$matches[0];
        $prefix=$terms['classification']==='held_to_maturity'?'063':'253';
        T::need(str_starts_with($line['account_code'],$prefix) && $line['side']==='debit' && !$line['is_red_storno'] && $line['dimension_splits']===[],'debt_acquisition_required');
        $this->account($supplier,$line['account_code'],'asset');
        $currency=$line['currency_code']??'CZK';
        $dirty=bcadd($terms['clean_cost'],$terms['acquired_interest'],2);
        T::need(($currency==='CZK'?$line['amount']:$line['amount_foreign'])===$dirty,'debt_acquisition_partition_required');
        foreach (['clean_cost_czk','acquired_interest_czk'] as $key) { \MyInvoice\Service\Accounting\Closing\SecuritiesDebt504Policy::decimal($terms[$key]??null);$terms[$key]=JournalLineAmount::decimal($terms[$key]); }
        T::need(bcadd($terms['clean_cost_czk'],$terms['acquired_interest_czk'],2)===$line['amount'],'debt_acquisition_partition_required');
        if($currency==='CZK')T::need($terms['clean_cost_czk']===$terms['clean_cost'] && $terms['acquired_interest_czk']===$terms['acquired_interest'],'debt_acquisition_partition_required');
        else T::need(is_string($line['fx_rate']) && $terms['acquired_interest_czk']===JournalLineAmount::decimal(bcmul($terms['acquired_interest'],$line['fx_rate'],16)),'debt_acquisition_partition_required');
        $this->available($supplier,$lineId,'acquisition');
        return ['terms'=>$terms,'acquisition'=>$ledger,'asset'=>$line,'currency'=>$currency];
    }

    public function register(int $supplier,array $facts,int $actor): int
    {
        $terms=$facts['terms'];
        T::need($this->rows('SELECT id FROM accounting_securities_debt_lots WHERE supplier_id=? AND lot_reference=? FOR UPDATE',[$supplier,$terms['lot_reference']])===[],'debt_lot_exists');
        $q=$this->db->pdo()->prepare('INSERT INTO accounting_securities_debt_lots(supplier_id,lot_reference,acquired_on,maturity_on,facts_json,facts_hash,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$supplier,$terms['lot_reference'],$terms['acquired_on'],$terms['maturity_on'],self::json($facts),self::hash($facts),$actor]);
        $id=(int)$this->db->pdo()->lastInsertId();$this->claim($supplier,$id,null,$facts['asset']['id'],'acquisition',$facts['asset']['amount']);return $id;
    }

    public function current(int $supplier,int $lot,string $date,bool $inventory=false): array
    {
        $rows=$this->rows('SELECT * FROM accounting_securities_debt_lots WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$lot]);
        T::need(count($rows)===1,'debt_lot_not_found');$row=$rows[0];$facts=self::decode($row['facts_json']);$terms=T::normalize($facts['terms']);
        T::need(self::hash($facts)===$row['facts_hash'] && $terms['lot_reference']===$row['lot_reference'] && $terms['acquired_on']===$row['acquired_on'] && $terms['maturity_on']===$row['maturity_on'],'debt_lot_corrupt');
        $this->assertLedger($supplier,$facts['acquisition']);
        $asset=array_values(array_filter($facts['acquisition']['lines'],fn($line)=>$line['id']===$facts['asset']['id']));
        T::need(count($asset)===1 && $asset[0]===$facts['asset'] && bcadd($terms['clean_cost_czk'],$terms['acquired_interest_czk'],2)===$facts['asset']['amount'],'debt_lot_corrupt');
        T::need($date>=$terms['acquired_on'],'debt_event_date_invalid');
        $claims=$this->rows('SELECT line_id,component,amount_cents,event_id FROM accounting_securities_debt_claims WHERE supplier_id=? AND lot_id=? ORDER BY line_id,component FOR UPDATE',[$supplier,$lot]);
        $expectedClaims=[[$facts['asset']['id'],'acquisition',bcmul($facts['asset']['amount'],'100',0),null]];
        $events=$this->rows('SELECT * FROM accounting_securities_debt_events WHERE supplier_id=? AND lot_id=? ORDER BY accounting_date,id FOR UPDATE',[$supplier,$lot]);
        $fxAll=(new SecuritiesDebt504FxCarryRepository($this->db,$this->currentRead))->read($supplier,$lot);
        $fx=array_values(array_filter($fxAll,fn($r)=>$r['accounting_date']<=$date && (!$inventory||$r['accounting_date']!==$date)));$fxCursor=0;
        $eventIndex=array_column($events,null,'id');
        $reversals=[];foreach($events as $event)if($event['reversal_of']!==null)$reversals[(int)$event['reversal_of']]=$event;
        $state=['id'=>$lot,'facts'=>$facts,'earned'=>'0.00','amortised'=>'0.00','coupon_received'=>'0.00','principal_redeemed'=>'0.00','valuation_czk'=>'0.00','book_czk'=>$facts['asset']['amount'],'principal_book_czk'=>$terms['clean_cost_czk'],'interest_book_czk'=>$terms['acquired_interest_czk'],'principal_book_native'=>$terms['clean_cost'],'latest_date'=>$terms['acquired_on'],'max_event_date'=>$terms['acquired_on'],'events'=>[]];
        $applyFx=function(string $until,bool $inclusive=false)use(&$state,&$fxCursor,$fx):void{while(isset($fx[$fxCursor])&&($fx[$fxCursor]['accounting_date']<$until||($inclusive&&$fx[$fxCursor]['accounting_date']===$until))){$r=$fx[$fxCursor++];$state['principal_book_czk']=bcadd($state['principal_book_czk'],$r['principal_czk'],2);$state['interest_book_czk']=bcadd($state['interest_book_czk'],$r['interest_czk'],2);$state['book_czk']=bcadd($state['book_czk'],bcadd($r['principal_czk'],$r['interest_czk'],2),2);}};
        foreach($events as $row) {
            $request=self::decode($row['request_json']);$receipt=self::decode($row['receipt_json']);$id=(int)$row['id'];
            $state['max_event_date']=max($state['max_event_date'],$row['accounting_date']);
            T::need(self::hash($request)===$row['request_hash'] && self::hash($receipt)===$row['receipt_hash'] && $request['lot_id']===$lot && $request['operation']===$row['operation'] && $request['accounting_date']===$row['accounting_date'],'debt_event_corrupt');
            $proof=$receipt['ledger']??null;$reverse=$reversals[$id]??null;
            if($proof!==null) {
                $expected=$proof;
                if($reverse!==null) { $mirror=self::decode($reverse['receipt_json']);$expected['entry']['reversed_by']=$mirror['ledger']['entry']['id']; }
                $this->assertLedger($supplier,$expected);
                T::need($proof['entry']['id']===(int)$row['journal_entry_id'] && $proof['entry']['entry_date']===$row['accounting_date'],'debt_event_corrupt');
                $change='0.00';$native='0.00';foreach($proof['lines'] as $line)if($line['account_code']===$facts['asset']['account_code']){
                    T::need($line['dimensions']===$facts['asset']['dimensions'] && $line['dimension_splits']===$facts['asset']['dimension_splits'],'debt_tuple_changed');
                    $sign=$line['side']==='debit'?'1':'-1';$change=bcadd($change,bcmul($line['amount'],$sign,2),2);$native=bcadd($native,bcmul($facts['currency']==='CZK'?$line['amount']:($line['amount_foreign']??'0'),$sign,2),2);
                }
                T::need($change===$receipt['book_change_czk'],'debt_event_corrupt');
                if($row['operation']!=='reverse')T::need(PaymentJournalProof::canonicalLines($proof['lines'])===PaymentJournalProof::canonicalLines($receipt['lines']),'debt_event_corrupt');
                if($row['operation']==='valuation')T::need($receipt['amount']===$change,'debt_event_corrupt');
                if($row['operation']==='reverse'){
                    $original=$eventIndex[$row['reversal_of']]??null;T::need($original!==null && ($request['event_id']??null)===(int)$row['reversal_of'],'debt_event_corrupt');
                    $old=self::decode($original['receipt_json']);T::need(isset($old['ledger']) && !isset($old['payment']) && $receipt['amount']===$old['amount'] && $receipt['book_change_czk']===bcsub('0',$old['book_change_czk'],2),'debt_event_corrupt');
                }
                if(in_array($row['operation'],['accrual','amortisation'],true))T::need($native===$receipt['amount'],'debt_event_corrupt');
            }
            if(in_array($row['operation'],['coupon_receipt','principal_redemption'],true)) {
                $saved=$receipt['payment'];$payment=(new SecuritiesDebt504PaymentProof($this->db,$this->currentRead))->current($supplier,$saved['ledger']['entry']['id'],$saved['line']['id'],$facts['asset']);
                T::need(self::hash($payment)===self::hash($saved),'debt_payment_source_changed');
                T::need($receipt['amount']===($row['operation']==='principal_redemption'?($request['nominal_amount']??null):$payment['amount']) && bccomp($receipt['amount'],'0',2)>0 && bccomp($receipt['amount'],$payment['money_amount'],2)<=0 && $receipt['book_change_czk']===bcsub('0',$payment['line']['amount'],2) && $payment['date']===$row['accounting_date'],'debt_event_corrupt');
                $this->assertPaymentCapacity($supplier,$payment,'0.00');
                $expectedClaims[]=[$saved['line']['id'],$row['operation'],bcmul($saved['line']['amount'],'100',0),$id];
            }
            if($row['accounting_date']>$date)continue;
            $applyFx($row['accounting_date']);
            $state['latest_date']=max($state['latest_date'],$row['accounting_date']);
            if($row['operation']==='reverse')continue;
            if($reverse!==null && $reverse['accounting_date']<=$date)continue;
            if($row['operation']==='valuation'){
                $quote=$request['quote'];\MyInvoice\Service\Accounting\Closing\SecuritiesValuation504Evidence::assertMarketBasis($quote+['quote_currency'=>$quote['currency']??null],$row['accounting_date'],$facts['currency']);
                T::need(($quote['quote_type']??null)==='clean' && ($quote['as_of']??null)===$row['accounting_date'] && ($quote['currency']??null)===$facts['currency'],'debt_clean_quote_required');
                $target=JournalLineAmount::decimal(bcmul($quote['clean_value'],$receipt['rate']['rate'],16));T::need($receipt['book_change_czk']===bcsub($target,$state['principal_book_czk'],2),'debt_event_corrupt');
            }
            if($row['operation']==='accrual')T::need($receipt['amount']===bcsub(T::earned($terms,$row['accounting_date']),$state['earned'],2),'debt_accrual_corrupt');
            if($row['operation']==='amortisation')T::need($receipt['amount']===bcsub(T::amortised($terms,$row['accounting_date']),$state['amortised'],2),'debt_amortisation_corrupt');
            $amount=$receipt['amount'];$field=match($row['operation']){'accrual'=>'earned','amortisation'=>'amortised','coupon_receipt'=>'coupon_received','principal_redemption'=>'principal_redeemed','valuation'=>'valuation_czk'};
            $state[$field]=bcadd($state[$field],$amount,2);
            $state['book_czk']=bcadd($state['book_czk'],$receipt['book_change_czk'],2);
            $interest=in_array($row['operation'],['accrual','coupon_receipt'],true);
            $bookField=$interest?'interest_book_czk':'principal_book_czk';
            $state[$bookField]=bcadd($state[$bookField],$receipt['book_change_czk'],2);
            if(!$interest && ($row['operation']!=='valuation'||$facts['currency']==='CZK'))$state['principal_book_native']=bcadd($state['principal_book_native'],isset($receipt['payment'])?bcsub('0',$receipt['payment']['amount'],2):$amount,2);
            $state['events'][]=['id'=>$id,'request'=>$request,'receipt'=>$receipt];
        }
        $applyFx($date,true);
        foreach($fxAll as $r){
            $before=array_values(array_filter($fxAll,fn($prior)=>$prior['accounting_date']<$r['accounting_date']));
            if($r['slot']==='saldo')T::need($r['basis']['terms']===$terms && $r['basis']['lines']===self::partsAt($facts,$events,$r['accounting_date'],$before),'debt_fx_basis_changed');
            else{$original=array_values(array_filter($fxAll,fn($prior)=>$prior['period_id']===$r['period_id']&&$prior['slot']==='saldo'));T::need(count($original)===1 && $r['principal_czk']===bcsub('0',$original[0]['principal_czk'],2) && $r['interest_czk']===bcsub('0',$original[0]['interest_czk'],2),'debt_fx_carry_corrupt');}
        }
        foreach($fxAll as $r)foreach($r['lines'] as $lineId=>$part){
            $actual=array_values(array_filter($r['ledger']['lines'],fn($line)=>$line['id']===(int)$lineId));T::need(count($actual)===1,'debt_fx_carry_corrupt');$line=$actual[0];
            foreach(['account_code','currency_code','dimensions','dimension_splits','cost_center','project_id'] as $field)T::need(($line[$field]??null)===($facts['asset'][$field]??null),'debt_fx_carry_corrupt');
            T::need($r['origin']===['kind'=>'declared_title','origin_line_id'=>$facts['asset']['id'],'reference'=>$terms['contract_reference']],'debt_fx_carry_corrupt');
            $expectedClaims[]=[(int)$lineId,'fx_carry',bcmul($line['amount'],'100',0),null];
        }
        $state['fx_carries']=$fx;
        usort($expectedClaims,fn($a,$b)=>[$a[0],$a[1]]<=>[$b[0],$b[1]]);
        $actual=array_map(fn($c)=>[(int)$c['line_id'],$c['component'],$c['amount_cents'],$c['event_id']===null?null:(int)$c['event_id']],$claims);
        T::need($actual===$expectedClaims,'debt_claims_corrupt');
        $state['unpaid_interest']=bcsub(bcadd($terms['acquired_interest'],$state['earned'],2),$state['coupon_received'],2);
        $state['principal_balance']=bcsub($terms['nominal'],$state['principal_redeemed'],2);
        foreach(['unpaid_interest','principal_balance','interest_book_czk','principal_book_czk','principal_book_native'] as $field)T::need(bccomp($state[$field],'0',2)>=0,'debt_negative_balance');
        $state['hash']=self::hash([$facts,$events,$claims,$fxAll]);return $state;
    }

    public function event(int $supplier,int $lot,array $request,array $receipt,?int $reversal=null): int
    {
        $entry=$receipt['ledger']['entry']['id']??null;
        $q=$this->db->pdo()->prepare('INSERT INTO accounting_securities_debt_events(supplier_id,lot_id,operation,accounting_date,request_json,request_hash,receipt_json,receipt_hash,journal_entry_id,reversal_of,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$supplier,$lot,$request['operation'],$request['accounting_date'],self::json($request),self::hash($request),self::json($receipt),self::hash($receipt),$entry,$reversal]);
        $id=(int)$this->db->pdo()->lastInsertId();
        if(isset($receipt['payment']))$this->claim($supplier,$lot,$id,$receipt['payment']['line']['id'],$request['operation'],$receipt['payment']['line']['amount']);
        return $id;
    }

    public function closingProof(int $supplier,string $date): array
    {
        $out=['lots'=>[],'findings'=>[],'origins'=>[]];
        foreach($this->rows('SELECT id FROM accounting_securities_debt_lots WHERE supplier_id=? AND acquired_on<=? ORDER BY id FOR UPDATE',[$supplier,$date]) as $lot){
            $state=$this->current($supplier,(int)$lot['id'],$date,true);$facts=$state['facts'];$terms=$facts['terms'];
            if($state['earned']!==T::earned($terms,$date))$out['findings'][]=['code'=>'debt_accrual_required','lot_id'=>$state['id']];
            if($terms['classification']==='held_to_maturity' && $state['amortised']!==T::amortised($terms,$date))$out['findings'][]=['code'=>'debt_amortisation_required','lot_id'=>$state['id']];
            $raw=$this->rows('SELECT * FROM accounting_securities_debt_events WHERE supplier_id=? AND lot_id=? ORDER BY accounting_date,id FOR UPDATE',[$supplier,$state['id']]);
            $lines=self::partsAt($facts,$raw,$date,$state['fx_carries']);$valued=null;foreach($state['events'] as $event)if($event['request']['operation']==='valuation')$valued=$event['request']['accounting_date'];
            foreach($lines as $lineId=>$part)$out['origins'][$lineId]=[['kind'=>'declared_title','origin_line_id'=>$facts['asset']['id'],'reference'=>$terms['contract_reference']]];
            $out['lots'][]=['id'=>$state['id'],'origin'=>['kind'=>'declared_title','origin_line_id'=>$facts['asset']['id'],'reference'=>$terms['contract_reference']],'terms'=>$terms,'currency'=>$facts['currency'],'lines'=>$lines,'valued_on'=>$valued,'principal_balance'=>$state['principal_balance'],'unpaid_interest'=>$state['unpaid_interest'],'book_czk'=>$state['book_czk']];
        }
        return $out;
    }

    public static function partsAt(array $facts,array $events,string $date,array $fx=[]): array
    {
        $terms=$facts['terms'];$lines=[$facts['asset']['id']=>['principal_czk'=>$terms['clean_cost_czk'],'principal_native'=>$terms['clean_cost'],'interest_czk'=>$terms['acquired_interest_czk'],'interest_native'=>$terms['acquired_interest']]];
        $reversed=[];foreach($events as $row)if($row['reversal_of']!==null&&$row['accounting_date']<=$date)$reversed[(int)$row['reversal_of']]=true;
        foreach($events as $row){
            if($row['accounting_date']>$date||$row['operation']==='reverse'||isset($reversed[(int)$row['id']]))continue;
            $r=self::decode($row['receipt_json']);$op=$row['operation'];$interest=in_array($op,['accrual','coupon_receipt'],true);$native=$op==='valuation'?'0.00':$r['amount'];
            if(isset($r['payment'])){$line=$r['payment']['line'];$native=bcsub('0',$r['payment']['amount'],2);}
            else{$asset=array_values(array_filter($r['ledger']['lines']??[],fn($line)=>$line['account_code']===$facts['asset']['account_code']));T::need(count($asset)===1,'debt_event_corrupt');$line=$asset[0];}
            $lines[$line['id']]=['principal_czk'=>$interest?'0.00':$r['book_change_czk'],'principal_native'=>$interest?'0.00':$native,'interest_czk'=>$interest?$r['book_change_czk']:'0.00','interest_native'=>$interest?$native:'0.00'];
        }
        foreach($fx as $r)if($r['accounting_date']<=$date)foreach($r['lines'] as $id=>$part)$lines[$id]=$part;
        ksort($lines,SORT_NUMERIC);return $lines;
    }

    private function assertLedger(int $supplier,array $proof): void
    {
        $actual=(new PaymentJournalProof($this->db))->snapshot($supplier,$proof['entry']['id'],$this->currentRead);
        if(self::hash($actual)!==self::hash($proof))\MyInvoice\Service\Property\Payments\PaymentException::reject('ledger_mismatch');
    }

    public function assertFramework(int $supplier,string $date): void
    {
        T::need((new AccountingFrameworkService($this->db,new AccountingFrameworkRepository($this->db)))->forDateForUpdate($supplier,$date)==='cz_504','debt_framework_required');
    }

    public function account(int $supplier,string $code,string $type): void
    {
        $rows=$this->rows('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=? AND account_type=? AND is_active=1 FOR UPDATE',[$supplier,$code,$type]);
        T::need(count($rows)===1,'debt_account_required');
    }

    public function available(int $supplier,int $line,string $component): void
    {
        $rows=$this->rows('SELECT lot_id FROM accounting_securities_debt_claims WHERE supplier_id=? AND line_id=? FOR UPDATE',[$supplier,$line]);
        T::need($rows===[],'debt_source_used_twice');
    }

    public function assertPaymentCapacity(int $supplier,array $payment,string $amount): void
    {
        $used='0.00';
        foreach($this->rows("SELECT e.operation,e.request_json,e.request_hash,e.receipt_json,e.receipt_hash FROM accounting_securities_debt_events e WHERE e.supplier_id=? AND e.operation IN ('coupon_receipt','principal_redemption') ORDER BY e.id FOR UPDATE",[$supplier]) as $event){
            $receipt=self::decode($event['receipt_json']);$saved=$receipt['payment']??null;
            if(($saved['ledger']['entry']['id']??null)!==$payment['ledger']['entry']['id'])continue;
            $request=self::decode($event['request_json']);
            T::need(self::hash($receipt)===$event['receipt_hash'] && self::hash($request)===$event['request_hash'],'debt_event_corrupt');
            $actual=(new SecuritiesDebt504PaymentProof($this->db,$this->currentRead))->current($supplier,$saved['ledger']['entry']['id'],$saved['line']['id'],$saved['line']);
            T::need(self::hash($saved)===self::hash($actual) && $receipt['amount']===($event['operation']==='principal_redemption'?($request['nominal_amount']??null):$actual['amount']) && bccomp($receipt['amount'],'0',2)>0,'debt_event_corrupt');
            $used=bcadd($used,$receipt['amount'],2);
        }
        T::need(bccomp(bcadd($used,$amount,2),$payment['money_amount'],2)<=0,'debt_payment_capacity_exceeded');
    }

    public function page(int $supplier,int $after=0): array
    {
        return $this->transaction($supplier,fn()=> $this->rows('SELECT id,lot_reference,acquired_on,maturity_on FROM accounting_securities_debt_lots WHERE supplier_id=? AND id>? ORDER BY id LIMIT 51',[$supplier,$after]));
    }

    private function claim(int $supplier,int $lot,?int $event,int $line,string $component,string $amount): void
    {
        $this->available($supplier,$line,$component);
        $q=$this->db->pdo()->prepare('INSERT INTO accounting_securities_debt_claims(supplier_id,lot_id,event_id,line_id,component,amount_cents) VALUES(?,?,?,?,?,?)');$q->execute([$supplier,$lot,$event,$line,$component,bcmul($amount,'100',0)]);
    }

    private function rows(string $sql,array $args): array { if(!$this->currentRead)$sql=str_replace(' FOR UPDATE','',$sql);$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC); }
    public static function json(array $value): string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public static function hash(array $value): string {return hash('sha256',self::json($value));}
    public static function decode(string $value): array {return json_decode($value,true,flags:JSON_THROW_ON_ERROR);}
}