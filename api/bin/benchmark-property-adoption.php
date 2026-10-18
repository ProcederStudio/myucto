<?php

declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/Support/PropertyBenchmarkDataset.php';
require __DIR__.'/Support/PropertyBenchmarkRead.php';
require __DIR__.'/Support/PropertyBenchmarkAdoption.php';
use MyInvoice\Benchmark\{PropertyBenchmarkAdoption,PropertyBenchmarkDataset,PropertyBenchmarkRead};
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Receivables\PropertyReconciliation;
$options=getopt('',['units:','months:','output:']);$units=filter_var($options['units']??12,FILTER_VALIDATE_INT);$months=filter_var($options['months']??2,FILTER_VALIDATE_INT);$output=$options['output']??null;
if(!in_array($units,[12,400],true)||!in_array($months,[2,12],true)||!is_string($output)||!is_dir($output))throw new InvalidArgumentException('Use --units=12|400 --months=2|12 --output=<existing directory>');
$container=Bootstrap::buildContainer();$db=$container->get(Connection::class);$p=$db->pdo();$database=(string)$p->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/^myucto_nno_property_benchmark_[a-z0-9_]+_test$/D',$database)||!in_array($container->get(MyInvoice\Infrastructure\Config\Config::class)->get('db.host'),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Exact owned local synthetic benchmark DB required');
$p->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$start=hrtime(true);$f=(new PropertyBenchmarkDataset($db,$container))->create($units,'2016-01-01','S6 adoption '.$units.'x'.$months);$catalogMs=(hrtime(true)-$start)/1e6;
file_put_contents($output.'/adoption-'.$units.'x'.$months.'-fixture.json',json_encode(['database'=>$database,'fixture'=>$f],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
$result=(new PropertyBenchmarkAdoption($db,$container))->apply($f,$months,'2025-01');$result['catalog_ms']=$catalogMs;$result['seed_memory_limit']=ini_get('memory_limit');$result['seed_peak_bytes']=memory_get_peak_usage(true);echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;fflush(STDOUT);
$start=hrtime(true);$reconciliation=(new PropertyReconciliation($db))->at($f['supplier'],'2026-01-31');$reconcileMs=(hrtime(true)-$start)/1e6;if(!$reconciliation['matches']||$reconciliation['ledger_cents']!==['receivable'=>$result['total_cents'],'credit'=>'0']||$reconciliation['ledger_cents']!==$reconciliation['subledger_cents'])throw new RuntimeException('Native S6 GL/subledger reconciliation failed');
$known=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');$reader=new PropertyBenchmarkRead($db);$current=$reader->page($f['supplier'],'2026-01-31T23:59:59+01:00',$known,25);$before=$reader->page($f['supplier'],'2025-12-31T23:59:59+01:00',$known,25);
$rows=[];foreach(array_slice($f['units'],0,25) as $index=>$id)$rows[]=['id'=>$id,'code'=>sprintf('U%03d',$index+1)];$reference=$reader->reference($f['supplier'],'2026-01-31T23:59:59+01:00',$known,$rows);if($reference!==$current['page']['items'])throw new RuntimeException('Native adopted saldo exact byte reference mismatch');
foreach($current['page']['items'] as $item)if($item['totals']['gross_receivable_cents']!==(string)($months*180000)||$item['claim_count']!==$months)throw new RuntimeException('Exact adopted unit principal count/sum mismatch');foreach($before['page']['items'] as $item)if($item['totals']['gross_receivable_cents']!=='0'||$item['claim_count']!==0)throw new RuntimeException('Adopted claims cannot invent pre-adoption ledger history');
unset($current['page'],$before['page']);$result+=['database'=>$database,'bootstrap_root'=>Bootstrap::rootDir(),'native_reconciliation'=>true,'reconciliation_ms'=>$reconcileMs,'saldo_current'=>$current,'saldo_before_adoption'=>$before,'reference_parity'=>true,'fixture'=>$f];$name='adoption-'.$units.'x'.$months;file_put_contents($output.'/'.$name.'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));echo json_encode(array_diff_key($result,['fixture'=>true]),JSON_THROW_ON_ERROR).PHP_EOL;
