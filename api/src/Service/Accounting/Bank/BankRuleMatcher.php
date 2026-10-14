<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Pure match logika pravidel účtování (§3.5, §4.1). Bez DB — jednotkově
 * testovatelné. Kritéria = AND přes VYPLNĚNÁ pole pravidla:
 *   - counterparty_account (+ counterparty_bank, je-li) — normalizované číslo účtu,
 *   - variable_symbol — číslice (VariableSymbolNormalizer::digits),
 *   - message_contains — substring v normalizované zprávě nebo jméně protistrany (BankMessageNormalizer),
 *   - amount_min/max — ABS(amount) v uzavřeném intervalu (porovnání v haléřích).
 *
 * Prázdná pole se ignorují; pravidlo bez jediného kritéria vynucuje CHECK/DB
 * (chk_bpr_criteria), sem se takové nedostane.
 */
final class BankRuleMatcher
{
    /** Pravidlo s prioritou pod touto hranicí má přednost před detektory (vlastní převod, odvody, karta). */
    public const DETECTOR_OVERRIDE_PRIORITY = 50;

    /** @param array<string,mixed> $rule řádek bank_posting_rules */
    public static function overridesDetectors(array $rule): bool
    {
        return (int) ($rule['priority'] ?? 100) < self::DETECTOR_OVERRIDE_PRIORITY;
    }

    /**
     * @param array<string,mixed> $rule řádek bank_posting_rules
     * @param array{amount:float|int|string, variable_symbol?:?string,
     *              counterparty_account?:?string, counterparty_bank?:?string,
     *              description?:?string, counterparty_name?:?string} $tx
     */
    public function matching(array $rule, array $tx): bool
    {
        // counterparty_account
        $ruleAccount = self::str($rule['counterparty_account'] ?? null);
        if ($ruleAccount !== null) {
            $txAccount = self::str($tx['counterparty_account'] ?? null);
            if ($txAccount === null || !AccountNumberNormalizer::equals($ruleAccount, $txAccount)) {
                return false;
            }
        }

        // Banka je samostatné kritérium i bez konkrétního čísla účtu.
        $ruleBank = AccountNumberNormalizer::canonicalBankCode(self::str($rule['counterparty_bank'] ?? null));
        if ($ruleBank !== null) {
            $txBank = AccountNumberNormalizer::canonicalBankCode(
                self::str($tx['counterparty_bank'] ?? null),
                self::str($tx['counterparty_account'] ?? null),
            );
            if ($txBank !== $ruleBank) {
                return false;
            }
        }

        $rulePrefix = self::str($rule['counterparty_prefix'] ?? null);
        if ($rulePrefix !== null
            && AccountNumberNormalizer::czechAccountPrefix((string) ($tx['counterparty_account'] ?? '')) !== ltrim($rulePrefix, '0')) {
            return false;
        }

        // variable_symbol (číslice)
        $ruleVs = self::str($rule['variable_symbol'] ?? null);
        if ($ruleVs !== null) {
            $txVs = VariableSymbolNormalizer::digits(self::str($tx['variable_symbol'] ?? null) ?? '');
            if ($txVs === '' || VariableSymbolNormalizer::digits($ruleVs) !== $txVs) {
                return false;
            }
        }

        // message_contains (substring v normalizované zprávě NEBO ve jméně protistrany —
        // platby některých poskytovatelů nesou název jen v counterparty_name, vratky nájmu chodí pod jménem
        // pronajímatelky, ne v popisu pohybu).
        $fragment = self::str($rule['message_contains'] ?? null);
        if ($fragment !== null) {
            if ($fragment === '') {
                return false;
            }
            $haystack = BankMessageNormalizer::normalize(self::str($tx['description'] ?? null) ?? '');
            $nameHaystack = BankMessageNormalizer::normalize(self::str($tx['counterparty_name'] ?? null) ?? '');
            if (!str_contains($haystack, $fragment) && !str_contains($nameHaystack, $fragment)) {
                return false;
            }
        }

        // amount_min/max (ABS, haléře)
        $absCents = (int) round(abs((float) $tx['amount']) * 100.0);
        if ($rule['amount_min'] !== null && $absCents < (int) round((float) $rule['amount_min'] * 100.0)) {
            return false;
        }
        if ($rule['amount_max'] !== null && $absCents > (int) round((float) $rule['amount_max'] * 100.0)) {
            return false;
        }

        return true;
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
