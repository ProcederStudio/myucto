<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Tax\Return\TaxReturnService;

/**
 * Kontrola převodu proti podáním, která PREMIER v záloze drží: kontrolní hlášení DPH
 * (`D_KHDPH1` hlavičky, `D_KHDPHP1` věty) a přiznání k dani z příjmů právnických osob
 * (`D_PO1`, `D_PO2`).
 *
 * KH se porovnává po měsících a oddílech (základ a daň v základní a snížené sazbě), vždy
 * proti POSLEDNÍMU podání měsíce v PREMIER (následné KH nahrazuje řádné celé). DPPO po
 * řádcích přiznání. Rozdíl je upozornění, ne chyba převodu - ukazuje, kde se výkazy MyÚčta
 * a podání z PREMIER rozcházejí (typicky doklad s odpočtem uplatněným v jiném období).
 *
 * Přiznání k DPH PREMIER v záloze neukládá (podává se přes EPO), proto se tu nekontroluje.
 */
final class PremierVerifier
{
    public const STEP = 'verification';

    private const KH_SECTIONS = ['A1' => 'A.1', 'A2' => 'A.2', 'A4' => 'A.4', 'A5' => 'A.5', 'B1' => 'B.1', 'B2' => 'B.2', 'B3' => 'B.3'];

    /** Řádky DPPO: sloupec `D_PO2` PREMIER → řádek přiznání a atribut XML MyÚčta (`VetaD`). */
    private const DPPO_LINES = [
        ['line' => '10', 'premier' => 'II_10_HOS2', 'xml' => 'kc_ii10_10'],
        ['line' => '40', 'premier' => 'II_40_VYDA', 'xml' => 'kc_ii50_40'],
        ['line' => '200', 'premier' => 'II_200_ZAK', 'xml' => 'kc_ii200_200'],
        ['line' => '220', 'premier' => 'II_220_ZAK', 'xml' => 'kc_ii_220'],
        ['line' => '250', 'premier' => 'II_250_ZAK', 'xml' => 'kc_ii230_250'],
        ['line' => '270', 'premier' => 'II_270_SNI', 'xml' => 'kc_ii260_270'],
        ['line' => '290', 'premier' => 'II_290_DAN', 'xml' => 'kc_ii280_290'],
        ['line' => '310', 'premier' => 'II_310_DAN', 'xml' => 'kc_ii300_310'],
        ['line' => '330', 'premier' => 'II_330_DAN', 'xml' => 'kc_ii320_330'],
        ['line' => '340', 'premier' => 'II_340_CEL', 'xml' => 'kc_ii_340'],
    ];

    public function __construct(
        private readonly KontrolniHlaseniBuilder $kh,
        private readonly TaxReturnService $taxReturns,
    ) {}

    public function run(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $result = ['year' => $ctx->year, 'kh' => $this->verifyKh($ctx), 'dppo' => $this->verifyDppo($ctx)];
        $p->set('verification', [$result]);
        $khDiffs = array_values(array_filter($result['kh'], static fn (array $m): bool => !$m['ok']));
        foreach ($khDiffs as $m) {
            $p->warn(self::STEP, 'kh_mismatch', sprintf('KH %s: MyÚčto se liší od podání v PREMIER (%s).', $m['period'], implode('; ', array_map(
                static fn (array $d): string => sprintf('%s %s: %s × %s', $d['section'], $d['field'], self::money($d['myucto']), self::money($d['premier'])),
                array_slice($m['diffs'], 0, 6)
            ))), ['period' => $m['period']]);
        }
        $p->setCount(self::STEP, 'kh_months', count($result['kh']));
        $p->setCount(self::STEP, 'kh_months_ok', count($result['kh']) - count($khDiffs));
        if ($result['dppo'] !== null) {
            if (isset($result['dppo']['error'])) {
                $p->info(self::STEP, 'dppo_unavailable', 'DPPO za rok ' . $ctx->year . ' nejde v MyÚčtu sestavit: ' . $result['dppo']['error']);
            } elseif (!$result['dppo']['ok']) {
                $p->warn(self::STEP, 'dppo_mismatch', sprintf('DPPO %d: MyÚčto se liší od přiznání v PREMIER (%s).', $ctx->year, implode('; ', array_map(
                    static fn (array $d): string => sprintf('ř. %s: %s × %s', $d['line'], self::money($d['myucto']), self::money($d['premier'])),
                    $result['dppo']['diffs']
                ))));
            } else {
                $p->info(self::STEP, 'dppo_ok', 'DPPO ' . $ctx->year . ' sedí s přiznáním v PREMIER'
                    . ($result['dppo']['rounding'] ? ' (některé řádky se liší o haléřové zaokrouhlení do 1 Kč, daň je shodná).' : '.'));
            }
        }
        $p->finish(self::STEP);
    }

