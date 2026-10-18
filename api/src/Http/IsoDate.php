<?php

declare(strict_types=1);

namespace MyInvoice\Http;

/**
 * Kalendářní datum z requestu ve tvaru YYYY-MM-DD. Samotný regex propustí i 2026-02-31,
 * které striktní sql_mode odmítne až při zápisu (500 místo 400).
 */
final class IsoDate
{
    public static function isValid(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
