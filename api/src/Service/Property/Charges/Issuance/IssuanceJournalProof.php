<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class IssuanceJournalProof
{
    public function __construct(private readonly Connection $db) {}

    public static function lines(array $charge): array
    {
        $out=[];
        foreach($charge['snapshot']['lines'] as $line) {
            $amount=bcdiv(ltrim($line['amount_cents'],'-'),'100',2);
            $negative=str_starts_with($line['amount_cents'],'-');
            $origins=[];
            foreach($line['dimensions'] as $type=>$value) $origins[$type]=['origin'=>'module','ref'=>'unit_charge:'.$charge['id']];
            foreach([[$line['account']['receivable_account'],$negative?'credit':'debit'],[$line['account']['counter_account'],$negative?'debit':'credit']] as [$code,$side])
                $out[]=['account_code'=>$code,'side'=>$side,'amount'=>$amount,'dimensions'=>$line['dimensions'],'dimension_origins'=>$origins];
        }
        return $out;
    }

    public function assertEntry(int $supplier,int $entryId,array $charge,bool $reversal=false): void
    {
        if(!$this->db->pdo()->inTransaction()) throw new \LogicException('Issued ledger proof requires current transaction reads.');
        $stmt=$this->db->pdo()->prepare('SELECT * FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE'); $stmt->execute([$supplier,$entryId]);
        $entry=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$entry||$entry['source_type']!=='unit_charge'||$entry['posted_at']===null||(!$reversal&&(int)$entry['source_id']!==$charge['id'])) self::fail();
        $expectedReversal=!$reversal&&($charge['status']??null)==='reversed'?($charge['reversal_entry_id']??null):null;
        if(($entry['reversed_by']===null?null:(int)$entry['reversed_by'])!==$expectedReversal||(!$reversal&&$entry['entry_date']!==$charge['accounting_date'])) self::fail();
        if($reversal) {
            if($entry['source_id']!==null) self::fail();
            $stmt=$this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE');
            $stmt->execute([$supplier,$charge['journal_entry_id']]);
            if((int)$stmt->fetchColumn()!==$entryId) self::fail();
        }
        $stmt=$this->db->pdo()->prepare('SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY l.line_no,l.id FOR UPDATE');
        $stmt->execute([$supplier,$entryId]); $actual=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $expected=self::lines($charge);
        if(count($actual)!==count($expected)) self::fail();
        $assignments=new DimensionAssignmentRepository($this->db); $ids=array_column($actual,'id');
        $dims=$assignments->lineDimensions($supplier,$ids,true); $splits=$assignments->lineSplits($supplier,$ids,true); $origins=$assignments->lineOrigins($supplier,$ids,true);
        foreach($actual as $i=>$line) {
            $want=$expected[$i]; $side=$reversal?($want['side']==='debit'?'credit':'debit'):$want['side'];
            if($line['account_code']!==$want['account_code']||$line['side']!==$side||JournalLineAmount::decimal($line['amount'])!==$want['amount']
                ||!empty($line['is_red_storno'])||$line['currency_code']!==null||$line['amount_foreign']!==null||$line['fx_rate']!==null
                ||$line['cost_center']!==null||$line['project_id']!==null
                ||PropertyInput::hash($dims[$line['id']]??[])!==PropertyInput::hash($want['dimensions'])||($splits[$line['id']]??[])!==[]
                ||PropertyInput::hash($origins[$line['id']]??[])!==PropertyInput::hash($want['dimension_origins'])) self::fail();
        }
    }

    private static function fail(): never
    {
        throw new IssuanceException('property_charge_issue_ledger_mismatch','Deník neodpovídá přesným částkám a dimenzím předpisu; operace byla vrácena.',409);
    }
}
