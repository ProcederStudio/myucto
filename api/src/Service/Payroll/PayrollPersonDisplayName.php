<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Jméno zaměstnance v tiskových sestavách mezd tak, jak ho vedou mzdové programy
 * (přehled mezd PAMICA): „Příjmení Jméno Titul“, řazené podle české abecedy
 * (Č za C, Ch za H, Ř za R, Š za S).
 */
final class PayrollPersonDisplayName
{
    /**
     * „Ing. Adéla Králová“ => „Králová Adéla Ing.“: příjmení je poslední slovo, které
     * není titulem; tituly (slova s tečkou, „DiS.“, „Ph.D.“) jdou na konec.
     */
    public static function surnameFirst(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $titles = array_values(array_filter($parts, static fn (string $part): bool => str_contains($part, '.')));
        $words = array_values(array_filter($parts, static fn (string $part): bool => $part !== '' && !str_contains($part, '.')));
        if ($words === []) {
            return trim($name);
        }
        $surname = (string) array_pop($words);

        return trim(implode(' ', [$surname, ...$words, ...$titles]));
    }

    /** Porovnání dvou jmen podle české abecedy. */
    public static function compare(string $left, string $right): int
    {
        static $collator = null;
        $collator ??= new \Collator('cs_CZ');

        return (int) $collator->compare($left, $right);
    }
}
