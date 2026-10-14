<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;

/**
 * Zařazení mzdových složek do rozpadu příjmu JMHZ podle hlášení, která PAMICA odeslala.
 *
 * Číselník složek PAMICA zařazení do JMHZ nenese: program ho odvozuje ze skupiny složky
 * až při sestavení hlášení. Vidět je jen ve výsledku - v kolonkách rozpadu (10329 mzda,
 * 10330 pravidelné odměny, 10331 nepravidelné, 10332 až 10336 příplatky, 10338 až 10343
 * náhrady) odeslaného hlášení každého vztahu a měsíce. Z nich se zařazení složky dá
 * zpětně poznat: kolonka, do které PAMICA částku složky započetla, je ta, jejíž hodnotu
 * jde složit z částky složky a dalších položek téže mzdy.
 *
 * Každá mzda, ve které se složka vyskytuje, dá jeden hlas, ale jen když vyjde jediná
 * kolonka; víc kolonek (shodné částky, nejasné součty) se nepočítá. Zařazení platí, když
 * má složka aspoň {@see self::MIN_VOTES} rozhodných hlasů a vítězná kolonka aspoň
 * {@see self::MIN_SHARE} z nich. Jinak zůstane nezařazená a rozhodne účetní - chybné
 * zařazení by prošlo do hlášení tiše.
 *
 * Třída jen čte a počítá, nic nezapisuje.
 */
final class PohodaPayrollJmhzTargets
{
    /** Kolonky rozpadu, do kterých se složka zařazuje; úhrny 10328 a 10337 sem nepatří. */
    private const TARGETS = ['10329', '10330', '10331', '10332', '10333', '10334', '10335', '10336',
        '10338', '10339', '10340', '10341', '10342', '10343'];

    /** Úhrn ostatních příplatků a jeho rozpad: složka v rozpadu je i v úhrnu. */
    private const PREMIUM_TOTAL = '10332';
    private const PREMIUM_DETAILS = ['10333', '10334', '10335', '10336'];

    public const MIN_VOTES = 2;
    public const MIN_SHARE = 0.9;

    /** Víc položek mzdy už podmnožiny neprochází (2^n); taková mzda nehlasuje. */
    private const MAX_ITEMS = 14;

    /**
     * Kolonky rozpadu z odeslaných hlášení: `ID vztahu|Y-m` => atribut => celé koruny.
     *
     * @return array<string,array<string,int>>
     */
    public static function reports(string $file, int $year): array
    {
        $months = [];
        foreach (PohodaXml::scan($file, ['MH']) as $row) {
            $month = (int) PohodaXml::text($row, 'RelMesic');
            if ((int) PohodaXml::text($row, 'Rok') === $year && $month >= 1 && $month <= 12) {
                $months[PohodaXml::text($row, 'ID')] = sprintf('%04d-%02d', $year, $month);
            }
        }
        $targets = array_fill_keys(self::TARGETS, true);
        $out = [];
        foreach (PohodaXml::records($file, 'MHitems') as $item) {
            $period = $months[PohodaXml::text($item, 'RefAg')] ?? null;
            if ($period === null) {
                continue;
            }
            $values = [];
            foreach (PohodaXml::attributes($item, 'Data') as $attribute) {
                // Blok dalšího souběžného vztahu (`order2` > 0) patří jinému vztahu.
                if ($attribute['order2'] !== 0 || !isset($targets[(string) $attribute['id']])
                    || preg_match('/^-?\d+(?:\.\d+)?$/D', $attribute['value']) !== 1) {
                    continue;
                }
                $values[(string) $attribute['id']] = (int) round((float) $attribute['value']);
            }
            if ($values !== []) {
                $out[PohodaXml::text($item, 'RefPomer') . '|' . $period] = $values;
            }
        }

        return $out;
    }

    /**
     * @param array<string,list<array{code:?string,amount:int}>> $items položky mezd ({@see PohodaPayrollConverter::itemAmounts()})
     * @param array<string,array<string,int>> $reports kolonky hlášení ({@see self::reports()})
     * @param list<string> $codes složky, jejichž zařazení se hledá
     * @return array<string,string> kód složky => atribut JMHZ
     */
    public static function infer(array $items, array $reports, array $codes): array
    {
        $wanted = array_fill_keys($codes, true);
        /** @var array<string,array<string,int>> $votes */
        $votes = [];
        foreach ($items as $key => $rows) {
            $report = $reports[$key] ?? null;
            if ($report === null || count($rows) > self::MAX_ITEMS) {
                continue;
            }
            foreach ($rows as $index => $row) {
                if ($row['code'] === null || !isset($wanted[$row['code']]) || $row['amount'] <= 0) {
                    continue;
                }
                $others = array_values(array_map(
                    static fn (array $other): int => $other['amount'],
                    array_filter($rows, static fn (int $i): bool => $i !== $index, ARRAY_FILTER_USE_KEY),
                ));
                $hits = [];
                foreach ($report as $target => $value) {
                    if ($value > 0 && self::composes($value - $row['amount'], $others)) {
                        $hits[] = (string) $target;
                    }
                }
                $hit = self::single($hits);
                if ($hit !== null) {
                    $votes[$row['code']][$hit] = ($votes[$row['code']][$hit] ?? 0) + 1;
                }
            }
        }
        $out = [];
        foreach ($votes as $code => $byTarget) {
            arsort($byTarget);
            $total = array_sum($byTarget);
            $target = (string) array_key_first($byTarget);
            if ($total >= self::MIN_VOTES && $byTarget[$target] / $total >= self::MIN_SHARE) {
                $out[$code] = $target;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Jediná kolonka. Úhrn ostatních příplatků (10332) obsahuje i svůj rozpad, takže
     * zásah do úhrnu i jednoho z detailů je tentýž příplatek zařazený do detailu.
     *
     * @param list<string> $hits
     */
    private static function single(array $hits): ?string
    {
        if (count($hits) === 2 && in_array(self::PREMIUM_TOTAL, $hits, true)) {
            $detail = array_values(array_intersect($hits, self::PREMIUM_DETAILS));
            return $detail[0] ?? null;
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Jde zbytek složit z některých dalších položek mzdy (i z žádné, když je nulový)?
     *
     * @param list<int> $amounts
     */
    private static function composes(int $rest, array $amounts): bool
    {
        if ($rest === 0) {
            return true;
        }
        $sums = [0 => true];
        foreach ($amounts as $amount) {
            foreach (array_keys($sums) as $sum) {
                $sums[$sum + $amount] = true;
            }
        }

        return isset($sums[$rest]);
    }
}
