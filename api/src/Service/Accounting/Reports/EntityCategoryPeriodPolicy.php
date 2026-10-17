<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class EntityCategoryPeriodPolicy
{
    public static function commencedMonths(string $startsOn, string $endsOn): int
    {
        $start = self::date($startsOn);
        $end = self::date($endsOn);
        if ($start > $end) {
            throw new \InvalidArgumentException('Začátek účetního období nesmí být po jeho konci.');
        }
        return ((int) $end->format('Y') - (int) $start->format('Y')) * 12
            + (int) $end->format('n') - (int) $start->format('n') + 1;
    }

    public static function thresholdYear(string $startsOn, string $endsOn): int
    {
        self::commencedMonths($startsOn, $endsOn);
        // Čl. II bod 2 zákona 316/2025: nové limity vyžadují začátek od 1. 1. 2024.
        // V dobrovolném okně kratšího roku 2024 zůstává dosavadní volba nových limitů.
        return $startsOn < '2024-01-01' && $endsOn >= '2024-01-01'
            ? 2023
            : (int) substr($endsOn, 0, 4);
    }

    public static function turnoverExceeds(float|int|string $turnover, float|int|string $limit, int $months): bool
    {
        if ($months < 1) {
            throw new \InvalidArgumentException('Počet započatých měsíců musí být kladný.');
        }
        return bccomp(
            bcmul(JournalLineAmount::decimal($turnover), '12', 2),
            bcmul(JournalLineAmount::decimal($limit), (string) $months, 2),
            2,
        ) > 0;
    }

    public static function annualTurnoverWhole(float|int|string $turnover, string $startsOn, string $endsOn): string
    {
        $months = self::commencedMonths($startsOn, $endsOn);
        // Pro jediné zaokrouhlení na celé jednotky stačí první desetinná číslice podílu.
        return bcround(bcdiv(bcmul(JournalLineAmount::decimal($turnover), '12', 2), (string) $months, 1), 0);
    }

    private static function date(string $date): \DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new \InvalidArgumentException('Neplatné datum účetního období.');
        }
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($value === false || $value->format('Y-m-d') !== $date || (int) $value->format('Y') < 1) {
            throw new \InvalidArgumentException('Neplatné datum účetního období.');
        }
        return $value;
    }
}
