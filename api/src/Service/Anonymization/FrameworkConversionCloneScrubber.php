<?php

declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository,FrameworkConversionRepository as R,ClosingRepository};
use MyInvoice\Service\Accounting\Closing\ProfitDistribution504Builder;
use MyInvoice\Service\Accounting\Conversion\{FrameworkConversionFxAdapter,FrameworkConversionException as E};
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use PDO;
final class FrameworkConversionCloneScrubber
{
    public const TABLES=['accounting_framework_conversions','accounting_framework_conversion_cells','accounting_framework_conversion_decisions'];
    public const COMMANDS='accounting_framework_conversion_commands';
    private const TEXT=['name','evidence','decision_authority','decision_reference','description','document_no','cost_center','ref','approval_body','approval_decision_ref','note','responsible_note'];
    public static function captureInputs(Connection $db):array
    {
        self::context($db);$p=$db->pdo();$p->beginTransaction();
        try{$rows=self::rows($db);$sources=[];$native=[];
            foreach($rows[self::TABLES[0]] as $row){E::need($row['status']!=='pending','conversion_clone_pending');$supplier=(int)$row['supplier_id'];$r=new R($db);$saved=$r->receipt($supplier,(int)$row['id'],true);$source=self::source($r,$row);E::need(hash_equals($row['source_hash'],$source['source_hash']),'conversion_source_changed');$sources[$row['id']]=$source;
                if($row['status']==='applied'){(new FrameworkConversionFxAdapter($db))->project($supplier,$source['target'],[],[],true);$native[(int)$row['opening_entry_id']]=(new PaymentJournalProof($db))->current($supplier,(int)$row['opening_entry_id']);}
            }
            foreach($rows[self::TABLES[2]] as $decision){$receipt=json_decode($decision['receipt_json'],true,512,JSON_THROW_ON_ERROR);E::need(hash_equals($decision['receipt_hash'],R::hash($receipt)),'conversion_decision_receipt_changed');$ledger=(new PaymentJournalProof($db))->current((int)$decision['supplier_id'],(int)$decision['journal_entry_id']);E::need($receipt['ledger']===$ledger,'conversion_decision_ledger_changed');$native[(int)$decision['journal_entry_id']]=$ledger;}
            $p->commit();return ['rows'=>$rows,'sources'=>$sources,'native'=>$native];
        }catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
    public static function run(Connection $db,TextScrubber $scrubber,array $inputs):void
    {
        self::context($db);$p=$db->pdo();$p->beginTransaction();
        try{$rows=self::rows($db);E::need($rows===($inputs['rows']??null),'conversion_clone_preimage_changed');$hashes=[];$rebased=[];
            foreach($rows[self::TABLES[0]] as $row){$supplier=(int)$row['supplier_id'];$r=new R($db);$saved=$r->receipt($supplier,(int)$row['id'],true);$receipt=self::text($saved['receipt'],$scrubber);$request=self::text($saved['request'],$scrubber);$source=self::source($r,$row);
                E::need(self::financialSource($inputs['sources'][$row['id']])===self::financialSource($source),'conversion_clone_financial_source_changed');
                if($row['status']==='applied'){
                    $ledger=(new PaymentJournalProof($db))->current($supplier,(int)$row['opening_entry_id']);E::need(self::financial($inputs['native'][(int)$row['opening_entry_id']])===self::financial($ledger),'conversion_clone_financial_opening_changed');
                    $newCells=array_column(array_map(static fn($cell)=>['identity'=>R::cellIdentity($cell),'cell'=>$cell],$r->cells($source)),'cell','identity');$mapping=[];
                    foreach($receipt['plan']['mapped_cells'] as $index=>&$mapped){$identity=R::cellIdentity($mapped['source']);$cell=$newCells[$identity]??null;E::need($cell!==null,'conversion_clone_cell_changed');$oldKey=$mapped['source']['cell_key'];$selection=$request['mapping'][$oldKey];$selection['activity_kind']['name']=self::kindName($db,$supplier,$selection['activity_kind']['code']);$mapping[$cell['cell_key']]=$selection;$mapped['source']=$cell;$mapped['target']['cost_center']=$cell['cost_center'];$mapped['target']['classification']=array_intersect_key($selection,array_flip(['activity','activity_kind','tax_regime']));
                        foreach([$index*2,$index*2+1] as $lineIndex){$receipt['plan']['lines'][$lineIndex]['cost_center']=$cell['cost_center'];$receipt['plan']['lines'][$lineIndex]['classification']=$mapped['target']['classification'];$receipt['lines'][$lineIndex]=self::line($receipt['lines'][$lineIndex],$ledger['lines'][$lineIndex]);}
                        $q=$p->prepare('UPDATE accounting_framework_conversion_cells SET cell_key=?,source_json=?,target_json=? WHERE supplier_id=? AND conversion_id=? AND cell_key=?');$q->execute([$cell['cell_key'],R::json($cell),R::json($receipt['lines'][$index*2]),$supplier,$row['id'],$oldKey]);E::need($q->rowCount()===1,'conversion_clone_cell_changed');
                    }unset($mapped);$request['mapping']=$mapping;
                    $actualHeader=$r->rows('SELECT * FROM journal_entries WHERE supplier_id=? AND id=?',[$supplier,$row['opening_entry_id']],true)[0];foreach(array_keys($receipt['header']) as $key)$receipt['header'][$key]=is_int($receipt['header'][$key])?(int)$actualHeader[$key]:$actualHeader[$key];$request['evidence']=$receipt['header']['description'];$receipt['ledger']=$ledger;
                    $dated=array_column((new AccountDefinitionRepository($db))->listForDate($supplier,$row['effective_from'],true),null,'account_code');foreach($request['definitions'] as &$definition)if(isset($dated[$definition['account_code']]))$definition['definition']=AccountDefinitionRepository::definition($dated[$definition['account_code']]);unset($definition);$receipt['plan']['definitions']=$request['definitions'];
                    self::rebaseHistory($receipt['plan']['preimage']['history']);self::rebaseHistory($receipt['postimage']['accounting_account_definition_history']);
                }
                $receipt['request_hash']=R::hash($request);$receipt['plan']['source_hash']=$source['source_hash'];$receipt['plan']['state_hash']=R::hash([$source['source_hash'],$receipt['plan']['preimage']]);$hashes[$row['receipt_hash']]=R::hash($receipt);$rebased[(int)$row['id']]=['receipt'=>$receipt,'source'=>$source];
                $q=$p->prepare('UPDATE accounting_framework_conversions SET source_hash=?,request_json=?,receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?');$q->execute([$source['source_hash'],R::json($request),R::json($receipt),R::hash($receipt),$supplier,$row['id']]);
            }
            foreach($rows[self::TABLES[2]] as $decision){$supplier=(int)$decision['supplier_id'];$request=self::text(json_decode($decision['request_json'],true,512,JSON_THROW_ON_ERROR),$scrubber);$receipt=self::text(json_decode($decision['receipt_json'],true,512,JSON_THROW_ON_ERROR),$scrubber);$ledger=(new PaymentJournalProof($db))->current($supplier,(int)$decision['journal_entry_id']);E::need(self::financial($inputs['native'][(int)$decision['journal_entry_id']])===self::financial($ledger),'conversion_clone_financial_decision_changed');
                $conversion=$rebased[(int)$decision['conversion_id']];$context=$receipt['context'];$native=(new ClosingRepository($db))->pendingResultLines($supplier,$context['target_period_id'],$decision['accounting_date'],'931',$context['source_period_id'],true);$cells=ProfitDistribution504Builder::cells($native);$oldCells=$context['cells'];E::need(count($cells)===count($oldCells),'conversion_clone_decision_cells_changed');$byIdentity=[];foreach($cells as $cell)$byIdentity[R::hash([$cell['source_line_ids'],$cell['available_cents'],$cell['dimensions'],$cell['account_code']])]=$cell;
                $keys=[];foreach($oldCells as $cell){$new=$byIdentity[R::hash([$cell['source_line_ids'],$cell['available_cents'],$cell['dimensions'],$cell['account_code']])]??null;E::need($new!==null,'conversion_clone_decision_cells_changed');$keys[$cell['key']]=$new['key'];}foreach($request['allocations'] as &$allocation)$allocation['cell_key']=$keys[$allocation['cell_key']];unset($allocation);
                $receipt['context']['cells']=$cells;$receipt['context']['snapshot_hash']=ProfitDistribution504Builder::snapshot($cells);$receipt['context']['receipt_hash']=$hashes[$context['receipt_hash']];$receipt['context']['source_approval']=array_intersect_key($conversion['source']['source'],array_flip(['approved_at','approved_by','approval_body','approval_decision_ref','approval_document_hash']));foreach($receipt['lines'] as $i=>&$line)$line=self::line($line,$ledger['lines'][$i]);unset($line);$receipt['ledger']=$ledger;
                $q=$p->prepare('UPDATE accounting_framework_conversion_decisions SET request_json=?,receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?');$q->execute([R::json($request),R::json($receipt),R::hash($receipt),$supplier,$decision['id']]);
            }
            $p->exec('DELETE FROM '.self::COMMANDS);
            foreach($rows[self::TABLES[0]] as $row)if($row['status']==='applied'){ $source=$rebased[(int)$row['id']]['source'];(new FrameworkConversionFxAdapter($db))->project((int)$row['supplier_id'],$source['target'],[],[],true);}
            $p->commit();
        }catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
    private static function source(R $read,array $row):array
    {
        try{return $read->source((int)$row['supplier_id'],(int)$row['source_period_id'],(int)$row['target_period_id'],true);}catch(E $error){if($row['status']==='cancelled')throw new E('conversion_cancelled_source_history_required',409,['cause'=>$error->errorCode]);throw $error;}
    }
    private static function rebaseHistory(array &$rows):void
    {
        foreach($rows as &$row){$definition=AccountDefinitionRepository::definition($row);$row['definition_json']=AccountDefinitionRepository::encode($definition);$row['definition_hash']=AccountDefinitionRepository::definitionHash((int)$row['supplier_id'],(int)$row['account_id'],$row['effective_from'],$definition);}unset($row);
    }
    private static function kindName(Connection $db,int $supplier,string $code):string
    {$q=$db->pdo()->prepare("SELECT v.name FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id AND t.supplier_id=v.supplier_id WHERE v.supplier_id=? AND t.system_key='activity_kind' AND v.code=? FOR UPDATE");$q->execute([$supplier,$code]);$name=$q->fetchColumn();E::need(is_string($name),'conversion_clone_kind_missing');return $name;}
    private static function line(array $before,array $actual):array
    {foreach(['cost_center','project_id','dimensions','dimension_splits','dimension_origins','dimension_split_origins'] as $key)if(array_key_exists($key,$actual))$before[$key]=$actual[$key];return $before;}
    private static function financialSource(array $source):array
    {return self::financial(array_intersect_key($source,array_flip(['source_entry_id','closing','native','bs','pl_native','profit_cents'])));}
    private static function financial(array $value):array
    {foreach($value as $key=>&$item){if(in_array((string)$key,['cost_center','ref','name','account_name','entry_document_no','entry_description'],true))$item=null;elseif(is_array($item))$item=self::financial($item);}unset($item);return $value;}
    private static function text(array $value,TextScrubber $scrubber):array
    {foreach($value as $key=>&$item){if(is_array($item))$item=self::text($item,$scrubber);elseif(is_string($item)&&in_array((string)$key,self::TEXT,true))$item=$scrubber->scrub($item);}unset($item);return $value;}
    private static function rows(Connection $db):array
    {$out=[];foreach(self::TABLES as $table)$out[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY supplier_id,'.($table===self::TABLES[1]?'conversion_id,cell_key':'id').' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);return $out;}
    private static function context(Connection $db):void
    {if($db->pdo()->inTransaction()||(int)$db->pdo()->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn()!==0)throw new \LogicException('Conversion clone requires own transaction before trigger installation.');}
}
