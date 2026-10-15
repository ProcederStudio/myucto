<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

/**
 * Zaokrouhlení krácené měsíční mzdy a náhrad mzdy za dovolenou a překážky na celé koruny.
 *
 * Výchozí je nahoru podle § 142 odst. 2 ZP (přes § 144 i pro náhradu mzdy).
 * Matematické zaokrouhlení je volba firmy, která přešla z programu, jenž
 * zaokrouhloval matematicky: navazující měsíce pak sedí s převzatou historií.
 * Pojistné ani daň se touto volbou neřídí, ty zaokrouhluje zákon sám. Náhrada mzdy při
 * nemoci zůstává nahoru: tak ji zaokrouhluje i PAMICA, ze které volba vzešla.
 */
enum PayrollWageRounding: string
{
    case Ceil = 'ceil';
    case HalfUp = 'half_up';

    public function mode(): RoundingMode
    {
        return match ($this) {
            self::Ceil => RoundingMode::Ceil,
            self::HalfUp => RoundingMode::HalfUp,
        };
    }

    public function traceLabel(): string
    {
        return match ($this) {
            self::Ceil => 'ceil-to-czk-on-period-total',
            self::HalfUp => 'half-up-to-czk-on-period-total',
        };
    }

    public function basisLabel(): string
    {
        return match ($this) {
            self::Ceil => 'zp-142-2-via-144',
            self::HalfUp => 'employer-choice-previous-program',
        };
    }
}
