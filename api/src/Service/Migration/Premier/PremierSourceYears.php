<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;

/**
 * Účetní roky PREMIER proti účetnímu roku firmy.
 *
 * Globální nastavení zálohy (`SET_GLOB`) začátek hospodářského roku nenese (jen `akt_rok`)
 * a `PERIODY` jsou příznaky zamčení po měsících, bez dat. Tvar roku PREMIER prozradí jen
 * podané přiznání k DPPO: `D_PO1.PRIZN_OD` a `PRIZN_DO` jsou hranice zdaňovacího období
 * (u právnické osoby shodné s účetním obdobím). Rok bez přiznání (rozpracovaný) se
 * posoudí podle tvaru posledního přiznaného roku a dat zápisů deníku.
 *
 * Bez jediného přiznání v záloze tvar roku PREMIER ověřit nejde; zápisy se pak zařadí
 * podle data do období firmy.
 */
final class PremierSourceYears
{
    /** Delší období (první účetní období firmy založené ke konci roku) tvar roku neurčuje. */
    private const MAX_PERIOD_DAYS = 366;

    /**
     * Zdaňovací období z podaných přiznání: ROK → [od, do].
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function declared(PremierBackup $backup): array
    {
        $out = [];
        foreach ($backup->rows('D_PO1') as $h) {
            $from = self::czDate($h['PRIZN_OD'] ?? null);
            $to = self::czDate($h['PRIZN_DO'] ?? null);
            $year = (int) ($h['ROK'] ?? 0);
            if ($year > 0 && $from !== null && $to !== null && $from <= $to) {
                $out[$year] = [$from, $to];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Ověří, že žádný účetní rok PREMIER, který zasahuje do období `$year` firmy, nepadne
     * do dvou období firmy.
     *
     * @param callable():iterable<string> $journalDates data zápisů deníku (čtou se, jen když je potřeba)
     * @throws FiscalYearMismatchException
     */
    public static function assertCompatible(FiscalCalendar $company, PremierBackup $backup, int $year, callable $journalDates): void
    {
        [$start, $end] = MigrationPeriods::bounds($company, $year);
        $declared = self::declared($backup);
        $covered = [];
        $source = null;
        foreach ($declared as $rok => [$from, $to]) {
            if (self::days($from, $to) > self::MAX_PERIOD_DAYS) {
                continue;
            }
            $source = self::shapeOf($to) ?? $source;
            if ($from > $end || $to < $start) {
                continue;
            }
            MigrationPeriods::assertSourceYear($company, [$from, $to], (string) $rok);
            $covered[] = [$from, $to];
        }
        if ($source === null || $source->startMonth() === $company->startMonth()) {
            return;
        }
        // Rok bez přiznání: zápisy seskupené podle tvaru roku PREMIER (z posledního přiznání).
        $groups = [];
        $touches = [];
        foreach ($journalDates() as $date) {
            $day = substr((string) $date, 0, 10);
            foreach ($covered as [$from, $to]) {
                if ($day >= $from && $day <= $to) {
                    continue 2;
                }
            }
            $label = $source->fiscalYearOfDate($day);
            $groups[$label][] = $day;
            if ($day >= $start && $day <= $end) {
                $touches[$label] = true;
            }
        }
        foreach (array_keys($touches) as $label) {
            MigrationPeriods::assertSourceYear($company, $groups[$label], self::label($source, $label));
        }
    }

    /**
     * Hlavička podaného přiznání (`D_PO1`) k období `$year` firmy, poslední podání vyhrává.
     * Kalendářní firma páruje podle `ROK` jako dřív; firma s hospodářským rokem podle konce
     * zdaňovacího období (`PRIZN_DO`), bez něj podle `ROK`.
     *
     * @return array<string,mixed>|null
     */
    public static function taxReturnHead(PremierBackup $backup, int $year, ?FiscalCalendar $company = null): ?array
    {
        $head = null;
        foreach ($backup->rows('D_PO1') as $h) {
            if (self::taxReturnYear($h, $company) === $year) {
                $head = $h;
            }
        }
        return $head;
    }

    /** @param array<string,mixed> $h */
    private static function taxReturnYear(array $h, ?FiscalCalendar $company): int
    {
        if ($company === null || $company->isCalendar()) {
            return (int) ($h['ROK'] ?? 0);
        }
        $to = self::czDate($h['PRIZN_DO'] ?? null);
        return $to !== null ? $company->fiscalYearOfDate($to) : (int) ($h['ROK'] ?? 0);
    }

    /** Tvar roku podle posledního dne období, které končí posledním dnem měsíce. */
    private static function shapeOf(string $to): ?FiscalCalendar
    {
        if ($to !== (new \DateTimeImmutable($to))->format('Y-m-t')) {
            return null;
        }
        return new FiscalCalendar(((int) substr($to, 5, 2) % 12) + 1);
    }

    private static function label(FiscalCalendar $source, int $label): string
    {
        return $source->isCalendar() ? (string) $label : $label . '/' . ($label + 1);
    }

    private static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
    }

    /** Datum z přiznání PREMIER (`dd.mm.rrrr`, případně už `rrrr-mm-dd`). */
    private static function czDate(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
