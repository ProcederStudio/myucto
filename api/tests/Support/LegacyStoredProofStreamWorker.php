<?php

declare(strict_types=1);

use MyInvoice\Service\Anonymization\LegacyStoredProofStreamAdapter;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$rows = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$mutant = ($argv[2] ?? 'valid') === 'mutant';
if (!is_int($rows) || $rows < 1) throw new InvalidArgumentException('Missing legacy proof row count.');

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-legacy-proof-worker-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create legacy proof worker directory.');
$paths = [
    'rows' => $directory . DIRECTORY_SEPARATOR . 'rows.json',
    'source_lines' => $directory . DIRECTORY_SEPARATOR . 'source-lines.json',
    'result' => $directory . DIRECTORY_SEPARATOR . 'result.json',
    'record' => $directory . DIRECTORY_SEPARATOR . 'record.json',
];

try {
    writeRows($paths['rows'], $rows);
    writeSourceLines($paths['source_lines'], $rows, $mutant);
    $ruleset = NonprofitTaxRules::VERSION;
    $resultPrefix = '{"accounting_result_cents":' . $rows . ',"assessed_result_cents":' . $rows
        . ',"cost_addbacks_cents":0,"excluded_revenue_cents":0,"rows":';
    $resultSuffix = ',"ruleset":' . json_encode($ruleset, JSON_THROW_ON_ERROR)
        . ',"scope":"classification_bridge_before_general_adjustments"}';
    writeComposite($paths['result'], $resultPrefix, $paths['rows'], $resultSuffix);

    $rowsHash = hashFile($paths['rows']);
    $sourceLinesHash = hashFile($paths['source_lines']);
    $resultHash = hashFile($paths['result']);
    $period = ['id' => 91, 'fiscal_year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'];
    $receipt = [
        'version' => 1,
        'ruleset' => $ruleset,
        'assessment_id' => 701,
        'period' => $period,
        'input_hash' => str_repeat('a', 64),
        'request_hash' => str_repeat('b', 64),
        'snapshot_hash' => str_repeat('c', 64),
        'assessment_hash' => str_repeat('d', 64),
        'result_hash' => $resultHash,
    ];
    $receipt['receipt_hash'] = NonprofitTaxRules::hash($receipt);

    writeRecord($paths, $rows, $period, $receipt, $rowsHash, $sourceLinesHash);
    gc_collect_cycles();
    $baseline = memory_get_usage(true);
    memory_reset_peak_usage();
    $started = hrtime(true);
    try {
        $verified = (new LegacyStoredProofStreamAdapter($paths['record']))->verify();
        $rejected = false;
        $error = null;
    } catch (Throwable $exception) {
        $verified = null;
        $rejected = true;
        $error = $exception->getMessage();
    }

    echo json_encode([
        'rows' => $verified?->rowCount,
        'profile' => $verified?->profile,
        'rejected' => $rejected,
        'error' => $error,
        'peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $baseline),
        'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    foreach ($paths as $path) if (is_file($path)) unlink($path);
    if (is_dir($directory)) rmdir($directory);
}

function writeRows(string $path, int $rows): void
{
    $handle = openWrite($path);
    try {
        writeAll($handle, '[');
        for ($row = 1; $row <= $rows; ++$row) {
            $sourceId = sourceId($row);
            writeAll($handle, ($row === 1 ? '' : ',') . json_encode([
                'adjustment_cents' => 0,
                'amount_cents' => 1,
                'classification' => ['legal_basis' => 'zdp_subject_synthetic', 'method' => 'return', 'regime' => 'subject'],
                'id' => $sourceId,
                'kind' => 'revenue',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        writeAll($handle, ']');
    } finally {
        fclose($handle);
    }
}

function writeSourceLines(string $path, int $rows, bool $mutant): void
{
    $handle = openWrite($path);
    try {
        writeAll($handle, '[');
        for ($row = 1; $row <= $rows; ++$row) {
            writeAll($handle, ($row === 1 ? '' : ',') . json_encode([
                'amount_cents' => $mutant && $row === $rows ? 2 : 1,
                'line' => null,
                'source_id' => sourceId($row),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        writeAll($handle, ']');
    } finally {
        fclose($handle);
    }
}

function writeRecord(array $paths, int $rows, array $period, array $receipt, string $rowsHash, string $sourceLinesHash): void
{
    $handle = openWrite($paths['record']);
    try {
        writeAll($handle, '{"podklady":{"nonprofit_calculation":{"version":3,"data":{"period":');
        writeAll($handle, json_encode($period, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        writeAll($handle, ',"nonprofit":{"assessment":{"result":');
        copyFile($handle, $paths['result']);
        writeAll($handle, '},"bridge":{"line_cents":{"10":' . $rows . ',"40":0,"100":0,"101":0,"109":0,"110":0,"120":0},"source_lines":');
        copyFile($handle, $paths['source_lines']);
        writeAll($handle, ',"external_adjustments":[],"scope":"classification_only","general_adjustments_included":false}},"j6_receipt":');
        writeAll($handle, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        writeAll($handle, '},"line_coverage":{"count":' . $rows . ',"j6_rows_hash":"' . $rowsHash . '","source_lines_hash":"' . $sourceLinesHash . '","source_ids":[');
        for ($row = 1; $row <= $rows; ++$row) {
            writeAll($handle, ($row === 1 ? '' : ',') . json_encode(sourceId($row), JSON_THROW_ON_ERROR));
        }
        writeAll($handle, ']}}}}');
    } finally {
        fclose($handle);
    }
}

function hashFile(string $path): string
{
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) throw new RuntimeException('Cannot hash legacy proof worker file.');
    return $hash;
}

function writeComposite(string $output, string $prefix, string $embedded, string $suffix): void
{
    $handle = openWrite($output);
    try {
        writeAll($handle, $prefix);
        copyFile($handle, $embedded);
        writeAll($handle, $suffix);
    } finally {
        fclose($handle);
    }
}

function sourceId(int $row): string
{
    return 'source-' . str_pad((string) $row, 6, '0', STR_PAD_LEFT);
}

function openWrite(string $path)
{
    $handle = fopen($path, 'w+b');
    if (!is_resource($handle)) throw new RuntimeException('Cannot open legacy proof worker file.');
    return $handle;
}

function copyFile($output, string $path): void
{
    foreach (fileChunks($path) as $chunk) writeAll($output, $chunk);
}

function fileChunks(string $path): iterable
{
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) throw new RuntimeException('Cannot read legacy proof worker file.');
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 524_288);
            if (!is_string($chunk)) throw new RuntimeException('Cannot read legacy proof worker file.');
            if ($chunk !== '') yield $chunk;
        }
    } finally {
        fclose($handle);
    }
}

function writeAll($handle, string $bytes): void
{
    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = fwrite($handle, substr($bytes, $offset));
        if (!is_int($written) || $written < 1) throw new RuntimeException('Cannot write legacy proof worker file.');
        $offset += $written;
    }
}
