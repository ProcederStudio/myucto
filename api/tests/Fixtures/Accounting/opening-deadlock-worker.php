<?php

declare(strict_types=1);

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

$root = dirname(__DIR__, 4);
require $root . '/api/vendor/autoload.php';
$config = Config::load($root);
if (!str_ends_with((string) $config->get('db.name'), '_test')) throw new RuntimeException('Test database required.');
$supplier = (int) ($argv[1] ?? 0);
$first = (int) ($argv[2] ?? 0);
if ($supplier <= 0 || $first <= 0) throw new RuntimeException('Synthetic fixture identifiers required.');
$pdo = (new Connection($config))->pdo();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
$pdo->beginTransaction();
$stmt = $pdo->prepare("UPDATE chart_of_accounts FORCE INDEX(PRIMARY) SET name='Synthetic worker lock' WHERE id>? AND supplier_id=?");
$stmt->execute([$first, $supplier]);
if ($stmt->rowCount() !== 2000) throw new RuntimeException('Incomplete synthetic deadlock fixture.');
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') throw new RuntimeException('Missing synchronization.');
$pdo->prepare("UPDATE chart_of_accounts SET name='Synthetic worker complete' WHERE supplier_id=? AND id=?")->execute([$supplier, $first]);
$pdo->commit();
fwrite(STDOUT, "DONE\n");
