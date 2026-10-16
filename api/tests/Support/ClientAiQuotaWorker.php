<?php

declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Service\Import\ClientAiExtractionQuota;

require dirname(__DIR__) . '/bootstrap.php';

$supplierId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$barrier = $argv[2] ?? '';
$worker = $argv[3] ?? '';
if ($supplierId === false || $barrier === '' || !ctype_digit($worker)) {
    exit(2);
}
touch($barrier . '.ready-' . $worker);
$deadline = microtime(true) + 15;
while (!is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        exit(3);
    }
    usleep(10000);
}
try {
    $quota = Bootstrap::buildContainer()->get(ClientAiExtractionQuota::class);
    echo $quota->reserve($supplierId) === null ? '0' : '1';
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage());
    exit(1);
}
