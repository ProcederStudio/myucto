<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Na které účty smí cizoměnové bankovní pravidlo účtovat protistranu. Jediné místo
 * pravidla pro validaci pravidla (REST, import profilu firmy) i pro engine účtování
 * a závěrkové přecenění — dřív šlo o dvě zrcadlové kontroly, které se musely hlídat
 * ručně.
 *
 * Smysl je „nenech cizoměnovou pozici na účtu, který k rozvahovému dni nikdo
 * nepřecení" (§ 24 odst. 2 písm. b) a odst. 6 ZoÚ, § 60 vyhl. 500/2002). Povolené jsou:
 *   a) výsledkové účty 5xx/6xx — náklad/výnos se spotřebuje kurzem dne a nepřeceňuje se;
 *   b) 221* — vlastní bankovní účet/analytika; přeceňuje se z bankových zůstatků;
 *   c) peněžní rozvahové účty z {@see self::LEDGER_REVALUED_PREFIXES} — jejich
 *      cizoměnový zůstatek se k rozvahovému dni přecení přímo z deníku
 *      ({@see \MyInvoice\Service\Accounting\Closing\FxRevaluationService}).
 *
 * Seznam v c) je úzký schválně: 378 jiné pohledávky a 379 jiné závazky jsou peněžní
 * položky bez saldokonta (typicky faktoring — záloha od faktora v EUR). Saldokonto
 * 311/321/314/324/325 se páruje s doklady a přeceňuje open-item metodou, nepeněžní
 * zálohy 314/324 se nepřeceňují vůbec, úvěry a společníci mají vlastní evidenci. Další
 * účet sem patří jen tehdy, když je peněžní a jeho cizoměnové řádky deníku nesou
 * úplnou FX stopu (měna, kurz, cizí částka) — jinak by přecenění počítalo z neúplného
 * zůstatku.
 */
final class ForeignCurrencyAccountPolicy
{
    /** Peněžní rozvahové účty přeceňované k rozvahovému dni přímo z deníku. */
    public const LEDGER_REVALUED_PREFIXES = ['378', '379'];

    public static function allowsRuleCounterAccount(string $accountCode): bool
    {
        $code = trim($accountCode);
        return str_starts_with($code, '5')
            || str_starts_with($code, '6')
            || str_starts_with($code, '221')
            || self::isLedgerRevalued($code);
    }

    public static function isLedgerRevalued(string $accountCode): bool
    {
        $code = trim($accountCode);
        foreach (self::LEDGER_REVALUED_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
