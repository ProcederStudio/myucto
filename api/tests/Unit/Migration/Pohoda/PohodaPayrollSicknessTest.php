<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollSickness;
use PHPUnit\Framework\TestCase;

/**
 * Případy nemocenské z `91_mzdy.xml`: návaznost rozpracované neschopnosti přes první
 * měsíc vedení mezd, zdroj náhrady mzdy a odmítnutí případu, který skončil dřív.
 *
 * Data jsou syntetická, sestavená v testu.
 */
final class PohodaPayrollSicknessTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_sickness_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    /**
     * Nemoc přes přelom: začala 20. 7. u PAMICA, pokračuje do 5. 8. u nás. Do prvního
     * měsíce vedení mezd (srpen) padlo 12 kalendářních dnů okna § 192 ZP, takže MyÚčto
     * má dát náhradu jen za zbytek okna, ne za dalších čtrnáct dnů.
     */
    public function testCarriesUsedWindowDaysOfSicknessAcrossStartPeriod(): void
    {
        $result = PohodaPayrollSickness::read($this->write(), 2026, '2026-08');
        $case = self::single($result['cases'], '1001');

        self::assertSame('dpn', $case['type']);
        self::assertSame('2026-07-20', $case['date_from'], 'Bere se skutečný počátek případu, ne první den měsíce.');
        self::assertSame('2026-08-05', $case['date_to']);
        self::assertTrue($case['in_progress']);
        self::assertTrue($case['crosses_start_period']);
        // 20. 7. až 31. 7. = 12 kalendářních dnů před 1. 8.
        self::assertSame(12, $case['window_used_calendar_days']);
        // Dny z exportu, ne z hodin: 12 + 5 kalendářních, 9 + 3 pracovních.
        self::assertSame(17, $case['calendar_days']);
        self::assertSame(12.0, $case['work_days']);
        self::assertSame(['MZneprit:1', 'MZneprit:2'], $case['evidence']);
        self::assertSame([
            'header' => '1',
            'period' => '2026-08',
            'decision_number' => '900000000000000003',
            'decisive_months' => [
                '2025-11' => ['income_minor' => 4_200_050, 'excluded_days' => 0],
                '2025-12' => ['income_minor' => 3_000_000, 'excluded_days' => 4],
            ],
            'fields' => [],
            'sent' => true,
            'accepted_on' => '2026-08-12',
        ], $case['benefit_claim']);
        self::assertSame([
            'header' => '4',
            'period' => '2026-08',
            'decision_number' => '900000000000000003',
            'fields' => [
                'issued_on' => '2026-08-06',
                'returned_to_work' => true,
                'returned_on' => '2026-08-06',
                'hours_worked_last_day' => 0.0,
                'shift_hours_last_day' => 8.0,
            ],
            'sent' => true,
            'accepted_on' => '2026-08-06',
        ], $case['end_report']);
    }

    /**
     * Zastropování délkou okna z rulesetu dělá až zápis; čtení vrací uplynulé dny tak,
     * jak je má kalendář, protože zákonné číslo nezná.
     */
    public function testWindowUsedDaysAreCappedOnlyWithKnownWindowLength(): void
    {
        self::assertSame(12, PohodaPayrollSickness::windowUsedCalendarDays('2026-07-20', '2026-08-01'));
        self::assertSame(12, PohodaPayrollSickness::windowUsedCalendarDays('2026-07-20', '2026-08-01', 14));
        self::assertSame(14, PohodaPayrollSickness::windowUsedCalendarDays('2026-05-01', '2026-08-01', 14),
            'Dávno vyčerpané okno se zastropuje jeho délkou.');
        self::assertSame(0, PohodaPayrollSickness::windowUsedCalendarDays('2026-08-10', '2026-08-01', 14),
            'Případ, který začal až u nás, nemá co přenášet.');
    }

    /**
     * Chybějící `MZnahr` krok nepoloží: náhrada se vezme z `MZneprit.KcNahr` a protokol
     * má z čeho říct, odkud je.
     */
    public function testMissingWageCompensationTableFallsBackToAbsenceAmount(): void
    {
        $result = PohodaPayrollSickness::read($this->write(), 2026, '2026-08');

        self::assertSame(0, $result['wage_compensation_rows'], 'Tabulka MZnahr v exportu není.');
        $case = self::single($result['cases'], '1001');
        // 8 400,50 v červenci + 2 100 v srpnu.
        self::assertSame(1_050_050, $case['compensation_minor']);
        self::assertSame('mzneprit_kcnahr', $case['compensation_source']);
    }

    /** Nemoc, která skončila před prvním měsícem vedení mezd, se do rozpracovaných nepočítá. */
    public function testSicknessClosedBeforeStartPeriodIsNotInProgress(): void
    {
        $result = PohodaPayrollSickness::read($this->write(), 2026, '2026-08');
        $case = self::single($result['cases'], '1002');

        self::assertSame('2026-03-02', $case['date_from']);
        self::assertSame('2026-03-11', $case['date_to']);
        self::assertFalse($case['in_progress'], 'Skončený případ MyÚčto nepřebírá, nese ho převedená mzda.');
        self::assertFalse($case['crosses_start_period']);
        self::assertSame(
            [],
            array_values(array_filter(
                $result['cases'],
                static fn (array $row): bool => $row['personal_number'] === '1002' && $row['in_progress'],
            )),
        );
    }

    /**
     * Prázdné `MZdavky` a nemocenská složka, kterou evidence nezná, se nepřevádějí tiše —
     * obojí má svůj počet pro protokol.
     */
    public function testSkippedBenefitsAndUnknownKindsAreCounted(): void
    {
        $result = PohodaPayrollSickness::read($this->write(), 2026, '2026-08');

        self::assertSame(0, $result['benefit_rows'], 'MZdavky je v testovaném exportu prázdná.');
        self::assertSame(1, $result['benefit_claims']);
        self::assertSame(['H99 Vyrovnávací příspěvek v těhotenství a mateřství' => 1], $result['unclassified']);
    }

    /** Bez prvního měsíce vedení mezd se rozpracovaný případ nedá poznat. */
    public function testWithoutStartPeriodNothingIsInProgress(): void
    {
        $result = PohodaPayrollSickness::read($this->write(), 2026, null);

        self::assertNull($result['start_period']);
        foreach ($result['cases'] as $case) {
            self::assertFalse($case['in_progress']);
            self::assertNull($case['window_used_calendar_days']);
        }
    }

    /** @param list<array<string,mixed>> $cases @return array<string,mixed> */
    private static function single(array $cases, string $personalNumber): array
    {
        foreach ($cases as $case) {
            if ($case['personal_number'] === $personalNumber) {
                return $case;
            }
        }
        self::fail("Případ osobního čísla {$personalNumber} ve výsledku není.");
    }

    /** Syntetický `91_mzdy.xml` s nepřítomnostmi; vrátí cestu k souboru. */
    private function write(): string
    {
        $xml = '';
        $row = static function (string $table, array $cols) use (&$xml): void {
            $xml .= "<{$table}>";
            foreach ($cols as $key => $value) {
                $xml .= "<{$key}>" . htmlspecialchars((string) $value, ENT_XML1) . "</{$key}>";
            }
            $xml .= "</{$table}>";
        };

        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'H01', 'Nazev' => 'Nemoc', 'JeNemDav' => 1]);
        $row('sMZneprit', ['ID' => 2, 'Cislo' => 'V01', 'Nazev' => 'Dovolená']);
        $row('sMZneprit', ['ID' => 3, 'Cislo' => 'H99', 'Nazev' => 'Vyrovnávací příspěvek v těhotenství a mateřství', 'JeNemDav' => 1]);

        $row('ZAM', ['ID' => 1, 'OsCislo' => '1001', 'Jmeno' => 'Jana', 'Prijmeni' => 'Testovací']);
        $row('ZAM', ['ID' => 2, 'OsCislo' => '1002', 'Jmeno' => 'Petr', 'Prijmeni' => 'Zkušební']);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'DatNast' => '2024-03-01']);
        $row('ZAMpomer', ['ID' => 2, 'RefZAM' => 2, 'Poradi' => 1, 'DatNast' => '2024-09-01']);

        $row('MZ', ['ID' => 11, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => 7]);
        $row('MZ', ['ID' => 12, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => 8]);
        $row('MZ', ['ID' => 21, 'RefZAM' => 2, 'RefPomer' => 2, 'Rok' => 2026, 'RelMes' => 3]);
        // Mzda jiného roku: její nepřítomnosti do převodu roku 2026 nepatří.
        $row('MZ', ['ID' => 31, 'RefZAM' => 2, 'RefPomer' => 2, 'Rok' => 2025, 'RelMes' => 12]);

        // Nemoc přes přelom, v PAMICA rozkrájená po měsících.
        $row('MZneprit', ['ID' => 1, 'RefAg' => 11, 'RefSlozka' => 1, 'DatZac' => '2026-07-20', 'DatKon' => '2026-07-31',
            'DnyKal' => 12, 'DnyPrac' => 9, 'HodPrac' => 72, 'KcNahr' => 8400.50]);
        $row('MZneprit', ['ID' => 2, 'RefAg' => 12, 'RefSlozka' => 1, 'DatZac' => '2026-08-01', 'DatKon' => '2026-08-05',
            'DnyKal' => 5, 'DnyPrac' => 3, 'HodPrac' => 24, 'KcNahr' => 2100]);
        // Dovolená patří jinému kroku převodu, tenhle ji nesmí zapsat.
        $row('MZneprit', ['ID' => 3, 'RefAg' => 11, 'RefSlozka' => 2, 'DatZac' => '2026-07-06', 'DatKon' => '2026-07-10',
            'DnyKal' => 5, 'DnyPrac' => 5, 'HodPrac' => 40]);
        // Nemoc uzavřená dávno před prvním měsícem vedení mezd.
        $row('MZneprit', ['ID' => 4, 'RefAg' => 21, 'RefSlozka' => 1, 'DatZac' => '2026-03-02', 'DatKon' => '2026-03-11',
            'DnyKal' => 10, 'DnyPrac' => 8, 'HodPrac' => 64, 'KcNahr' => 5600]);
        // Nemocenská složka, kterou evidence MyÚčta nezná.
        $row('MZneprit', ['ID' => 5, 'RefAg' => 21, 'RefSlozka' => 3, 'DatZac' => '2026-03-20', 'DatKon' => '2026-03-25',
            'DnyKal' => 6, 'DnyPrac' => 4, 'HodPrac' => 32]);
        // Nepřítomnost mzdy jiného roku: nesmí se přenést.
        $row('MZneprit', ['ID' => 6, 'RefAg' => 31, 'RefSlozka' => 1, 'DatZac' => '2025-12-01', 'DatKon' => '2025-12-20',
            'DnyKal' => 20, 'DnyPrac' => 14, 'HodPrac' => 112, 'KcNahr' => 9000]);

        // Příloha k žádosti o dávku: číslo rozhodnutí a rozhodné období (první dva měsíce
        // ještě z předchozího roku), odeslaná a přijatá.
        $row('NEMPRI', ['ID' => 1, 'RefStavDP' => 7, 'DatPrij' => '2026-08-12T10:00:00', 'ElOdeslano' => 1]);
        $row('NEMPRIpol', ['ID' => 1, 'RefAg' => 1, 'RokMZ' => 2026, 'MesicMZ' => 8, 'RefNeprit' => 2,
            'DruhDavky' => 'NEM', 'KodOSSZ' => '101', 'CisPotvrz' => '900000000000000003',
            'DatR1' => '2025-11-01', 'KcPrijR1' => 42000.5, 'VyldnyR1' => 0,
            'DatR2' => '2025-12-01', 'KcPrijR2' => 30000, 'VyldnyR2' => 4]);
        // Hlášení při ukončení neschopnosti, odeslané.
        $row('HZUPN', ['ID' => 4, 'RefStavDP' => 12, 'DatPrij' => '2026-08-06T09:00:00', 'ElOdeslano' => 1]);
        $row('HZUPNpol', ['ID' => 7, 'RefAg' => 4, 'RefNeprit' => 2, 'RokMZ' => 2026, 'MesicMZ' => 8,
            'CisloPotvrzeni' => '900000000000000003', 'DatumVystaveni' => '2026-08-06', 'NavratDoPrace' => 1,
            'DatumNavratuDoPrace' => '2026-08-06', 'PocetOdpracHodinPoslDenPD' => 0, 'PracovniDobaPoslDenPD' => 8]);

        // `MZnahr` ani `MZdavky` v souboru ZÁMĚRNĚ nejsou — přesně jako v reálném exportu.

        $file = $this->tmp . DIRECTORY_SEPARATOR . '91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="POHODA" state="ok">'
            . $xml . '</mdbExport>');

        return $file;
    }
}
