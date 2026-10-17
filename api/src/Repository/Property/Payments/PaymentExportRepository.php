<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentException};
use MyInvoice\Service\Property\Payments\Workflow\PaymentExportPlan;
use PDO;

final class PaymentExportRepository
{
    public function __construct(private readonly Connection $db,private readonly PaymentRepository $payments) {}
    public function preview(int $supplier,array $request):array
    {
        return $this->transaction($supplier,function()use($supplier,$request):array{$snapshot=$this->plan($supplier,$request);return ['preview_hash'=>PropertyInput::hash($snapshot),'snapshot'=>$snapshot];});
    }
    public function confirm(int $supplier,array $request,string $hash,string $token,int $actor):array
    {
        PropertyInput::code($token,'idempotency_key');PropertyInput::id($actor,'actor');
        return $this->transaction($supplier,function()use($supplier,$request,$hash,$token,$actor):array{
            $requestHash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);
            $old=$this->one('SELECT * FROM unit_payment_exports WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){if(!hash_equals($old['request_hash'],$requestHash)||!hash_equals($old['snapshot_hash'],$hash))PaymentException::reject('idempotency_conflict');return $this->result($supplier,$old);}
            $snapshot=$this->plan($supplier,$request);if(!hash_equals($hash,PropertyInput::hash($snapshot)))PaymentException::reject('preview_stale');$bytes=PaymentExportPlan::bytes($snapshot);
            $this->execute('INSERT INTO unit_payment_exports (supplier_id,request_json,snapshot_json,request_hash,snapshot_hash,content_base64,content_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,self::json($request),self::json($snapshot),$requestHash,$hash,base64_encode($bytes),hash('sha256',$bytes),$token,$actor]);$id=(int)$this->db->pdo()->lastInsertId();
            foreach($snapshot['reservations'] as $reservation){
                $this->execute('INSERT INTO unit_payment_export_items (supplier_id,export_id,reservation_id,amount_cents) VALUES (?,?,?,?)',[$supplier,$id,$reservation['id'],$reservation['amount_cents']]);
                $this->cas("UPDATE unit_credit_reservations SET status='submitted',row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status='reserved'",[$supplier,$reservation['id'],$reservation['row_version']]);
                $this->audit($supplier,$reservation['id'],'submitted',['export_id'=>$id,'snapshot_hash'=>$hash,'amount_cents'=>$reservation['amount_cents']],$actor);
            }
            return $this->result($supplier,$this->one('SELECT * FROM unit_payment_exports WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]));
        });
    }
    public function cancelSubmission(int $supplier,int $id,int $expected,string $reason,string $evidence,string $token,int $actor):array
    {
        PropertyInput::id($actor,'actor');PropertyInput::code($token,'idempotency_key');PropertyInput::text($reason,'reason',4000);PropertyInput::text($evidence,'evidence',4000);
        return $this->transaction($supplier,function()use($supplier,$id,$expected,$reason,$evidence,$token,$actor):array{
            $payload=['expected_version'=>$expected,'reason'=>$reason,'evidence'=>$evidence,'token'=>$token,'actor'=>$actor,'kind'=>'submission_cancelled'];
            foreach($this->all("SELECT payload_json,payload_hash FROM unit_payment_audit WHERE supplier_id=? AND object_kind='reservation' AND object_id=? AND action='cancelled' ORDER BY id FOR UPDATE",[$supplier,$id]) as $event){$fact=self::decode($event['payload_json']);self::hash($event['payload_hash'],$fact);if(($fact['token']??null)===$token){if(PropertyInput::hash($fact)!==PropertyInput::hash($payload))PaymentException::reject('idempotency_conflict');return $this->payments->reservation($supplier,$id);}}
            $reservation=$this->payments->reservation($supplier,$id);$credit=$this->payments->credit($supplier,$reservation['credit_id']);
            if($reservation['row_version']!==$expected)PaymentException::reject('reservation_version_conflict');
            if($reservation['status']!=='submitted')PaymentException::reject('reservation_not_submitted');
            $export=$this->one('SELECT e.* FROM unit_payment_exports e JOIN unit_payment_export_items i ON i.supplier_id=e.supplier_id AND i.export_id=e.id WHERE i.supplier_id=? AND i.reservation_id=? FOR UPDATE',[$supplier,$id]);if(!$export)PaymentException::reject('export_receipt_missing');$this->result($supplier,$export);
            $this->cas("UPDATE unit_credit_reservations SET status='cancelled',row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status='submitted'",[$supplier,$id,$expected]);
            $this->cas('UPDATE unit_credit_lots SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$supplier,$credit['id'],$credit['row_version']]);
            $this->audit($supplier,$id,'cancelled',$payload,$actor);return $this->payments->reservation($supplier,$id);
        });
    }
    public function find(int $supplier,int $id):array
    {
        return $this->transaction($supplier,function()use($supplier,$id):array{$row=$this->one('SELECT * FROM unit_payment_exports WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row)PaymentException::reject('export_not_found');return $this->result($supplier,$row);});
    }
    public function reservationStatusAfterReversal(int $supplier,array $reservation):string
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Reservation restoration requires current payment context.');
        $row=$this->one('SELECT e.* FROM unit_payment_exports e JOIN unit_payment_export_items i ON i.supplier_id=e.supplier_id AND i.export_id=e.id WHERE i.supplier_id=? AND i.reservation_id=? FOR UPDATE',[$supplier,$reservation['id']]);
        if($row)$this->result($supplier,$row);
        return $reservation['status']==='cancelled'?'cancelled':($row!==null?'submitted':'reserved');
    }
    private function plan(int $supplier,array $request):array
    {
        PropertyInput::fields($request,['reservation_ids','expected_versions','bank_account_id','due_date','reason','payment_symbols']);
        $ids=PropertyInput::list($request['reservation_ids']??null,'reservation_ids');foreach($ids as $id)PropertyInput::id($id,'reservation_id');
        if($ids===[]||count($ids)>100||count(array_unique($ids))!==count($ids))PropertyInput::invalid('reservation_ids');sort($ids,SORT_NUMERIC);
        if(!is_array($request['expected_versions']??null)||!is_array($request['payment_symbols']??null))PropertyInput::invalid('expected_versions');
        foreach(['expected_versions','payment_symbols'] as $field){$keys=array_map('intval',array_keys($request[$field]));sort($keys,SORT_NUMERIC);if($keys!==$ids)PropertyInput::invalid($field);}
        $date=\MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest::date($request['due_date']??null);PropertyInput::text($request['reason']??null,'reason',4000);
        $bank=$this->one('SELECT id,label,account_number,bank_code,currency,kind,is_active FROM supplier_bank_accounts WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,PropertyInput::id($request['bank_account_id']??null,'bank_account_id')]);
        if(!$bank||!(bool)$bank['is_active']||$bank['currency']!=='CZK'||$bank['kind']==='credit_card')PaymentException::reject('abo_account_setup_required');
        $payer=['id'=>(int)$bank['id'],'label'=>$bank['label'],'account_number'=>$bank['account_number'],'bank_code'=>$bank['bank_code'],'currency'=>$bank['currency']];
        $reservations=[];$total='0';
        foreach($ids as $id){
            $row=$this->payments->reservation($supplier,$id);$credit=$this->payments->credit($supplier,$row['credit_id']);
            if($row['row_version']!==($request['expected_versions'][$id]??null))PaymentException::reject('reservation_version_conflict');
            if($row['status']!=='reserved'||($row['request']['delivery_method']??'bank')!=='bank')PaymentException::reject('reservation_not_exportable');
            if($this->one('SELECT id FROM unit_payment_export_items WHERE supplier_id=? AND reservation_id=? FOR UPDATE',[$supplier,$id]))PaymentException::reject('reservation_already_exported');
            $symbols=$request['payment_symbols'][$id];if(!is_array($symbols))PropertyInput::invalid('payment_symbols');PropertyInput::fields($symbols,['variable_symbol','constant_symbol','specific_symbol','allow_missing_variable_symbol','message']);
            if(isset($symbols['message']))PropertyInput::text($symbols['message'],'message',4000);
            $amount=bcsub($row['amount_cents'],$row['paid_cents'],0);$total=bcadd($total,$amount,0);
            $reservations[]=['id'=>$id,'credit_id'=>$row['credit_id'],'row_version'=>$row['row_version'],'paid_cents'=>$row['paid_cents'],'amount_cents'=>$amount,'request'=>$row['request'],'request_hash'=>$row['request_hash'],'credit_snapshot_hash'=>PropertyInput::hash($credit['snapshot'])];
        }
        $snapshot=['request'=>$request,'payer'=>$payer,'reservations'=>$reservations,'total_cents'=>$total];PaymentExportPlan::bytes($snapshot);return $snapshot;
    }
    private function result(int $supplier,array $row):array
    {
        $request=self::decode($row['request_json']);$snapshot=self::decode($row['snapshot_json']);self::hash($row['request_hash'],['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);self::hash($row['snapshot_hash'],$snapshot);
        if(PropertyInput::hash($snapshot['request'])!==PropertyInput::hash($request))PaymentException::reject('history_corrupt');
        $bytes=base64_decode($row['content_base64'],true);if($bytes===false||!hash_equals($row['content_hash'],hash('sha256',$bytes))||$bytes!==PaymentExportPlan::bytes($snapshot))PaymentException::reject('export_hash_mismatch');
        $items=$this->all('SELECT reservation_id,amount_cents FROM unit_payment_export_items WHERE supplier_id=? AND export_id=? ORDER BY reservation_id FOR UPDATE',[$supplier,$row['id']]);
        if(count($items)!==count($snapshot['reservations']))PaymentException::reject('export_receipt_mismatch');$states=[];
        foreach($snapshot['reservations'] as $index=>$fact){$item=$items[$index];if((int)$item['reservation_id']!==$fact['id']||$item['amount_cents']!==$fact['amount_cents'])PaymentException::reject('export_receipt_mismatch');
            $current=$this->payments->reservation($supplier,$fact['id']);
            $credit=$this->payments->credit($supplier,$fact['credit_id']);if(PropertyInput::hash($credit['snapshot'])!==$fact['credit_snapshot_hash'])PaymentException::reject('export_receipt_mismatch');
            if($current['credit_id']!==$fact['credit_id']||$current['request_hash']!==$fact['request_hash']||bcadd($fact['paid_cents'],$fact['amount_cents'],0)!==$current['amount_cents']||$current['row_version']<=$fact['row_version'])PaymentException::reject('export_receipt_mismatch');$states[$fact['id']]=$current['status'];
        }
        return ['id'=>(int)$row['id'],'filename'=>'vratky-'.$row['id'].'.kpc','content_base64'=>$row['content_base64'],'encoding'=>'windows-1250','reservation_ids'=>array_column($snapshot['reservations'],'id'),'reservation_statuses'=>$states];
    }
    private function transaction(int $supplier,callable $work):mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    private function audit(int $supplier,int $id,string $action,array $payload,int $actor):void {$this->execute("INSERT INTO unit_payment_audit (supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,'reservation',?,?,?,?,?,UTC_TIMESTAMP(6))",[$supplier,$id,$action,self::json($payload),PropertyInput::hash($payload),$actor]);}
    private function execute(string $sql,array $params):\PDOStatement {$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q;}
    private function one(string $sql,array $params):?array {return $this->execute($sql,$params)->fetch(PDO::FETCH_ASSOC)?:null;}
    private function all(string $sql,array $params):array {return $this->execute($sql,$params)->fetchAll(PDO::FETCH_ASSOC);}
    private function cas(string $sql,array $params):void {if($this->execute($sql,$params)->rowCount()!==1)PaymentException::reject('version_conflict');}
    private static function json(array $v):string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private static function decode(string $v):array {return json_decode($v,true,flags:JSON_THROW_ON_ERROR);}
    private static function hash(string $hash,array $v):void {if(!hash_equals($hash,PropertyInput::hash($v)))PaymentException::reject('history_corrupt');}
}
