<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

final class NetTurnoverPolicy
{
    public const EXTRA_ROWS = [
        'income_statement' => [
            'III.', 'III.1.', 'III.2.', 'III.3.',
            'IV.', 'IV.1.', 'IV.2.', 'V.', 'V.1.', 'V.2.',
            'VI.', 'VI.1.', 'VI.2.', 'VII.',
        ],
        'income_statement_purpose' => ['II.', 'III.', 'IV.', 'V.', 'VI.'],
    ];

    public static function isBusinessModel(string $startsOn): bool
    {
        return $startsOn >= '2024-01-01';
    }

    public static function extraRows(string $type, array $selected): array
    {
        return array_values(array_unique(array_intersect($selected, self::EXTRA_ROWS[$type] ?? [])));
    }

    public static function directRows(array $rows, string $type, string $startsOn, array $selected): array
    {
        if (!isset(self::EXTRA_ROWS[$type])) {
            return [];
        }
        $roots = $type === 'income_statement' ? ['I.', 'II.'] : ['I.'];
        $roots = array_merge($roots, self::isBusinessModel($startsOn)
            ? self::extraRows($type, $selected)
            : self::EXTRA_ROWS[$type]);
        $byCode = [];
        $children = [];
        foreach ($rows as $row) {
            $code = (string) $row['row_code'];
            $byCode[$code] = $row;
            $children[(string) ($row['parent_row_code'] ?? '')][] = $code;
        }
        $included = [];
        $visit = static function (string $code) use (&$visit, &$included, $byCode, $children): void {
            if (isset($included[$code]) || !isset($byCode[$code])) {
                return;
            }
            $included[$code] = true;
            if ($byCode[$code]['row_type'] === 'subtotal') {
                foreach ($children[$code] ?? [] as $child) {
                    $visit($child);
                }
            }
        };
        foreach ($roots as $root) {
            $visit($root);
        }
        return $included;
    }
}
