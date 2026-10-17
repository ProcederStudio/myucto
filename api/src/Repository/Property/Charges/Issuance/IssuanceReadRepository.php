<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use PDO;

final class IssuanceReadRepository
{
    public function __construct(private readonly Connection $db) {}

    public function charges(int $supplier, bool $current = false): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges WHERE supplier_id=? ORDER BY id'.$this->lock($current));
        $stmt->execute([$supplier]);
        $rows=array_map($this->decodeCharge(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
        $this->assertMaterialized($supplier,$rows,$current);
        return $rows;
    }

    public function find(int $supplier,int $id,bool $current = false): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges WHERE supplier_id=? AND id=?'.$this->lock($current));
        $stmt->execute([$supplier,$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new IssuanceException('property_charge_issue_not_found','Předpis nebyl nalezen.',404);
        $charge=$this->decodeCharge($row);
        $this->assertMaterialized($supplier,[$charge],$current);
        return $charge;
    }

    public function batch(int $supplier,int $id,bool $current = false): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_batches WHERE supplier_id=? AND id=?'.$this->lock($current));
        $stmt->execute([$supplier,$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new IssuanceException('property_charge_issue_not_found','Dávka předpisů nebyla nalezena.',404);
        $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
        $snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        self::hash($row['preview_hash'],$snapshot);
        self::hash($row['request_hash'],['request'=>$request,'preview_hash'=>$row['preview_hash'],'idempotency_key'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
        if(PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request'])) self::corrupt();
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges WHERE supplier_id=? AND batch_id=? ORDER BY unit_id'.$this->lock($current));
        $stmt->execute([$supplier,$id]); $charges=array_map($this->decodeCharge(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
        if(count($charges)!==count($snapshot['charges'])) self::corrupt();
        $expected=array_column($snapshot['charges'],null,'unit_id');
        foreach($charges as $charge) if(!isset($expected[$charge['unit_id']])||PropertyInput::hash($expected[$charge['unit_id']])!==$charge['snapshot_hash']) self::corrupt();
        $this->assertMaterialized($supplier,$charges,$current);
        return ['id'=>(int)$row['id'],'supplier_id'=>$supplier,'version_id'=>(int)$row['version_id'],'request'=>$request,'snapshot'=>$snapshot,
            'preview_hash'=>$row['preview_hash'],'created_by'=>(int)$row['created_by'],'recorded_at'=>$row['recorded_at'],'charges'=>$charges];
    }

    public function evidence(int $supplier,int $chargeId,bool $current=false): array
    {
        $this->find($supplier,$chargeId,$current);
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_evidence_events WHERE supplier_id=? AND charge_id=? ORDER BY id'.$this->lock($current));
        $stmt->execute([$supplier,$chargeId]); $out=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
            self::hash($row['request_hash'],['charge_id'=>$chargeId,'request'=>$request,'idempotency_key'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
            if($row['kind']!==$request['kind']||$row['effective_at']!==\MyInvoice\Service\Property\PropertyInstant::sql($request['effective_at'])) self::corrupt();
            $out[]=['id'=>(int)$row['id'],'charge_id'=>$chargeId,'request'=>$request,'created_by'=>(int)$row['created_by'],'recorded_at'=>$row['recorded_at']];
        }
        return $out;
    }

    private function decodeCharge(array $row): array
    {
        $snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        self::hash($row['snapshot_hash'],$snapshot);
        foreach(['id','supplier_id','batch_id','unit_id','revision_no','previous_charge_id','row_version','journal_entry_id','reversal_entry_id'] as $field) if($row[$field]!==null) $row[$field]=(int)$row[$field];
        foreach(['unit_id','revision_no','previous_charge_id','title','issue_mode','period_from','period_to','accounting_date','due_date','source_key','amount_cents'] as $field) if($row[$field]!==$snapshot[$field]) self::corrupt();
        foreach(['claim_origin_at','issued_at'] as $field) if($row[$field]!==\MyInvoice\Service\Property\PropertyInstant::sql($snapshot[$field])) self::corrupt();
        unset($row['snapshot_json']);
        return $row+['snapshot'=>$snapshot];
    }

    private function assertMaterialized(int $supplier,array $charges,bool $current): void
    {
        if($charges===[]) return;
        $ids=array_column($charges,'id'); sort($ids,SORT_NUMERIC);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $actual=['lines'=>[],'parties'=>[],'notices'=>[]];
        foreach(['lines','parties','notices'] as $kind) {
            $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_'.$kind.' WHERE supplier_id=? AND charge_id IN ('.$marks.') ORDER BY charge_id,id'.$this->lock($current));
            $stmt->execute([$supplier,...$ids]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id=(int)$row['charge_id'];
                if($kind==='lines') $actual[$kind][$id][(int)$row['component_id']]=['component_id'=>(int)$row['component_id'],
                    'service_id'=>$row['service_id']===null?null:(int)$row['service_id'],'amount_cents'=>$row['amount_cents'],
                    'line'=>json_decode($row['line_json'],true,flags:JSON_THROW_ON_ERROR)];
                elseif($kind==='parties') $actual[$kind][$id][$row['role'].':'.$row['client_id']]=['client_id'=>(int)$row['client_id'],
                    'group_key'=>$row['group_key'],'role'=>$row['role'],'group'=>json_decode($row['party_json'],true,flags:JSON_THROW_ON_ERROR)];
                else $actual[$kind][$id][(int)$row['component_id']]=json_decode($row['notice_json'],true,flags:JSON_THROW_ON_ERROR);
            }
        }
        foreach($charges as $charge) {
            $expected=['lines'=>[],'parties'=>[],'notices'=>[]];
            foreach($charge['snapshot']['lines'] as $line) $expected['lines'][$line['component_id']]=['component_id'=>$line['component_id'],
                'service_id'=>$line['service_id'],'amount_cents'=>$line['amount_cents'],'line'=>$line];
            foreach($charge['snapshot']['liability_groups'] as $group) foreach($group['members'] as $client) $expected['parties'][$group['role'].':'.$client]=[
                'client_id'=>$client,'group_key'=>$group['group_key'],'role'=>$group['role'],'group'=>$group];
            foreach($charge['snapshot']['notices'] as $notice) $expected['notices'][$notice['component_id']]=$notice['notice'];
            foreach($expected as $kind=>$rows) if(PropertyInput::hash($rows)!==PropertyInput::hash($actual[$kind][$charge['id']]??[])) self::corrupt();
        }
    }

    private function lock(bool $current): string
    {
        if($current&&!$this->db->pdo()->inTransaction()) throw new \LogicException('Issuance current reads require period/catalog locks.');
        return $current?' FOR UPDATE':'';
    }

    public static function hash(string $hash,array $payload): void
    {
        if(!hash_equals($hash,PropertyInput::hash($payload))) self::corrupt();
    }

    public static function corrupt(): never
    {
        throw new IssuanceException('property_charge_issue_history_corrupt','Historický předpis neodpovídá uloženému dokladu a otisku.',409);
    }
}
