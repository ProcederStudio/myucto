<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Účetní období, do kterého převod z cizího účetního programu zapisuje deník roku.
 *
 * Tvar účetního roku (kalendářní, nebo hospodářský rok) určuje FIRMA, ne převod:
 * {@see AccountingPeriodRepository::fiscalCalendar()} — období firmy, bez nich nastavení
 * „Účetní rok začíná" (zadává se předem). Konektor jen zařadí zápis podle data
 * ({@see ensureForDate()}) a ověří, že zdroj vede stejný rok ({@see assertSourceYear()}).
 *
 * PROČ NE {@see \MyInvoice\Service\Accounting\AccountingPeriodProvisioner}: to je
 * jediné pravidlo pro automatické zakládání období při účtování a importu dokladů,
 * ale převodu nesedí ve třech věcech, které by změnily jeho výsledek:
 *  - zakládá jen firmě, která už podvojné účetnictví vede; převod běží dřív, než se
 *    firmě režim přepne (převádí se do firmy v daňové evidenci);
 *  - převod smí první období zkrátit podle zdroje (firma založená během roku začíná
 *    dnem vzniku, Money S3) a přijímá existující období beze změny hranic;
 *  - zapisuje do `activity_log`, převod eviduje období ve své mapě (`*_import_map`),
 *    podle které ho při smazání převodu uklidí.
 * Existující období se stejně jako u provisioneru nikdy nemění; uzavřené se vrací
 * s `locked = true` a rozhodnutí nechá na volajícím.
 */
final class MigrationPeriods
{
    public const CREATED_REASON = 'import';

    private const MONTHS_CS = [
        1 => 'leden (kalendářní rok)', 2 => 'únor', 3 => 'březen', 4 => 'duben', 5 => 'květen', 6 => 'červen',
        7 => 'červenec', 8 => 'srpen', 9 => 'září', 10 => 'říjen', 11 => 'listopad', 12 => 'prosinec',
    ];

    public function __construct(private readonly AccountingPeriodRepository $periods) {}

    /** Kalendář účetního roku firmy — jediný zdroj tvaru období pro všechny převody. */
    public function calendar(int $supplierId): FiscalCalendar
    {
        return $this->periods->fiscalCalendar($supplierId);
    }

    /**
     * Období roku: existující, nebo nově založené (otevřené, důvod `import`).
     *
     * @param callable(int):void $remember zapíše NOVĚ založené období do mapy převodu
     * @param (callable(int):void)|null $adoptExisting volá se s id EXISTUJÍCÍHO období
     *        (Money S3 ho doplní do mapy, když v ní chybí); null = nic
     * @param bool $warnBoundsDiffer ohlásit, že existující období má jiné hranice
     *        (`period_bounds_differ`), než jaké by převod založil
     * @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}
     */
    public function ensure(
        int $supplierId,
        int $year,
        string $startsOn,
        string $endsOn,
        ImportProtocol $protocol,
        string $step,
        callable $remember,
        ?callable $adoptExisting = null,
        bool $warnBoundsDiffer = false,
    ): array {
        $existing = $this->periods->findByYear($supplierId, $year);
        if ($existing === null) {
            $id = $this->periods->create($supplierId, $year, $startsOn, $endsOn, self::CREATED_REASON);
            $remember($id);
            $protocol->count($step, 'periods_created');
            return ['id' => $id, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => 'open', 'locked' => false];
        }
        $id = (int) $existing['id'];
        if ($adoptExisting !== null) {
            $adoptExisting($id);
        }
        if ($warnBoundsDiffer && ((string) $existing['starts_on'] !== $startsOn || (string) $existing['ends_on'] !== $endsOn)) {
            $protocol->warn($step, 'period_bounds_differ', sprintf(
                'Období %d už v MyÚčtu existuje (%s – %s), převod ho použije beze změny hranic.',
                $year, $existing['starts_on'], $existing['ends_on']
            ), ['year' => $year]);
        }
        $status = (string) $existing['status'];
        return [
            'id' => $id,
            'starts_on' => (string) $existing['starts_on'],
            'ends_on' => (string) $existing['ends_on'],
            'status' => $status,
            'locked' => $status !== 'open',
        ];
    }