    /**
     * @return list<array{period:string,variant:string,ok:bool,diffs:list<array<string,mixed>>}>
     */
    private function verifyKh(PremierContext $ctx): array
    {
        // KH je kalendářní (měsíc ROK/MESIC); kontrolují se měsíce účetního období převodu.
        $from = substr($ctx->calendar->periodStart($ctx->year), 0, 7);
        $to = substr($ctx->calendar->periodEnd($ctx->year), 0, 7);
        $latest = [];
        foreach ($ctx->backup->rows('D_KHDPH1') as $h) {
            $month = (int) ($h['MESIC'] ?? 0);
            $period = sprintf('%04d-%02d', (int) ($h['ROK'] ?? 0), $month);
            if ($month < 1 || $month > 12 || $period < $from || $period > $to) {
                continue;
            }
            $filed = self::czDate((string) ($h['DNE'] ?? ''));
            if (!isset($latest[$period]) || $filed >= $latest[$period]['filed']) {
                $latest[$period] = ['id' => trim((string) $h['ID_CISLO']), 'filed' => $filed, 'form' => trim((string) ($h['FORMA'] ?? ''))];
            }
        }
        if ($latest === []) {
            return [];
        }
        $premier = [];
        $wanted = array_flip(array_column($latest, 'id'));
        foreach ($ctx->backup->rows('D_KHDPHP1') as $r) {
            $id = trim((string) ($r['ID_CISLO'] ?? ''));
            $section = self::KH_SECTIONS[strtoupper(trim((string) ($r['ODDIL'] ?? '')))] ?? null;
            if (!isset($wanted[$id]) || $section === null) {
                continue;
            }
            foreach (self::khFields($r['ZAKL_DANE1'] ?? 0, $r['DAN1'] ?? 0, (float) ($r['ZAKL_DANE2'] ?? 0) + (float) ($r['ZAKL_DANE3'] ?? 0), (float) ($r['DAN2'] ?? 0) + (float) ($r['DAN3'] ?? 0)) as $field => $value) {
                $premier[$id][$section][$field] = ($premier[$id][$section][$field] ?? 0.0) + $value;
            }
        }
        ksort($latest);
        $out = [];
        foreach ($latest as $period => $head) {
            try {
                $built = $this->kh->build($ctx->supplierId, (int) substr($period, 0, 4), (int) substr($period, 5, 2), 'monthly', 'radne');
                $mine = self::parseKh((string) $built['xml']);
            } catch (\Throwable $e) {
                $out[] = ['period' => $period, 'variant' => $head['form'], 'ok' => false, 'diffs' => [['section' => '-', 'field' => $e->getMessage(), 'myucto' => 0.0, 'premier' => 0.0]]];
                continue;
            }
            $theirs = $premier[$head['id']] ?? [];
            $diffs = [];
            foreach (array_unique(array_merge(array_keys($mine), array_keys($theirs))) as $section) {
                foreach (['zakl_dane1', 'dan1', 'zakl_dane2', 'dan2'] as $field) {
                    $a = round($mine[$section][$field] ?? 0.0, 2);
                    $b = round($theirs[$section][$field] ?? 0.0, 2);
                    if (abs($a - $b) >= ReconciliationTolerance::FILING_ROUNDING) {
                        $diffs[] = ['section' => $section, 'field' => $field, 'myucto' => $a, 'premier' => $b];
                    }
                }
            }
            $out[] = ['period' => $period, 'variant' => $head['form'], 'ok' => $diffs === [], 'diffs' => $diffs];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function verifyDppo(PremierContext $ctx): ?array
    {
        $head = PremierSourceYears::taxReturnHead($ctx->backup, $ctx->year, $ctx->calendar);
        if ($head === null) {
            return null;
        }
        $values = null;
        foreach ($ctx->backup->rows('D_PO2') as $r) {
            if (trim((string) ($r['ID_CISLO'] ?? '')) === trim((string) $head['ID_CISLO'])) {
                $values = $r + $head;
            }
        }
        if ($values === null) {
            return null;
        }
        try {
            $built = $this->taxReturns->buildXml($ctx->supplierId, $ctx->year, 'po', 'radne', 1);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        $mine = self::xmlAttributes((string) $built['xml']);
        $diffs = [];
        $lines = [];
        $rounding = false;
        foreach (self::DPPO_LINES as $l) {
            $premier = round((float) ($values[$l['premier']] ?? 0), 2);
            $myucto = round((float) ($mine[$l['xml']] ?? 0), 2);
            $lines[] = ['line' => $l['line'], 'myucto' => $myucto, 'premier' => $premier];
            // PREMIER zaokrouhluje částky přiznání na koruny nahoru, MyÚčto matematicky -
            // rozdíl do 1 Kč je zaokrouhlení, ne neshoda (daň se zaokrouhluje ze základu
            // na tisíce a vychází stejně).
            // Pozor: DPPO bere rozdíl právě 1 Kč ještě jako zaokrouhlení (>), KH už jako
            // neshodu (>=). Rozdíl je převzatý beze změny.
            if (abs($premier - $myucto) > ReconciliationTolerance::FILING_ROUNDING) {
                $diffs[] = ['line' => $l['line'], 'myucto' => $myucto, 'premier' => $premier];
            } elseif (!ReconciliationTolerance::sameCent($premier, $myucto)) {
                $rounding = true;
            }
        }
        return ['ok' => $diffs === [], 'lines' => $lines, 'diffs' => $diffs, 'rounding' => $rounding];
    }

    /** @return array<string,array<string,float>> oddíl → pole → součet */
    private static function parseKh(string $xml): array
    {
        $out = [];
        if ($xml === '') {
            return $out;
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return $out;
        }
        foreach ($doc->xpath('//*[starts-with(local-name(), "Veta")]') ?: [] as $veta) {
            $name = $veta->getName();
            if (preg_match('/^Veta([AB]\d)$/', $name, $m) !== 1) {
                continue;
            }
            $section = self::KH_SECTIONS[$m[1]] ?? null;
            if ($section === null) {
                continue;
            }
            $a = $veta->attributes();
            foreach (self::khFields($a['zakl_dane1'] ?? 0, $a['dan1'] ?? 0, $a['zakl_dane2'] ?? 0, $a['dan2'] ?? 0) as $field => $value) {
                $out[$section][$field] = ($out[$section][$field] ?? 0.0) + $value;
            }
        }
        return $out;
    }

    /** @return array{zakl_dane1:float,dan1:float,zakl_dane2:float,dan2:float} */
    private static function khFields(mixed $base1, mixed $vat1, mixed $base2, mixed $vat2): array
    {
        return ['zakl_dane1' => (float) (string) $base1, 'dan1' => (float) (string) $vat1, 'zakl_dane2' => (float) (string) $base2, 'dan2' => (float) (string) $vat2];
    }

    /**
     * Atributy vět přiznání (`VetaD`, `VetaO`…) - řádky oddílu II jsou rozdělené do více vět.
     *
     * @return array<string,string>
     */
    private static function xmlAttributes(string $xml): array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }
        $out = [];
        foreach ($doc->xpath('//*[starts-with(local-name(), "Veta")]') ?: [] as $node) {
            foreach ($node->attributes() as $k => $v) {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }

    private static function czDate(string $value): string
    {
        return preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($value), $m) === 1 ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
