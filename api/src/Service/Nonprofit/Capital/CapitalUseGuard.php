<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\JournalLineComparison;
use MyInvoice\Service\Accounting\PostingException;
final class CapitalUseGuard
{
    private readonly JournalEntryRepository $journal;
    public function __construct(private readonly Connection $db,?JournalEntryRepository $journal=null)
    {$this->journal=$journal??new JournalEntryRepository($db,new \MyInvoice\Service\ActivityLogger($db));}
    public static function assertGenericInsert(array $header):void
    {
        if(($header['source_type']??null)==='nonprofit_capital')throw new PostingException('nonprofit_capital_owner_required','Kapitálový zápis vyžaduje vlastníka a živý účetní proof.',409);
    }
    public function assertEntryChange(int $supplier,int $entry,?array $replacementLines,?string $replacementDate,?array $replacementHeader=null):bool
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Capital mutation guard requires a caller transaction.');
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplier);
        $protected=[];
        foreach((new CapitalRepository($this->db))->sources($supplier)as$source)$protected[$source['basis']['source']['entry_id']]=true;
        foreach((new CapitalService($this->db))->canonicalEvents($supplier)as$event){
            $protected[$event['entry_id']]=true;
            if($event['active']){
                if($event['cost_line_id']!==null)$protected[$event['snapshot']['cost']['entry_id']]=true;
                foreach($event['snapshot']['depreciation']['history']??[]as$native)$protected[$native['entry']['id']]=true;
            }
        }
        if(!isset($protected[$entry]))return false;
        $current=$this->journal->findForUpdate($entry,$supplier);if(!$current||$replacementLines===null||$replacementDate!==$current['entry_date'])self::used();
        $repo=new DimensionAssignmentRepository($this->db);$ids=array_column($current['lines'],'id');$dims=$repo->lineDimensions($supplier,$ids,true);$splits=$repo->lineSplits($supplier,$ids,true);
        foreach($current['lines']as&$line){$line['dimensions']=$dims[$line['id']]??[];$line['dimension_splits']=$splits[$line['id']]??[];}unset($line);
        if(!JournalLineComparison::sameLines($current['lines'],$replacementLines))self::used();
        if($replacementHeader!==null){foreach(['period_id','source_type','source_id','document_date','document_no','description']as$key)if((string)($replacementHeader[$key]??'')!==(string)($current[$key]??''))self::used();if(($replacementHeader['posted_at']??null)===null)self::used();}
        return true;
    }
    public function assertEntriesMutable(int $supplier,array $entries):void{foreach(array_unique(array_map('intval',$entries))as$entry)$this->assertEntryChange($supplier,$entry,null,null);}
    public function assertAssetChange(int $supplier,int $asset,?array $replacement):void
    {
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplier);
        $service=new CapitalService($this->db);if(!$service->hasAssetSources($supplier,$asset))return;
        $basis=$service->assetBasis($supplier,$asset);
        if($replacement!==null
            && \MyInvoice\Service\Accounting\JournalLineAmount::cents($replacement['input_price'])===$basis['input_cents']
            && \MyInvoice\Service\Accounting\JournalLineAmount::cents($replacement['opening_acc_amount'])===$basis['opening_acc_cents']
            && $replacement['depreciator_ground']==='owner'&&$replacement['accumulated_account_code']!==null)return;
        throw new \MyInvoice\Service\Accounting\Assets\AssetException('nonprofit_capital_asset_basis_in_use','Ocenění majetku tvoří doložený investiční zdroj. Změna vyžaduje řízenou změnu tohoto zdroje.',409);
    }
    public function assertLinesMutable(int $supplier,array $ids):void
    {
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplier);
        foreach(array_unique(array_map('intval',$ids))as$id){$q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$id]);$entry=$q->fetchColumn();if($entry!==false)$this->assertEntriesMutable($supplier,[(int)$entry]);}
    }
    public function assertSourceMutable(int $supplier,string $kind,int $id):void
    {
        $entries=(new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockSources($supplier,[[$kind,$id]]);
        $this->assertEntriesMutable($supplier,array_map('intval',array_keys($entries)));
    }
    public function assertBankStatementMutable(int $supplier,int $id):void
    {
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplier);
        $q=$this->db->pdo()->prepare("SELECT e.id FROM journal_entries e JOIN bank_transactions b ON b.supplier_id=e.supplier_id AND e.source_type='bank' AND e.source_id=b.id WHERE e.supplier_id=? AND b.statement_id=? ORDER BY e.id FOR UPDATE");$q->execute([$supplier,$id]);
        $this->assertEntriesMutable($supplier,array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN)));
    }
    public function assertReversal(int $supplier,int $entry):void{$this->assertEntryChange($supplier,$entry,null,null);}
    private static function used():never{throw new PostingException('nonprofit_capital_source_in_use','Účetní zápis tvoří doložený kapitálový zdroj nebo čerpání. Vyžaduje se řízené storno.',409);}
}