    /**
     * Období firmy, do kterého patří datum: existující (podle data), jinak založené
     * podle kalendáře účetního roku firmy. Label = rok začátku období.
     *
     * @param callable(int):void $remember
     * @param (callable(int):void)|null $adoptExisting
     * @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool,fiscal_year:int}
     */
    public function ensureForDate(
        int $supplierId,
        string $date,
        ImportProtocol $protocol,
        string $step,
        callable $remember,
        ?callable $adoptExisting = null,
    ): array {
        $existing = $this->periods->findForDate($supplierId, $date);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            if ($adoptExisting !== null) {
                $adoptExisting($id);
            }
            $status = (string) $existing['status'];
            return [
                'id' => $id,
                'starts_on' => (string) $existing['starts_on'],
                'ends_on' => (string) $existing['ends_on'],
                'status' => $status,
                'locked' => $status !== 'open',
                'fiscal_year' => (int) $existing['fiscal_year'],
            ];
        }
        $calendar = $this->calendar($supplierId);
        $year = $calendar->fiscalYearOfDate($date);
        [$startsOn, $endsOn] = self::bounds($calendar, $year);
        return $this->ensure($supplierId, $year, $startsOn, $endsOn, $protocol, $step, $remember, $adoptExisting)
            + ['fiscal_year' => $year];
    }

    /** @return array{0:string,1:string} první a poslední den období daného labelu */
    public static function bounds(FiscalCalendar $calendar, int $year): array
    {
        return [$calendar->periodStart($year), $calendar->periodEnd($year)];
    }

    /** @return array{0:string,1:string} kalendářní rok: první a poslední den */
    public static function calendarYear(int $year): array
    {
        return self::bounds(FiscalCalendar::calendar(), $year);
    }

    /**
     * Ověří, že jeden účetní rok ZDROJE padne do jednoho účetního roku firmy. Platí pro
     * všechny převody bez znalosti metadat zdroje: kalendářní agenda vůči hospodářskému
     * roku firmy (a obráceně) se rozpadne do dvou období a převod se zastaví s návrhem
     * správného nastavení.
     *
     * @param iterable<string> $dates data zápisů (Y-m-d…) jednoho účetního roku zdroje —
     *        bez zápisů, které zdroj sám řadí do pozdějšího roku
     * @param string $sourceYearLabel jak zdroj rok nazývá (do hlášky)
     * @throws FiscalYearMismatchException
     */
    public static function assertSourceYear(FiscalCalendar $company, iterable $dates, string $sourceYearLabel): void
    {
        $min = null;
        $max = null;
        $years = [];
        foreach ($dates as $date) {
            $day = substr((string) $date, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
                continue;
            }
            $years[$company->fiscalYearOfDate($day)] = true;
            $min = $min === null || $day < $min ? $day : $min;
            $max = $max === null || $day > $max ? $day : $max;
        }
        if (count($years) <= 1 || $min === null || $max === null) {
            return;
        }
        $suggested = (int) substr($min, 5, 2);
        throw new FiscalYearMismatchException(sprintf(
            'Účetní rok %s ve zdroji obsahuje zápisy od %s do %s, ale firma vede účetní rok od měsíce %s. '
                . 'Jeden účetní rok zdroje by se rozdělil do dvou účetních období. Nastavte ve firmě '
                . '(Nastavení → Daně a účetnictví → Účetní rok začíná) měsíc %s a převod spusťte znovu.',
            $sourceYearLabel,
            $min,
            $max,
            self::MONTHS_CS[$company->startMonth()],
            self::MONTHS_CS[$suggested],
        ), $suggested);
    }
}
