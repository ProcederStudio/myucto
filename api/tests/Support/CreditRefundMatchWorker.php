<?php

declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Bank\StatementMatcher;

$database = (string) (getenv('MYINVOICE_DB_NAME') ?: '');
if ($database === '' || !str_ends_with($database, '_test')) {
    fwrite(STDERR, "Credit refund worker requires MYINVOICE_DB_NAME ending in _test.\n");
    exit(3);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$transactionId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$documentKind = (string) ($argv[2] ?? '');
$documentId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$barrier = (string) ($argv[4] ?? '');
$readyFile = (string) ($argv[5] ?? '');
$resultFile = (string) ($argv[6] ?? '');
$snapshotIsolation = strtolower((string) ($argv[7] ?? ''));
if ($transactionId === false
    || !in_array($documentKind, ['issued', 'purchase'], true)
    || $documentId === false
    || $barrier === ''
    || $readyFile === ''
    || $resultFile === ''
    || !in_array($snapshotIsolation, ['on', 'off'], true)
) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(2);
}

$pdo = null;
try {
    $container = Bootstrap::buildContainer();
    $db = $container->get(Connection::class);
    $pdo = $db->pdo();
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . strtoupper($snapshotIsolation));
    $matcher = $container->get(StatementMatcher::class);
    $maxAttempts = $snapshotIsolation === 'on' ? 3 : 1;
    $attempt = 0;
    $abortedAttempts = 0;
    while (true) {
        ++$attempt;
        try {
            $pdo->beginTransaction();
            if ($attempt === 1) {
                $targetColumn = $documentKind === 'issued' ? 'invoice_id' : 'purchase_invoice_id';
                $snapshot = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE {$targetColumn} = ?"
                );
                $snapshot->execute([(int) $documentId]);
                $snapshot->fetchColumn();

                file_put_contents($readyFile, json_encode([
                    'ready' => true,
                    'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
                    'isolation' => (string) $pdo->query('SELECT @@tx_isolation')->fetchColumn(),
                    'snapshot_isolation' => (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn() === 1,
                    'snapshot_established' => true,
                ], JSON_THROW_ON_ERROR));

                $deadline = microtime(true) + 15.0;
                clearstatcache(true, $barrier);
                while (!is_file($barrier)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('barrier_timeout');
                    }
                    usleep(10_000);
                    clearstatcache(true, $barrier);
                }
            }

            $result = $matcher->match((int) $transactionId);
            $pdo->commit();
            $result['attempts'] = $attempt;
            $result['aborted_attempts'] = $abortedAttempts;
            break;
        } catch (Throwable $e) {
            $aborted = TransactionFailure::rollbackIfAborted($pdo, $e);
            if (!$aborted && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (PDOException) {
                }
            }
            if ($aborted && $snapshotIsolation === 'on' && $attempt < $maxAttempts) {
                ++$abortedAttempts;
                continue;
            }
            throw $e;
        }
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (PDOException) {
        }
    }
    $result = [
        'status' => 'worker_error',
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'attempts' => $attempt ?? 0,
        'aborted_attempts' => $abortedAttempts ?? 0,
    ];
}

file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
