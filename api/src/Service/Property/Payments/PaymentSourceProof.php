<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceLocks;
use MyInvoice\Service\Property\PropertyInput;
use PDO;
final class PaymentSourceProof
{
    public function __construct(private readonly Connection $db) {}
    public function current(int $supplier,string $kind,int $id): array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Payment source requires owner locks.');
        $pdo=$this->db->pdo();
        if($kind==='bank') {
            $s=$pdo->prepare('SELECT bt.*,bs.currency statement_currency FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bt.id=? AND '.BankStatementOwnershipResolver::sql().' FOR UPDATE');$s->execute([$id,...BankStatementOwnershipResolver::params($supplier)]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row) PaymentException::reject('source_not_found');
            if(($row['source']??'statement')!=='statement'||$row['matched_invoice_id']!==null||$row['match_status']!=='unmatched')PaymentException::reject('source_owned_elsewhere');
            foreach(['payment_matches','invoice_payments','payroll_payment_matches'] as $table) {
                $s=$pdo->prepare('SELECT id FROM '.$table.' WHERE bank_transaction_id=? LIMIT 1 FOR UPDATE');$s->execute([$id]);if($s->fetchColumn()!==false)PaymentException::reject('source_owned_elsewhere');
            }
            $amount=JournalLineAmount::decimal($row['amount']);$currency=$row['currency']?:$row['statement_currency'];$date=$row['posted_at'];$direction=bccomp($amount,'0',2)>0?'in':'out';
        } elseif($kind==='cash') {
            $s=$pdo->prepare('SELECT * FROM cash_documents WHERE supplier_id=? AND id=? FOR UPDATE');$s->execute([$supplier,$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row)PaymentException::reject('source_not_found');
            if($row['status']!=='posted'||$row['purpose']!=='other'||$row['invoice_id']!==null||$row['purchase_invoice_id']!==null||$row['invoice_payment_id']!==null||$row['reversal_entry_id']!==null||$row['journal_entry_id']===null||$row['vat_mode']!=='none')PaymentException::reject('source_owned_elsewhere');
            $s=$pdo->prepare('SELECT id FROM payroll_payment_matches WHERE cash_document_id=? LIMIT 1 FOR UPDATE');$s->execute([$id]);if($s->fetchColumn()!==false)PaymentException::reject('source_owned_elsewhere');
            $amount=JournalLineAmount::decimal($row['total_amount']);$currency=$row['currency_code'];$date=$row['issue_date'];$direction=$row['doc_type'];
            if(bccomp($amount,'0',2)<=0)PaymentException::reject('source_amount');
            if(bccomp((string)$row['fx_rate'],'1',6)!==0)PaymentException::reject('fx_review_required',['incomplete'=>true]);
        } else PaymentException::reject('invalid_source');
        if($currency!=='CZK')PaymentException::reject('fx_review_required',['incomplete'=>true]);
        $cents=bcmul(ltrim($amount,'-'),'100',0);if(bccomp($cents,'0',0)<=0)PaymentException::reject('source_amount');
        (new IssuanceLocks($this->db))->assertFramework($supplier,$date);
        $s=$pdo->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND source_type=? AND source_id=? AND reversed_by IS NULL AND posted_at IS NOT NULL ORDER BY id FOR UPDATE');$s->execute([$supplier,$kind,$id]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)!==1)PaymentException::reject('source_requires_clearing_owner');
        if($kind==='cash'&&(int)$row['journal_entry_id']!==(int)$ids[0])PaymentException::reject('source_clearing_mismatch');
        $ledger=(new PaymentJournalProof($this->db))->current($supplier,(int)$ids[0]);
        $workflow=(new \MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowSourceProof($this->db))->current($supplier,$kind,$id,$row,$ledger,$date,$direction,$cents);
        if($workflow!==null)return $workflow;
        if($ledger['entry']['entry_date']!==$date||count($ledger['lines'])!==2)PaymentException::reject('source_clearing_mismatch');
        $clearing=null;$money=null;
        foreach($ledger['lines'] as $line) {
            if($line['is_red_storno']||$line['currency_code']!==null||$line['amount_foreign']!==null||$line['fx_rate']!==null||bcmul($line['amount'],'100',0)!==$cents)PaymentException::reject('source_clearing_mismatch');
            if($line['account_code']==='379.900'&&$line['side']===($direction==='in'?'credit':'debit'))$clearing=$line;
            elseif(str_starts_with($line['account_code'],$kind==='bank'?'221':'211')&&$line['side']===($direction==='in'?'debit':'credit'))$money=$line;
        }
        if($clearing===null||$money===null||$ledger['entry']['reversed_by']!==null)PaymentException::reject('source_clearing_mismatch');
        $column=$kind==='bank'?'bank_transaction_id':'cash_document_id';
        $s=$pdo->prepare('SELECT * FROM unit_payment_sources WHERE supplier_id=? AND '.$column.'=? FOR UPDATE');$s->execute([$supplier,$id]);$stored=$s->fetch(PDO::FETCH_ASSOC);
        $receipt=['kind'=>$kind,'id'=>$id,'direction'=>$direction,'amount_cents'=>$cents,'date'=>$date,'currency'=>'CZK','ledger'=>$ledger,'clearing_line'=>$clearing,'counterparty_account'=>$kind==='bank'?($row['counterparty_account']??null):null,'counterparty_bank'=>$kind==='bank'?($row['counterparty_bank']??null):null,'partner_name'=>$kind==='cash'?$row['partner_name']:null];
        $revision=[];
        if($stored) {
            if($stored['amount_cents']!==$cents||$stored['direction']!==$direction||(int)$stored['journal_entry_id']!==$ledger['entry']['id'])PaymentException::reject('source_changed');
            if(!hash_equals($stored['receipt_hash'],PropertyInput::hash(json_decode($stored['receipt_json'],true,flags:JSON_THROW_ON_ERROR)))||!hash_equals($stored['receipt_hash'],PropertyInput::hash($receipt)))PaymentException::reject('source_changed');
            $s=$pdo->prepare("SELECT amount_cents FROM unit_payment_operations WHERE supplier_id=? AND source_id=? AND status='posted' AND kind IN ('allocation','refund') ORDER BY id FOR UPDATE");$s->execute([$supplier,$stored['id']]);$used='0';foreach($s->fetchAll(PDO::FETCH_COLUMN) as $part)$used=bcadd($used,$part,0);
            if($used!==$stored['consumed_cents'])PaymentException::reject('source_capacity_corrupt');
            $history=(new \MyInvoice\Service\Property\Payments\Workflow\ClearingRevisionHistory($this->db))->current($supplier,$stored,$receipt);
            if($history['revision_id']!==null) {
                $revision=$history; $cellUsed=[];
                $s=$pdo->prepare("SELECT amount_cents,snapshot_json,snapshot_hash FROM unit_payment_operations WHERE supplier_id=? AND source_id=? AND status='posted' AND kind IN ('allocation','refund') ORDER BY id FOR UPDATE");$s->execute([$supplier,$stored['id']]);
                foreach($s->fetchAll(PDO::FETCH_ASSOC) as $operation) {
                    $snapshot=json_decode($operation['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
                    if(!hash_equals($operation['snapshot_hash'],PropertyInput::hash($snapshot)))PaymentException::reject('source_capacity_corrupt');
                    $selection=$snapshot['clearing_selection']??[['key'=>'original','amount_cents'=>$operation['amount_cents']]];
                    foreach(\MyInvoice\Service\Property\Payments\Workflow\ClearingCells::consume($history['clearing_cells'],$cellUsed,$operation['amount_cents'],$selection) as $part)$cellUsed[$part['key']]=bcadd($cellUsed[$part['key']]??'0',$part['amount_cents'],0);
                }
                $revision['cell_consumed']=$cellUsed;
            }
        }
        return ['receipt'=>$receipt,'id'=>$stored?(int)$stored['id']:null,'version'=>$stored?(int)$stored['row_version']:0,'consumed_cents'=>$stored['consumed_cents']??'0','available_cents'=>bcsub($cents,$stored['consumed_cents']??'0',0)]+$revision;
    }
}
