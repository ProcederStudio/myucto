<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyDimensionPolicy;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceLocks;
use MyInvoice\Service\Property\Payments\{PaymentException,PaymentLocks,PaymentSourceProof,PaymentJournalProof,StatutoryAllocationPolicy};
use PDO;

final class PaymentRepository
{
    public function __construct(private readonly Connection $db,private readonly PostingService $posting) {}

    public function preview(int $supplier,array $request): array
    {
        return $this->transaction($supplier,fn()=> $this->plan($supplier,$request));
    }

    public function previewClearing(int $supplier,array $request,\MyInvoice\Service\Property\Payments\Workflow\PaymentSourcePreview $source):array
    {
        return $this->transaction($supplier,fn()=> $this->plan($supplier,$request,$source));
    }

    public function apply(int $supplier,array $request,string $previewHash,string $token,int $actor): array
    {
        PropertyInput::code($token,'idempotency_key');
        return $this->transaction($supplier,function()use($supplier,$request,$previewHash,$token,$actor):array {
            $hash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);
            $old=$this->one('SELECT id FROM unit_payment_operations WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old) {
                $operation=$this->operationLocked($supplier,(int)$old['id']);
                if(!hash_equals($operation['request_hash'],$hash)||!hash_equals($operation['snapshot_hash'],$previewHash))PaymentException::reject('idempotency_conflict');
                return $operation;
            }
            $plan=$this->plan($supplier,$request);
            if(!hash_equals($plan['preview_hash'],$previewHash))PaymentException::reject('preview_stale');
            $snapshot=$plan['snapshot'];$source=$snapshot['source'];$receipt=$source['receipt'];$pdo=$this->db->pdo();
            $sourceId=$source['id'];
            if($sourceId===null) {
                $this->execute('INSERT INTO unit_payment_sources (supplier_id,bank_transaction_id,cash_document_id,journal_entry_id,direction,amount_cents,receipt_json,receipt_hash) VALUES (?,?,?,?,?,?,?,?)',
                    [$supplier,$receipt['kind']==='bank'?$receipt['id']:null,$receipt['kind']==='cash'?$receipt['id']:null,$receipt['ledger']['entry']['id'],$receipt['direction'],$receipt['amount_cents'],self::json($receipt),PropertyInput::hash($receipt)]);
                $sourceId=(int)$pdo->lastInsertId();
            }
            $this->execute('INSERT INTO unit_payment_operations (supplier_id,source_id,kind,amount_cents,accounting_date,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
                [$supplier,$sourceId,$request['kind'],$request['amount_cents'],$request['accounting_date'],self::json($request),self::json($snapshot),$hash,$previewHash,$token,$actor]);
            $id=(int)$pdo->lastInsertId();
            if (($receipt['version'] ?? 1) === 2 || isset($source['revision_id']) || $snapshot['allocations'] !== [] || $request['kind'] === 'refund') {
                $intent = \MyInvoice\Service\Property\Payments\Workflow\PaymentSettlementIntent::fromPending($this->db, $this, $supplier, $id);
                $entry = (new \MyInvoice\Repository\JournalEntryRepository($this->db, new \MyInvoice\Service\ActivityLogger($this->db)))->insertPropertySettlement($intent);
            } else {
            $entry=$this->posting->postDocument($supplier,'unit_payment',$id,$snapshot['lines'],['entry_date'=>$request['accounting_date'],'posted_by'=>$actor,'description'=>$request['reason']]);
            }
            $actual=(new PaymentJournalProof($this->db))->current($supplier,$entry);
            if(PropertyInput::hash(PaymentJournalProof::canonicalLines($snapshot['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($actual['lines'])))PaymentException::reject('posted_ledger_mismatch');
            $creditId=null;
            if($request['kind']==='allocation') {
                foreach($snapshot['allocations'] as $allocation)$this->execute('INSERT INTO unit_payment_allocations (supplier_id,operation_id,debt_id,charge_id,charge_line_id,accessory_id,debtor_id,liability_group,amount_cents,allocation_json) VALUES (?,?,?,?,?,?,?,?,?,?)',[$supplier,$id,$allocation['debt_id'],$allocation['charge_id'],$allocation['charge_line_id'],$allocation['accessory_id'],$allocation['debtor_id'],$allocation['liability_group'],$allocation['amount_cents'],self::json($allocation)]);
                $credit=$snapshot['credit'];
                if($credit!==null) {
                $this->execute('INSERT INTO unit_credit_lots (supplier_id,operation_id,unit_id,amount_cents,due_date,snapshot_json,snapshot_hash) VALUES (?,?,?,?,?,?,?)',[$supplier,$id,$credit['unit_id'],$credit['amount_cents'],$credit['due_date'],self::json($credit),PropertyInput::hash($credit)]);
                $creditId=(int)$pdo->lastInsertId();
                foreach($credit['parties'] as $party)$this->execute('INSERT INTO unit_credit_parties (supplier_id,credit_id,client_id,party_json) VALUES (?,?,?,?)',[$supplier,$creditId,$party['id'],self::json($party)]);
                }
            } else {
                $reservation=$snapshot['reservation'];$creditId=$reservation['credit_id'];
                $this->execute("INSERT INTO unit_credit_events (supplier_id,credit_id,operation_id,reservation_id,amount_cents,kind,event_json) VALUES (?,?,?,?,?,'refund',?)",[$supplier,$creditId,$id,$reservation['id'],'-'.$request['amount_cents'],self::json(['reservation_id'=>$reservation['id'],'amount_cents'=>$request['amount_cents'],'source_receipt_hash'=>PropertyInput::hash($receipt)])]);
                $paid=bcadd($reservation['paid_cents'],$request['amount_cents'],0);$status=$paid===$reservation['amount_cents']?'paid':$reservation['status'];
                $this->cas('UPDATE unit_credit_reservations SET paid_cents=?,status=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$paid,$status,$supplier,$reservation['id'],$reservation['row_version']]);
                $this->cas('UPDATE unit_credit_lots SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$supplier,$creditId,$snapshot['credit']['row_version']]);
            }
            $newVersion=$source['id']===null?1:$source['version'];
            $this->cas('UPDATE unit_payment_sources SET consumed_cents=?,row_version=? WHERE supplier_id=? AND id=? AND row_version=?',
                [bcadd($source['consumed_cents'],$request['amount_cents'],0),$source['version']+1,$supplier,$sourceId,$newVersion]);
            $this->cas("UPDATE unit_payment_operations SET status='posted',journal_entry_id=?,row_version=2 WHERE supplier_id=? AND id=? AND status='pending'",[$entry,$supplier,$id]);
            $this->audit($supplier,'operation',$id,'posted',['snapshot_hash'=>$previewHash,'journal_entry_id'=>$entry,'credit_id'=>$creditId],$actor);
            return $this->operationLocked($supplier,$id);
        });
    }

    public function provePendingSettlement(Connection $connection, int $supplier, int $id): array
    {
        $proof = \MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof::fromPendingOperation($connection, $this, $supplier, $id);
        $journal = (new \MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowJournalPlan($this->db))->currentPayment($proof);
        $row = $this->one('SELECT request_json,created_by FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE', [$supplier, $id]);
        $request = self::decode($row['request_json']);
        $journal['header'] += ['posted_at' => gmdate('Y-m-d H:i:s'), 'posted_by' => (int) $row['created_by'], 'description' => $request['reason']];
        return $journal;
    }

    public function currentCarrierSnapshot(Connection $connection, int $supplier, array $request, ?\MyInvoice\Service\Property\Payments\Workflow\PaymentSourcePreview $prospective = null, ?int $operationId = null): array
    {
        if ($connection->pdo() !== $this->db->pdo() || !$connection->pdo()->inTransaction()) throw new \LogicException('Carrier proof requires the owner transaction.');
        (new PaymentLocks($this->db))->acquire($supplier);
        if ($operationId !== null) return $this->pendingSettlementSnapshot($supplier, $operationId);
        $prospective = $prospective?->revalidated($connection, $supplier);
        return $this->plan($supplier, $request, $prospective)['snapshot'];
    }

    private function pendingSettlementSnapshot(int $supplier, int $id): array
    {
        (new PaymentLocks($this->db))->acquire($supplier);
        $row = $this->one('SELECT * FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE', [$supplier, $id]);
        if (!$row || $row['status'] !== 'pending' || $row['journal_entry_id'] !== null || !in_array($row['kind'], ['allocation', 'refund'], true)) PaymentException::reject('settlement_intent');
        $request = self::decode($row['request_json']); $snapshot = self::decode($row['snapshot_json']);
        self::hash($row['request_hash'], ['request' => $request, 'token' => $row['idempotency_key'], 'actor' => (int) $row['created_by']]);
        self::hash($row['snapshot_hash'], $snapshot);
        $source = (new PaymentSourceProof($this->db))->current($supplier, $request['source']['kind'], $request['source']['id']);
        if ($source['id'] !== (int) $row['source_id']) PaymentException::reject('settlement_intent');
        $currentRequest = $request;
        $fresh = $snapshot['source']['id'] === null;
        if ($fresh) {
            $others = $this->one('SELECT id FROM unit_payment_operations WHERE supplier_id=? AND source_id=? AND id<>? LIMIT 1 FOR UPDATE', [$supplier, $row['source_id'], $id]);
            if ($others || $source['version'] !== 1 || $source['consumed_cents'] !== '0' || $snapshot['source']['version'] !== 0) PaymentException::reject('settlement_intent');
            $currentRequest['source']['expected_version'] = 1;
        }
        $current = $this->plan($supplier, $currentRequest)['snapshot'];
        if ($fresh) {
            $current['request'] = $request;
            $current['source']['id'] = null;
            $current['source']['version'] = 0;
        }
        if (!hash_equals($row['snapshot_hash'], PropertyInput::hash($current))) PaymentException::reject('settlement_intent');
        return $current;
    }

    public function reserve(int $supplier,int $creditId,int $expected,array $request,string $token,int $actor): array
    {
        PropertyInput::code($token,'idempotency_key');
        return $this->transaction($supplier,function()use($supplier,$creditId,$expected,$request,$token,$actor):array {
            $hash=PropertyInput::hash(['credit_id'=>$creditId,'expected_version'=>$expected,'request'=>$request,'token'=>$token,'actor'=>$actor]);
            $old=$this->one('SELECT id,request_hash FROM unit_credit_reservations WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){$found=$this->reservationLocked($supplier,(int)$old['id']);if(!hash_equals($old['request_hash'],$hash))PaymentException::reject('idempotency_conflict');return $found;}
            PropertyInput::fields($request,['amount_cents','party_ids','disposition','recipient_account','delivery_method','recipient_id','recipient_evidence']);
            $amount=$this->positive($request['amount_cents']??null);$credit=$this->creditLocked($supplier,$creditId);
            if($credit['row_version']!==$expected)PaymentException::reject('version_conflict');
            if(bccomp($amount,$credit['available_cents'],0)>0)PaymentException::reject('credit_capacity');
            $parties=$request['party_ids']??null;if(!is_array($parties)||!array_is_list($parties))PropertyInput::invalid('party_ids');sort($parties,SORT_NUMERIC);
            $actual=array_column($credit['snapshot']['parties'],'id');sort($actual,SORT_NUMERIC);
            if($parties!==$actual)PaymentException::reject('entitlement_mismatch');
            PropertyInput::text($request['disposition']??null,'disposition',4000);
            $method=$request['delivery_method']??'bank';
            if($method==='bank'){self::bankAccount($request['recipient_account']??null);if(isset($request['recipient_id'])||isset($request['recipient_evidence']))PropertyInput::invalid('recipient_id');}
            elseif($method==='cash'){
                if(isset($request['recipient_account']))PropertyInput::invalid('recipient_account');$recipient=PropertyInput::id($request['recipient_id']??null,'recipient_id');PropertyInput::text($request['recipient_evidence']??null,'recipient_evidence',4000);
                if(count((new PropertyReadRepository($this->db))->parties($supplier,[$recipient],true))!==1)PaymentException::reject('foreign_party');
            }else PropertyInput::invalid('delivery_method');
            $this->execute('INSERT INTO unit_credit_reservations (supplier_id,credit_id,amount_cents,request_json,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$creditId,$amount,self::json(['expected_version'=>$expected,'request'=>$request]),$hash,$token,$actor]);
            $id=(int)$this->db->pdo()->lastInsertId();$this->cas('UPDATE unit_credit_lots SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$supplier,$creditId,$expected]);
            $this->audit($supplier,'reservation',$id,'reserved',['credit_id'=>$creditId,'request_hash'=>$hash],$actor);
            return $this->reservationLocked($supplier,$id);
        });
    }

    public function cancelReservation(int $supplier,int $id,int $expected,string $reason,string $token,int $actor):array
    {
        PropertyInput::text($reason,'reason',4000);PropertyInput::code($token,'idempotency_key');
        return $this->transaction($supplier,function()use($supplier,$id,$expected,$reason,$token,$actor):array{
            $request=['expected_version'=>$expected,'reason'=>$reason,'token'=>$token,'actor'=>$actor];
            foreach($this->all("SELECT payload_json,payload_hash FROM unit_payment_audit WHERE supplier_id=? AND object_kind='reservation' AND object_id=? AND action='cancelled' ORDER BY id FOR UPDATE",[$supplier,$id]) as $event){$payload=self::decode($event['payload_json']);self::hash($event['payload_hash'],$payload);if($payload['token']===$token){if(PropertyInput::hash($payload)!==PropertyInput::hash($request))PaymentException::reject('idempotency_conflict');return $this->reservationLocked($supplier,$id);}}
            $reservation=$this->reservationLocked($supplier,$id);$credit=$this->creditLocked($supplier,$reservation['credit_id']);
            if($reservation['row_version']!==$expected)PaymentException::reject('reservation_version_conflict');
            if($reservation['status']!=='reserved')PaymentException::reject('reservation_not_cancellable');
            $this->cas("UPDATE unit_credit_reservations SET status='cancelled',row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?",[$supplier,$id,$expected]);
            $this->cas('UPDATE unit_credit_lots SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$supplier,$credit['id'],$credit['row_version']]);
            $this->audit($supplier,'reservation',$id,'cancelled',$request,$actor);return $this->reservationLocked($supplier,$id);
        });
    }

    public function reverse(int $supplier,int $originalId,int $expected,string $date,string $reason,string $token,int $actor):array
    {
        self::date($date);PropertyInput::text($reason,'reason',4000);PropertyInput::code($token,'idempotency_key');
        return $this->transaction($supplier,function()use($supplier,$originalId,$expected,$date,$reason,$token,$actor):array{
            $request=['kind'=>'reversal','original_operation_id'=>$originalId,'expected_version'=>$expected,'accounting_date'=>$date,'reason'=>$reason];
            $original=$this->operationLocked($supplier,$originalId);$request['amount_cents']=$original['amount_cents'];$hash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);
            $old=$this->one('SELECT id,request_hash FROM unit_payment_operations WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);
            if($old){if(!hash_equals($hash,$old['request_hash']))PaymentException::reject('idempotency_conflict');return $this->operationLocked($supplier,(int)$old['id']);}
            if($original['kind']==='correction')PaymentException::reject('correction_owner_required');
            (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertOperationMutable($supplier,$originalId);
            if($original['status']!=='posted'||$original['kind']==='reversal'||$original['row_version']!==$expected)PaymentException::reject('version_conflict');
            (new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertOperationReversible($supplier,$originalId);
            if($date<$original['accounting_date'])PaymentException::reject('backdated_payment');
            (new IssuanceLocks($this->db))->assertFramework($supplier,$date);
            $credit=null;$reservation=null;
            if($original['kind']==='allocation'&&$original['credit_id']!==null){
                $credit=$this->creditLocked($supplier,$original['credit_id']);
                if($credit['balance_cents']!==$credit['snapshot']['amount_cents']||$credit['reserved_cents']!=='0')PaymentException::reject('credit_in_use');
            } elseif($original['kind']==='refund'){
                $reservation=$this->reservationLocked($supplier,$original['snapshot']['reservation']['id']);$credit=$this->creditLocked($supplier,$reservation['credit_id']);
            }
            $source=null;if($original['kind']==='setoff'){$credit=$this->creditLocked($supplier,$original['snapshot']['credit']['id']);}else{$rawSource=$original['snapshot']['source']['receipt'];$source=(new PaymentSourceProof($this->db))->current($supplier,$rawSource['kind'],$rawSource['id']);}
            $lines=$original['snapshot']['lines'];foreach($lines as &$line)$line['side']=$line['side']==='debit'?'credit':'debit';unset($line);
            $snapshot=['request'=>$request,'original_operation_id'=>$originalId,'original_snapshot_hash'=>$original['snapshot_hash'],'lines'=>$lines];
            $this->execute("INSERT INTO unit_payment_operations (supplier_id,original_operation_id,kind,amount_cents,accounting_date,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,'reversal',?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",[$supplier,$originalId,$original['amount_cents'],$date,self::json($request),self::json($snapshot),$hash,PropertyInput::hash($snapshot),$token,$actor]);
            $id=(int)$this->db->pdo()->lastInsertId();
            $intent=\MyInvoice\Service\Property\Payments\PaymentReversalIntent::fromCurrentRequest($this->db,$supplier,$id,$this);
            $entry=(new \MyInvoice\Repository\JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db)))->insertPropertyPaymentReversal($intent);
            return $this->operationLocked($supplier,$id);
        });
    }

    public function reversalCompletionContext(int $supplier,int $pendingId):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Reversal completion requires current owner locks.');
        (new PaymentLocks($this->db))->acquire($supplier);
        $pending=$this->operationLocked($supplier,$pendingId);
        if($pending['kind']!=='reversal'||$pending['status']!=='pending'||$pending['journal_entry_id']!==null)PaymentException::reject('reversal_intent');
        $original=$this->operationLocked($supplier,$pending['original_operation_id']);
        if($original['status']!=='posted'||$original['row_version']!==$pending['request']['expected_version'])PaymentException::reject('reversal_intent');
        (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertOperationMutable($supplier,$original['id']);
        $source=$credit=$reservation=$correction=null;
        if($original['kind']==='correction') {
            $rawPending=$this->one('SELECT * FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$pendingId]);
            $rawOriginal=$this->one('SELECT * FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$original['id']]);
            $correction=\MyInvoice\Service\Property\Payments\Workflow\PaymentReliefReversalProof::assertPending($this->db,$supplier,$rawPending,$rawOriginal,$pending['request']);
            (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertCorrectionMutable($supplier,(int)$correction['correction']['id']);
        } elseif($original['kind']==='setoff') $credit=$this->creditLocked($supplier,$original['snapshot']['credit']['id']);
        else {
            $receipt=$original['snapshot']['source']['receipt'];
            $source=(new PaymentSourceProof($this->db))->current($supplier,$receipt['kind'],$receipt['id']);
            if(bccomp($source['consumed_cents'],$original['amount_cents'],0)<0)PaymentException::reject('source_capacity_corrupt');
        }
        if(in_array($original['kind'],['allocation','correction'],true)&&$original['credit_id']!==null) {
            $credit=$this->creditLocked($supplier,$original['credit_id']);
            if($credit['balance_cents']!==$credit['snapshot']['amount_cents']||$credit['reserved_cents']!=='0')PaymentException::reject('credit_in_use');
        } elseif($original['kind']==='refund') {
            $reservation=$this->reservationLocked($supplier,$original['snapshot']['reservation']['id']);
            $credit=$this->creditLocked($supplier,$reservation['credit_id']);
            if(bccomp($reservation['paid_cents'],$original['amount_cents'],0)<0)PaymentException::reject('reservation_capacity_corrupt');
        }
        $reservationAfterStatus=$reservation===null?null:(new PaymentExportRepository($this->db,$this))->reservationStatusAfterReversal($supplier,$reservation);
        return compact('pending','original','source','credit','reservation','correction','reservationAfterStatus');
    }

    public function completePendingReversal(\MyInvoice\Service\Property\Payments\PaymentReversalIntent $intent,int $entry):void
    {
        $context=$intent->completion($this->db);$supplier=$intent->supplierId;
        $pending=$context['pending'];$original=$context['original'];$source=$context['source'];$credit=$context['credit'];$reservation=$context['reservation'];
        $actual=(new PaymentJournalProof($this->db))->current($supplier,$entry);
        $anchor=(new PaymentJournalProof($this->db))->current($supplier,$intent->sourceEntryId);
        if($pending['id']!==$intent->intentId||$original['journal_entry_id']!==$intent->sourceEntryId||$anchor['entry']['reversed_by']!==$entry
            ||$actual['entry']['source_type']!=='unit_payment'||$actual['entry']['source_id']!==null||$actual['entry']['reversed_by']!==null||$actual['entry']['entry_date']!==$intent->accountingDate
            ||PropertyInput::hash(PaymentJournalProof::canonicalLines($actual['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($pending['snapshot']['lines'])))PaymentException::reject('reversal_intent');
        $this->cas("UPDATE unit_payment_operations SET status='reversed',reversal_entry_id=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status='posted' AND snapshot_hash=?",[$entry,$supplier,$original['id'],$original['row_version'],$original['snapshot_hash']]);
        $this->cas("UPDATE unit_payment_operations SET status='posted',journal_entry_id=?,row_version=2 WHERE supplier_id=? AND id=? AND status='pending' AND row_version=1 AND request_hash=? AND snapshot_hash=?",[$entry,$supplier,$pending['id'],$pending['request_hash'],$pending['snapshot_hash']]);
        if($source!==null)$this->cas('UPDATE unit_payment_sources SET consumed_cents=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[bcsub($source['consumed_cents'],$original['amount_cents'],0),$supplier,$source['id'],$source['version']]);
        if($reservation!==null){$paid=bcsub($reservation['paid_cents'],$original['amount_cents'],0);$status=$context['reservationAfterStatus'];$this->cas('UPDATE unit_credit_reservations SET paid_cents=?,status=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$paid,$status,$supplier,$reservation['id'],$reservation['row_version']]);}
        if($credit!==null)$this->cas('UPDATE unit_credit_lots SET row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?',[$supplier,$credit['id'],$credit['row_version']]);
        if($context['correction']!==null) {
            $correction=$context['correction']['correction'];$audit=$context['correction']['audit'];
            $this->cas("UPDATE unit_payment_corrections SET status='reversed',row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status='posted'",[$supplier,$correction['id'],$correction['row_version']]);
            $this->audit($supplier,'correction',(int)$correction['id'],'reversed',['request'=>$audit['request'],'token'=>$audit['token'],'actor'=>$audit['actor']],$intent->actor);
        }
        $this->audit($supplier,'operation',$pending['id'],'reversal',['original_operation_id'=>$original['id'],'snapshot_hash'=>$pending['snapshot_hash'],'journal_entry_id'=>$entry],$intent->actor);
    }

    public function reservation(int $supplier,int $id): array {return $this->transaction($supplier,fn()=> $this->reservationLocked($supplier,$id));}
    public function credit(int $supplier,int $id): array {return $this->transaction($supplier,fn()=> $this->creditLocked($supplier,$id));}
    public function operation(int $supplier,int $id): array {return $this->transaction($supplier,fn()=> $this->operationLocked($supplier,$id));}

    private function plan(int $supplier,array $request,?\MyInvoice\Service\Property\Payments\Workflow\PaymentSourcePreview $prospective=null): array
    {
        $kind=$request['kind']??null;if(!in_array($kind,['allocation','refund'],true))PropertyInput::invalid('kind');
        PropertyInput::fields($request,$kind==='allocation'?['kind','source','amount_cents','accounting_date','reason','debts','credit_entitlement','payment','clearing_selection']:['kind','source','amount_cents','accounting_date','reason','reservation_id','expected_reservation_version','cash_receipt','clearing_selection']);
        $amount=$this->positive($request['amount_cents']??null);$date=self::date($request['accounting_date']??null);
        PropertyInput::text($request['reason']??null,'reason',4000);
        $raw=$request['source']??null;if(!is_array($raw))PropertyInput::invalid('source');PropertyInput::fields($raw,['kind','id','expected_version']);
        if(!is_int($raw['expected_version']??null)||$raw['expected_version']<0)PropertyInput::invalid('source.expected_version');
        (new \MyInvoice\Service\Property\Payments\PaymentPostingDate($this->db))->assertOpen($supplier,$date);
        $source=$prospective?->source($supplier)??(new PaymentSourceProof($this->db))->current($supplier,(string)($raw['kind']??''),PropertyInput::id($raw['id']??null,'source.id'));
        if($source['version']!==$raw['expected_version'])PaymentException::reject('source_version_conflict');
        if(bccomp($amount,$source['available_cents'],0)>0)PaymentException::reject('source_capacity');
        if($date<$source['receipt']['date'])PaymentException::reject('backdated_payment');
        if($source['receipt']['direction']!==($kind==='allocation'?'in':'out'))PaymentException::reject('source_direction');
        $clearing=\MyInvoice\Service\Property\Payments\Workflow\PaymentSourcePreview::clearingLines($source,$amount,$request['clearing_selection']??null);
        $snapshot=['request'=>$request,'source'=>$source,'allocations'=>[]];
        if(isset($source['receipt']['clearing_cells'])||isset($source['revision_id']))$snapshot['clearing_selection']=$clearing['selection'];
        if($kind==='allocation') {
            $creditAmount=$amount;$snapshot['lines']=$clearing['lines'];
            if(($request['debts']??null)!==[]) {
                $claimPlan=(new \MyInvoice\Service\Property\Payments\ClaimAllocationPlanner($this->db))->current($supplier,$request,$source);
                $snapshot['resolution']=$claimPlan['resolution'];$snapshot['allocations']=$claimPlan['allocations'];$snapshot['debt_receipts']=$claimPlan['debt_receipts'];$snapshot['payment_parties']=$claimPlan['payment_parties'];
                array_push($snapshot['lines'],...$claimPlan['lines']);$creditAmount=$claimPlan['resolution']['credit_cents'];
            }
            $snapshot['credit']=null;
            if($creditAmount!=='0') {
            $ent=$request['credit_entitlement']??null;if(!is_array($ent))PropertyInput::invalid('credit_entitlement');
            PropertyInput::fields($ent,['unit_id','party_ids','evidence','due_date']);PropertyInput::text($ent['evidence']??null,'credit_entitlement.evidence',4000);
            $unitId=PropertyInput::id($ent['unit_id']??null,'unit_id');$reader=new PropertyReadRepository($this->db);
            $at=(new \DateTimeImmutable($date.' 12:00:00',new \DateTimeZone('Europe/Prague')))->format('c');(new PropertyProfileRepository($this->db))->assertEnabledLocked($supplier,$at);
            $unit=$reader->find($supplier,$unitId,$at,null,true);if($unit['kind']!=='unit')PropertyInput::invalid('unit_id');
            $ids=PropertyInput::list($ent['party_ids']??null,'party_ids',100);if($ids===[]||count(array_unique($ids))!==count($ids))PropertyInput::invalid('party_ids');
            $parties=$reader->parties($supplier,$ids,true);if(count($parties)!==count($ids))PaymentException::reject('foreign_party');
            $value=$this->one('SELECT id,type_id FROM dimension_values WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$unit['dimension_value_id']]);if(!$value)PaymentException::reject('unit_dimension');
            $line=['account_code'=>'379.910','side'=>'credit','amount'=>bcdiv($creditAmount,'100',2),'dimensions'=>[(int)$value['type_id']=>(int)$value['id']],
                'dimension_origins'=>[(int)$value['type_id']=>['origin'=>'module','ref'=>$source['receipt']['kind'].':'.$source['receipt']['id']]]];
            (new PropertyDimensionPolicy($this->db))->assertAccountingDate($supplier,$date,[$line]);
            $snapshot['credit']=['unit_id'=>$unitId,'amount_cents'=>$creditAmount,'due_date'=>self::date($ent['due_date']??null),'parties'=>$parties,'evidence'=>$ent['evidence'],'line'=>$line];
            $snapshot['lines'][]=$line;
            }
        } else {
            $reservation=$this->reservationLocked($supplier,PropertyInput::id($request['reservation_id']??null,'reservation_id'));
            if($reservation['row_version']!==($request['expected_reservation_version']??null))PaymentException::reject('reservation_version_conflict');
            if(!in_array($reservation['status'],['reserved','submitted'],true)||bccomp($amount,bcsub($reservation['amount_cents'],$reservation['paid_cents'],0),0)>0)PaymentException::reject('reservation_capacity');
            $credit=$this->creditLocked($supplier,$reservation['credit_id']);if(bccomp($amount,$credit['balance_cents'],0)>0)PaymentException::reject('credit_capacity');
            $receipt=$source['receipt'];
            if($receipt['kind']!==($reservation['request']['delivery_method']??'bank'))PaymentException::reject('refund_recipient_mismatch');
            if($receipt['kind']==='bank'){
                if(isset($request['cash_receipt']))PropertyInput::invalid('cash_receipt');$actual=($receipt['counterparty_account']??'').'/'.($receipt['counterparty_bank']??'');
                if(self::bankAccount($actual)!==self::bankAccount($reservation['request']['recipient_account']))PaymentException::reject('refund_recipient_mismatch');
            }else{
                $signed=$request['cash_receipt']??null;if(!is_array($signed))PaymentException::reject('cash_recipient_receipt_required');PropertyInput::fields($signed,['cash_document_id','recipient_id','recipient_name','evidence']);
                PropertyInput::text($signed['evidence']??null,'cash_receipt.evidence',4000);PropertyInput::text($signed['recipient_name']??null,'cash_receipt.recipient_name',190);
                if(($signed['cash_document_id']??null)!==$receipt['id']||($signed['recipient_id']??null)!==$reservation['request']['recipient_id']||$signed['recipient_name']!==$receipt['partner_name'])PaymentException::reject('refund_recipient_mismatch');
            }
            $line=$credit['snapshot']['line'];$line['side']='debit';$line['amount']=bcdiv($amount,'100',2);
            $snapshot['reservation']=$reservation;$snapshot['credit']=$credit;$snapshot['lines']=[$line,...$clearing['lines']];
        }
        return ['snapshot'=>$snapshot,'preview_hash'=>PropertyInput::hash($snapshot)];
    }

    private function operationLocked(int $supplier,int $id): array
    {
        $row=$this->one('SELECT * FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row)PaymentException::reject('not_found');
        $request=self::decode($row['request_json']);$snapshot=self::decode($row['snapshot_json']);
        self::hash($row['request_hash'],['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);self::hash($row['snapshot_hash'],$snapshot);
        foreach(['kind','amount_cents','accounting_date'] as $field)if($row[$field]!==$request[$field])PaymentException::reject('history_corrupt');
        if(PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request']))PaymentException::reject('history_corrupt');
        if(in_array($row['status'],['posted','reversed'],true)) {
            $actual=(new PaymentJournalProof($this->db))->current($supplier,(int)$row['journal_entry_id']);
            if($actual['entry']['source_type']!=='unit_payment'||$actual['entry']['source_id']!==($row['kind']==='reversal'?null:$id)||$actual['entry']['reversed_by']!==($row['status']==='reversed'?(int)$row['reversal_entry_id']:null)||$actual['entry']['entry_date']!==$row['accounting_date']||PropertyInput::hash(PaymentJournalProof::canonicalLines($actual['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($snapshot['lines'])))PaymentException::reject('ledger_mismatch');
        }
        if($row['status']==='reversed') {
            $mirror=(new PaymentJournalProof($this->db))->current($supplier,(int)$row['reversal_entry_id']);$lines=$snapshot['lines'];foreach($lines as &$line)$line['side']=$line['side']==='debit'?'credit':'debit';unset($line);
            if($mirror['entry']['source_type']!=='unit_payment'||$mirror['entry']['source_id']!==null||$mirror['entry']['reversed_by']!==null||PropertyInput::hash(PaymentJournalProof::canonicalLines($mirror['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($lines)))PaymentException::reject('ledger_mismatch');
        }
        $actualAllocations=[];foreach($this->all('SELECT * FROM unit_payment_allocations WHERE supplier_id=? AND operation_id=? ORDER BY id FOR UPDATE',[$supplier,$id]) as $allocation){
            $fact=self::decode($allocation['allocation_json']);foreach(['debt_id','charge_id','charge_line_id','accessory_id','debtor_id','liability_group','amount_cents'] as $key){$actual=$allocation[$key];if(is_int($fact[$key]))$actual=(int)$actual;if($actual!==$fact[$key])PaymentException::reject('history_corrupt');}$actualAllocations[]=$fact;
        }
        if(PropertyInput::hash($actualAllocations)!==PropertyInput::hash($snapshot['allocations']??[]))PaymentException::reject('history_corrupt');
        $credit=$this->one('SELECT id FROM unit_credit_lots WHERE supplier_id=? AND operation_id=? FOR UPDATE',[$supplier,$id]);
        foreach(['id','source_id','original_operation_id','row_version','journal_entry_id','reversal_entry_id','created_by'] as $key)if($row[$key]!==null)$row[$key]=(int)$row[$key];
        unset($row['request_json'],$row['snapshot_json']);return $row+['request'=>$request,'snapshot'=>$snapshot,'credit_id'=>$credit?(int)$credit['id']:null];
    }

    private function creditLocked(int $supplier,int $id): array
    {
        $row=$this->one('SELECT * FROM unit_credit_lots WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row)PaymentException::reject('credit_not_found');
        $snapshot=self::decode($row['snapshot_json']);self::hash($row['snapshot_hash'],$snapshot);
        foreach(['unit_id','amount_cents','due_date'] as $key)if((is_int($snapshot[$key])?(int)$row[$key]:$row[$key])!==$snapshot[$key])PaymentException::reject('history_corrupt');
        $origin=$this->operationLocked($supplier,(int)$row['operation_id']);if(!in_array($origin['status'],['posted','reversed'],true)||PropertyInput::hash($origin['snapshot']['credit'])!==$row['snapshot_hash'])PaymentException::reject('credit_origin');
        $parties=$this->all('SELECT client_id,party_json FROM unit_credit_parties WHERE supplier_id=? AND credit_id=? ORDER BY client_id FOR UPDATE',[$supplier,$id]);
        $actual=[];foreach($parties as $party){$fact=self::decode($party['party_json']);if($fact['id']!==(int)$party['client_id'])PaymentException::reject('history_corrupt');$actual[]=$fact;}
        if(PropertyInput::hash($actual)!==PropertyInput::hash($snapshot['parties']))PaymentException::reject('history_corrupt');
        $balance=$origin['status']==='reversed'?'0':$row['amount_cents'];
        foreach($this->all('SELECT * FROM unit_credit_events WHERE supplier_id=? AND credit_id=? ORDER BY id FOR UPDATE',[$supplier,$id]) as $event) {
            $op=$this->operationLocked($supplier,(int)$event['operation_id']);if($op['status']==='posted')$balance=bcadd($balance,$event['amount_cents'],0);
            if($event['kind']!==$op['kind']||!in_array($event['kind'],['refund','setoff'],true)||$event['amount_cents']!=='-'.$op['amount_cents']||$op['snapshot']['credit']['id']!==$id)PaymentException::reject('history_corrupt');
            if($event['kind']==='refund'){$expected=['reservation_id'=>$op['snapshot']['reservation']['id'],'amount_cents'=>$op['amount_cents'],'source_receipt_hash'=>PropertyInput::hash($op['snapshot']['source']['receipt'])];if((int)$event['reservation_id']!==$expected['reservation_id'])PaymentException::reject('history_corrupt');}
            else{$expected=['credit_id'=>$id,'amount_cents'=>$op['amount_cents'],'credit_snapshot_hash'=>PropertyInput::hash($snapshot)];if($event['reservation_id']!==null)PaymentException::reject('history_corrupt');}
            if(PropertyInput::hash(self::decode($event['event_json']))!==PropertyInput::hash($expected))PaymentException::reject('history_corrupt');
        }
        $reserved='0';foreach($this->all('SELECT id FROM unit_credit_reservations WHERE supplier_id=? AND credit_id=? ORDER BY id FOR UPDATE',[$supplier,$id]) as $r){$r=$this->reservationLocked($supplier,(int)$r['id']);if(in_array($r['status'],['reserved','submitted'],true))$reserved=bcadd($reserved,bcsub($r['amount_cents'],$r['paid_cents'],0),0);}
        if(bccomp($balance,$reserved,0)<0)PaymentException::reject('credit_capacity_corrupt');
        return ['id'=>$id,'row_version'=>(int)$row['row_version'],'snapshot'=>$snapshot,'balance_cents'=>$balance,'reserved_cents'=>$reserved,'available_cents'=>bcsub($balance,$reserved,0)];
    }

    private function reservationLocked(int $supplier,int $id): array
    {
        $row=$this->one('SELECT * FROM unit_credit_reservations WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);if(!$row)PaymentException::reject('reservation_not_found');
        $data=self::decode($row['request_json']);self::hash($row['request_hash'],['credit_id'=>(int)$row['credit_id'],'expected_version'=>$data['expected_version'],'request'=>$data['request'],'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
        if($row['amount_cents']!==$data['request']['amount_cents'])PaymentException::reject('history_corrupt');
        $paid='0';foreach($this->all("SELECT e.amount_cents FROM unit_credit_events e JOIN unit_payment_operations o ON o.id=e.operation_id AND o.supplier_id=e.supplier_id WHERE e.supplier_id=? AND e.reservation_id=? AND o.status='posted' ORDER BY e.id FOR UPDATE",[$supplier,$id]) as $event)$paid=bcsub($paid,$event['amount_cents'],0);
        if($paid!==$row['paid_cents'])PaymentException::reject('reservation_capacity_corrupt');
        if(bccomp($paid,'0',0)<0||bccomp($paid,$row['amount_cents'],0)>0||($row['status']==='paid'&&$paid!==$row['amount_cents'])||(in_array($row['status'],['reserved','submitted'],true)&&$paid===$row['amount_cents']))PaymentException::reject('reservation_capacity_corrupt');
        foreach(['id','credit_id','row_version','created_by'] as $key)$row[$key]=(int)$row[$key];unset($row['request_json']);return $row+['request'=>$data['request']];
    }

    private function transaction(int $supplier,callable $work): mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    private function execute(string $sql,array $params): \PDOStatement{$s=$this->db->pdo()->prepare($sql);$s->execute($params);return $s;}
    private function all(string $sql,array $params): array{return $this->execute($sql,$params)->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $params): ?array{return $this->execute($sql,$params)->fetch(PDO::FETCH_ASSOC)?:null;}
    private function cas(string $sql,array $params): void{if($this->execute($sql,$params)->rowCount()!==1)PaymentException::reject('version_conflict');}
    private function audit(int $supplier,string $object,int $id,string $action,array $payload,int $actor):void{$this->execute('INSERT INTO unit_payment_audit (supplier_id,object_kind,object_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$object,$id,$action,self::json($payload),PropertyInput::hash($payload),$actor]);}
    private function positive(mixed $amount):string{try{$amount=StatutoryAllocationPolicy::cents($amount);}catch(\InvalidArgumentException){PropertyInput::invalid('amount_cents');}if($amount==='0')PropertyInput::invalid('amount_cents');return $amount;}
    private static function date(mixed $value):string{if(!is_string($value)||!($d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value))||$d->format('Y-m-d')!==$value)PropertyInput::invalid('date');return $value;}
    private static function bankAccount(mixed $value):string{if(!is_string($value)||!preg_match('#^([0-9]{1,6}-)?[0-9]{1,10}/[0-9]{4}$#D',$value))PropertyInput::invalid('recipient_account');[$account,$bank]=explode('/',$value);return AccountNumberNormalizer::canonical($account).'/'.$bank;}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private static function decode(string $value):array{return json_decode($value,true,flags:JSON_THROW_ON_ERROR);}
    private static function hash(string $hash,array $value):void{if(!hash_equals($hash,PropertyInput::hash($value)))PaymentException::reject('history_corrupt');}
}
