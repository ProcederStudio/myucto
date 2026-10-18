<?php

declare(strict_types=1);
namespace MyInvoice\Benchmark;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\{FinancialHistoryRepository,PartyEventRepository,PropertySaldoReadRepository};
use MyInvoice\Service\Property\Receivables\{PropertyReceivableProjection,PropertySaldoSummary};
use PDO;
final class PropertyBenchmarkRead
{
    public function __construct(private readonly Connection $db){}
    public function page(int $supplier,string $asOf,string $knownAt,int $limit=25,?string $cursor=null):array
    {
        $p=$this->db->pdo();$before=self::counters($p);$started=hrtime(true);$page=(new PropertySaldoReadRepository($this->db))->page($supplier,$asOf,$knownAt,$limit,$cursor);$ms=(hrtime(true)-$started)/1e6;$after=self::counters($p);$delta=[];foreach($after as $key=>$value)$delta[$key]=(int)$value-(int)$before[$key];
        foreach(['Com_insert','Com_update','Com_delete'] as $key)if($delta[$key]!==0)throw new \RuntimeException('Saldo read performed DML');
        foreach($page['items'] as $item)if(!$item['coverage']['financial_complete'])throw new \RuntimeException('Incomplete financial coverage is not benchmark success');
        if($p->inTransaction())throw new \RuntimeException('Saldo left an open transaction');
        return ['ms'=>$ms,'units'=>count($page['items']),'delta'=>$delta,'sha256'=>hash('sha256',json_encode($page['items'],JSON_THROW_ON_ERROR)),'page'=>$page];
    }
    public function reference(int $supplier,string $asOf,string $knownAt,array $units):array
    {
        $p=$this->db->pdo();$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->exec('START TRANSACTION READ ONLY');
        try{$history=(new FinancialHistoryRepository($this->db))->readProjection($supplier);$events=(new PartyEventRepository($this->db))->history($supplier);$q=$p->prepare('SELECT id FROM unit_payment_allocations WHERE supplier_id=? AND accessory_id IS NOT NULL');$q->execute([$supplier]);$accessories=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);$out=[];
            foreach($units as $unit){$card=PropertyReceivableProjection::reduceReadHistory($supplier,$asOf,$knownAt,['unit_id'=>(int)$unit['id']],$history,$events);$out[]=PropertySaldoSummary::fromCard($card,$accessories)+['unit_code'=>$unit['code']];}
            $p->commit();return $out;
        }catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    }
    public static function counters(PDO $pdo):array
    {return $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Com_insert','Com_update','Com_delete','Rows_read','Rows_sent')")->fetchAll(PDO::FETCH_KEY_PAIR);}
}
