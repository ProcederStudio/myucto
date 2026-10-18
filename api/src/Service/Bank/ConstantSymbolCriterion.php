<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * KS pro pravidla musí být 1–4 číslice; při porovnání se ignorují vodicí nuly.
 * Na rozdíl od VS (který může být odvozen z čísla dokladu) neodstraňujeme nečíselné
 * znaky. Chybějící nebo poškozený symbol nesmí splnit podmínku, ani pro KS „0“.
 */
final class ConstantSymbolCriterion
{
    public static function key(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $symbol = trim((string) $value);
        if (preg_match('/\A[0-9]{1,4}\z/', $symbol) !== 1) {
            return null;
        }
        return ltrim($symbol, '0') ?: '0';
    }

    public static function matches(mixed $criterion, mixed $symbol): bool
    {
        $want = self::key($criterion);
        return $want !== null && $want === self::key($symbol);
    }
}
