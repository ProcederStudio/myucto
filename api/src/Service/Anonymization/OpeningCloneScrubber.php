<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use PDO;
final class OpeningCloneScrubber
{
    public function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber){}
    public function run(array $registryMap,callable $text,callable $references,callable $map):void
    {
        $pdo=$this->db->pdo();if(!$pdo->inTransaction()||(int)$pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn()!==0)throw new \LogicException('Opening clone requires the clone transaction before trigger installation.');
        $batches=$pdo->query('SELECT * FROM property_opening_batches ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $items=$pdo->query('SELECT * FROM property_opening_items ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        foreach($items as $item)self::decode($item,'snapshot');
        foreach($batches as $row){$request=$text(self::decode($row,'request'));$snapshot=$references($this->transform(self::decode($row,'snapshot'),(int)$row['supplier_id'],$registryMap,$text));$snapshot['request']=$request;$this->update('property_opening_batches',$row,['request_json'=>self::json($request),'request_hash'=>$map($row['request_hash'],['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]),'snapshot_json'=>self::json($snapshot),'snapshot_hash'=>$map($row['snapshot_hash'],$snapshot)]);}
        foreach($items as $row){$snapshot=$this->transform(self::decode($row,'snapshot'),(int)$row['supplier_id'],$registryMap,$text);$this->update('property_opening_items',$row,['case_key'=>$snapshot['case_key'],'snapshot_json'=>self::json($snapshot),'snapshot_hash'=>$map($row['snapshot_hash'],$snapshot)]);}
        foreach($pdo->query('SELECT * FROM property_opening_components ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=$text(self::decode($row,'fact'));$this->update('property_opening_components',$row,['fact_json'=>self::json($fact),'fact_hash'=>$map($row['fact_hash'],$fact)]);}
        foreach($pdo->query('SELECT * FROM property_opening_parties ORDER BY supplier_id,item_id,group_key,client_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=$text(json_decode($row['fact_json'],true,flags:JSON_THROW_ON_ERROR));$q=$pdo->prepare('UPDATE property_opening_parties SET fact_json=? WHERE supplier_id=? AND item_id=? AND group_key=? AND client_id=?');$q->execute([self::json($fact),$row['supplier_id'],$row['item_id'],$row['group_key'],$row['client_id']]);}
        foreach($pdo->query('SELECT * FROM property_opening_states ORDER BY supplier_id,item_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row){$value=$text($row);$q=$pdo->prepare('UPDATE property_opening_states SET purpose_ref=?,counterpart_ref=? WHERE supplier_id=? AND item_id=?');$q->execute([$value['purpose_ref'],$value['counterpart_ref'],$row['supplier_id'],$row['item_id']]);}
        foreach($items as $item)(new OpeningReadProof($this->db))->item((int)$item['supplier_id'],(int)$item['id'],true);
    }
    private function transform(array $node,int $supplier,array $registryMap,callable $text):array
    {
        $node=$this->registry($node,$supplier,$registryMap);$node=$text($node);$this->verifyRegistry($node);return $node;
    }
    private function registry(array $node,int $supplier,array $registryMap):array
    {
        if(isset($node['kind'],$node['revision']['state_hash'],$node['revision']['state'])){
            $revision=&$node['revision'];$proof=$registryMap[$supplier][$revision['id']]??throw new \LogicException('Opening registry clone proof missing.');
            if($revision['state_hash']!==$proof['before_state_hash']||PropertyInput::hash($revision['state'])!==$revision['state_hash'])throw new \LogicException('Opening registry clone source mismatch.');
            $revision['state']=(new PropertyCloneScrubber($this->db->pdo(),$this->scrubber))->scrubSnapshotState($node['kind'],$revision['state']);$revision['state_hash']=$proof['after_state_hash'];unset($revision);
        }
        foreach($node as &$value)if(is_array($value))$value=$this->registry($value,$supplier,$registryMap);unset($value);return $node;
    }
    private function verifyRegistry(array $node):void
    {
        if(isset($node['revision']['state_hash'],$node['revision']['state'])&&PropertyInput::hash($node['revision']['state'])!==$node['revision']['state_hash'])throw new \LogicException('Opening registry clone target mismatch.');foreach($node as $value)if(is_array($value))$this->verifyRegistry($value);
    }
    private function update(string $table,array $row,array $changes):void{$this->db->pdo()->prepare('UPDATE '.$table.' SET '.implode(',',array_map(fn($key)=>$key.'=?',array_keys($changes))).' WHERE supplier_id=? AND id=?')->execute([...array_values($changes),$row['supplier_id'],$row['id']]);}
    private static function decode(array $row,string $key):array{$value=json_decode($row[$key.'_json'],true,flags:JSON_THROW_ON_ERROR);if($key!=='request'&&PropertyInput::hash($value)!==$row[$key.'_hash'])throw new \LogicException('Opening clone source hash mismatch.');return $value;}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
}
