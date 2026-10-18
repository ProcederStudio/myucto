<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class FxCaseTuple
{
    public static function fromRow(array $row, array $dimensions = [], array $splits = []): array
    {
        ksort($dimensions, SORT_NUMERIC);
        ksort($splits, SORT_NUMERIC);
        foreach ($splits as &$values) ksort($values, SORT_NUMERIC);
        unset($values);
        return ['cost_center' => $row['cost_center'] ?? null, 'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null, 'dimensions' => $dimensions, 'dimension_splits' => $splits];
    }

    public static function hash(array $tuple): string
    {
        return hash('sha256', json_encode(self::fromRow($tuple, $tuple['dimensions'] ?? [], $tuple['dimension_splits'] ?? []), JSON_THROW_ON_ERROR));
    }

    public static function sourceHash(array $item): string
    {
        return hash('sha256', json_encode([
            (int) $item['source_entry_id'], (int) $item['source_line_id'], $item['doc_type'], (int) $item['doc_id'],
            (int) $item['case_document_id'], (int) $item['account_id'], $item['currency_code'],
            $item['source_side'], (bool) $item['source_is_red_storno'], JournalLineAmount::decimal($item['source_amount']),
            JournalLineAmount::decimal($item['source_foreign']), bcadd((string) $item['fx_rate'], '0', 6),
            $item['source_entry_date'], self::hash($item['source_tuple']),
        ], JSON_THROW_ON_ERROR));
    }
}
