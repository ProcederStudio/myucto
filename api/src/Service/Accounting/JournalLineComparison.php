<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Repository\DimensionAssignmentRepository;

final class JournalLineComparison
{
    public static function sameLedgerLine(array $a, array $b): bool
    {
        $normalize = static function (array $line): array {
            $out = ['account_id' => (int) ($line['account_id'] ?? 0), 'side' => (string) ($line['side'] ?? ''),
                'amount' => round((float) ($line['amount'] ?? 0), 2), 'is_red_storno' => (bool) ($line['is_red_storno'] ?? false)];
            foreach (['currency_code', 'cost_center'] as $field) {
                $out[$field] = $line[$field] ?? null;
            }
            foreach (['fx_rate', 'amount_foreign', 'project_id'] as $field) {
                $out[$field] = isset($line[$field]) ? (float) $line[$field] : null;
            }
            return $out;
        };
        return $normalize($a) === $normalize($b);
    }

    public static function sameAssignments(array $a, array $b): bool
    {
        $dimensions = static function (array $line): array {
            $out = [];
            foreach ($line['dimensions'] ?? [] as $type => $value) $out[(int) $type] = (int) $value;
            ksort($out);
            return $out;
        };
        return $dimensions($a) === $dimensions($b)
            && DimensionAssignmentRepository::sameSplits($a['dimension_splits'] ?? [], $b['dimension_splits'] ?? []);
    }

    public static function sameLines(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        foreach ($a as $line) {
            foreach ($b as $index => $candidate) {
                if (self::sameLedgerLine($line, $candidate) && self::sameAssignments($line, $candidate)) {
                    unset($b[$index]);
                    continue 2;
                }
            }
            return false;
        }
        return true;
    }
}
