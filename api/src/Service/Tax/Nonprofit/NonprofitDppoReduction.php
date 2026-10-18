<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoReduction
{
    public static function limit(int $baseKc, string $band): int
    {
        self::amount($baseKc);
        [$minimum,$maximum] = match ($band) {
            'standard' => [300000,1000000],
            'university_research' => [1000000,3000000],
            'ineligible' => [0,0],
            default => throw new TaxReturnException('nonprofit_dppo_reduction_invalid', 'Snížení vyžaduje doloženou kategorii podle § 20 odst. 7.', 422),
        };
        return min($baseKc, $maximum, max($minimum, intdiv($baseKc * 3, 10)));
    }

    public static function unusedAddback(int $reductionKc, int $savingKc, int $qualifiedUseKc): int
    {
        foreach ([$reductionKc,$savingKc,$qualifiedUseKc] as $amount) self::amount($amount);
        if ($qualifiedUseKc > $savingKc || ($reductionKc === 0 && $savingKc !== 0)) {
            throw new TaxReturnException('nonprofit_dppo_reduction_invalid', 'Použití úspory musí odpovídat doložené skutečné daňové úspoře.', 422);
        }
        if ($savingKc === 0) return 0;
        $numerator = bcmul((string) $reductionKc, (string) ($savingKc - $qualifiedUseKc), 0);
        $denominator = (string) $savingKc;
        $whole = bcdiv($numerator, $denominator, 0);
        $roundUp = bccomp(bcmul(bcmod($numerator, $denominator, 0), '2', 0), $denominator, 0) >= 0;
        return (int) $whole + (int) $roundUp;
    }

    private static function amount(int $amount): void
    {
        if ($amount < 0 || $amount > 99999999999999) {
            throw new TaxReturnException('nonprofit_dppo_reduction_invalid', 'Částka musí být nezáporná a odpovídat rozsahu daňového formuláře.', 422);
        }
    }
}
