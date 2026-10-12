<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Premier;

/**
 * Syntetická záloha PREMIER firmy s hospodářským rokem od srpna (fiktivní data). Stejná
 * firma (IČO, kódy DPH) jako {@see SyntheticPremierBackup}, jen deník interních dokladů:
 *
 *   2024/2025  1. 8. 2024 vklad 10 000 do pokladny (211/411), 15. 9. 2024 služba 1 000
 *              (518/211), 10. 3. 2025 tržba 3 000 (602)
 *   2025/2026  5. 8. 2025 služba 500, 1. 2. 2026 tržba 700
 *
 * Podané přiznání k DPPO (`D_PO1`) za rok 2024 nese zdaňovací období 1. 8. 2024 až
 * 31. 7. 2025 - podle něj se pozná tvar roku PREMIER. Počáteční stavy 2025/2026:
 * pokladna 12 000, ZK -10 000, výsledek minulých let -2 000.
 */
final class FiscalPremierBackup
{
    public const YEAR = 2024;
    public const NEXT = 2025;

    /** @param bool $declared se zdaňovacím obdobím v `D_PO1` (jinak jen ROK) */
    public static function writeDir(string $dir, bool $declared = true): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $base = SyntheticPremierBackup::tables();
        $tables = [
            'SET_GLOB' => $base['SET_GLOB'],
            'KODY_DPH' => $base['KODY_DPH'],
            'OSNOVA' => [$base['OSNOVA'][0], self::chart()],
            'PUB_UCTO' => [$base['PUB_UCTO'][0], [
                self::row(1, '2024-08-01', '1', 'Vklad do pokladny', 10000, '211001', '411000'),
                self::row(2, '2024-09-15', '2', 'Služba', 1000, '518100', '211001'),
                self::row(3, '2025-03-10', '3', 'Tržba', 3000, '211001', '602100'),
                self::row(4, '2025-08-05', '4', 'Služba', 500, '518100', '211001'),
                self::row(5, '2026-02-01', '5', 'Tržba', 700, '211001', '602100'),
            ]],
            'D_PO1' => [
                [['ID_CISLO', 'C', 10], ['ROK', 'N', 4], ['DNE', 'C', 10], ['FORMA', 'C', 1], ['PRIZN_OD', 'C', 10], ['PRIZN_DO', 'C', 10]],
                [['ID_CISLO' => 'PO1', 'ROK' => self::YEAR, 'DNE' => '30.01.2026', 'FORMA' => 'B']
                    + ($declared ? ['PRIZN_OD' => '01.08.2024', 'PRIZN_DO' => '31.07.2025'] : [])],
            ],
        ];
        foreach ($tables as $name => [$fields, $rows]) {
            DbfWriter::write($dir . DIRECTORY_SEPARATOR . $name . '.DBF', $fields, $rows);
        }
        return $dir;
    }

    /** @return list<array<string,mixed>> */
    private static function chart(): array
    {
        $out = [];
        foreach ([self::YEAR, self::NEXT] as $year) {
            foreach ([['211', '001', 'Pokladna'], ['411', '000', 'Základní kapitál'], ['431', '000', 'Výsledek hospodaření'],
                ['518', '100', 'Ostatní služby'], ['602', '100', 'Tržby z prodeje služeb'], ['701', '000', 'Počáteční účet rozvažný']] as [$synthetic, $analytic, $name]) {
                $out[] = ['UCET' => $synthetic, 'ANALYT' => $analytic, 'TEXT' => $name, 'ROK' => $year];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function row(int $inter, string $date, string $number, string $text, float $amount, string $md, string $dal): array
    {
        return ['INTER' => $inter, 'DATUM' => $date, 'DATUM_DPH' => $date, 'DOKLAD' => 'ID', 'CISLO' => $number, 'POPIS' => $text,
            'CASTKA' => $amount, 'MD' => $md, 'DAL' => $dal];
    }
}
