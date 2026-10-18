<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/** Protiúčet v jednom poli: číslo bez omezení banky, číslo/banka nebo hvězdička/banka. */
final class CounterpartyAccountCriterion
{
    public static function isValid(string $pattern): bool
    {
        $compact = self::compact($pattern);
        if (str_contains($compact, '*') || str_contains($compact, '/')) {
            return preg_match('~^(?:\*|(?:\d{1,6}-)?\d{1,16})/\d{4}$~D', $compact) === 1
                && (str_starts_with($compact, '*/') || AccountNumberNormalizer::czechNational($compact) !== null);
        }
        return $compact !== '';
    }

    public static function matches(string $pattern, ?string $account, ?string $bank): bool
    {
        if (!self::isValid($pattern)) return false;
        $pattern = self::compact($pattern);
        $account = self::compact($account ?? '');
        $suffix = [];
        preg_match('~/([0-9]{4})$~D', $account, $suffix);
        $actualBank = AccountNumberNormalizer::canonicalBankCode($bank ?: ($suffix[1] ?? null), $account);
        $parts = explode('/', $pattern, 2);
        $expectedBank = $parts[1] ?? AccountNumberNormalizer::czechIbanBankCode($pattern);
        if ($expectedBank !== null && $actualBank !== $expectedBank) return false;
        if ($parts[0] === '*') return true;

        // Podvojné účetnictví zadává banku samostatně; toto kritérium DE nese obě části v jednom poli.
        // Kanonizace zůstává společná s importem a párováním bankovních účtů.
        $want = AccountNumberNormalizer::czechNational(
            AccountNumberNormalizer::czechSlovakIbanAccountPart($parts[0]) ?? $parts[0],
        );
        if ($want !== null) {
            $have = AccountNumberNormalizer::czechNational(
                AccountNumberNormalizer::czechSlovakIbanAccountPart($account) ?? $account,
            );
            return $want === $have;
        }
        return $pattern === $account;
    }

    private static function compact(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }
}
