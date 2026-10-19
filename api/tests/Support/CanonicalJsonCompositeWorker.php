<?php

declare(strict_types=1);

use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonCompositeHash;
use MyInvoice\Service\Anonymization\Streaming\JsonStreamValue;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$rows = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (!is_int($rows) || $rows < 1) {
    throw new InvalidArgumentException('Missing composite row count.');
}

$baseline = memory_get_usage(true);
memory_reset_peak_usage();
$started = hrtime(true);
$result = (new CanonicalJsonCompositeHash())->hash([
    'supplier_id' => 7,
    'source_id' => 300,
    'amount_cents' => '19000',
    'basis' => new JsonStreamValue(static function () use ($rows): iterable {
        yield '{"rows":[';
        for ($row = 1; $row <= $rows; $row++) {
            yield ($row === 1 ? '' : ',') . json_encode([
                'id' => $row,
                'amount_cents' => (string) ($row * 17),
                'evidence' => 'Žluťoučký ' . $row,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        yield '],"summary":{"amount_cents":"19000"}}';
    }),
    'created_by' => 1,
]);

echo json_encode([
    'rows' => $rows,
    'sha256' => $result->sha256,
    'bytes' => $result->bytes,
    'nodes' => $result->nodes,
    'members' => $result->members,
    'peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $baseline),
    'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
