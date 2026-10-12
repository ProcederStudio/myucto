<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\MoneyS3\Ms3Journal;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use PHPUnit\Framework\TestCase;

/**
 * Adresář ROK zálohy Money je jeden účetní rok zdroje a stává se jedním účetním obdobím
 * firmy podle jejího kalendáře (nastavení „Účetní rok začíná"). Rok zdroje, který by se
 * rozpadl do dvou období, zastaví kontrola tvaru roku jednou srozumitelnou chybou
 * s navrženým měsícem místo stovek chyb „zápis mimo období".
 */
final class FiscalYearShapeTest extends TestCase
{
    /** @return list<array<string,mixed>> hospodářský rok srpen 2024 – červenec 2025 */
    private static function fiscalAgenda(): array
    {
        $rows = [['Zdroj' => 'XP', 'Datum' => '2024-08-01', 'Popis' => 'Počáteční stav roku 2024']];
        foreach (['2024-08-15', '2024-09-15', '2024-10-15', '2024-11-15', '2024-12-15', '2025-01-15',
            '2025-02-15', '2025-03-15', '2025-04-15', '2025-05-15', '2025-06-15', '2025-07-31'] as $date) {
            $rows[] = ['Zdroj' => 'FP', 'Datum' => $date];
        }
        return $rows;
    }

    public function testFiscalAgendaIsOneYearOfFiscalCompany(): void
    {
        $calendar = new FiscalCalendar(8);
        $summary = Ms3Journal::summarize(self::fiscalAgenda(), $calendar);

        self::assertSame(2024, $summary['fiscal_year'], 'label období = rok jeho začátku');
        self::assertTrue($summary['single_year']);
        self::assertSame('2024-08-15', $summary['first_entry']);
        MigrationPeriods::assertSourceYear($calendar, $summary['source_dates'], '2024');
        self::assertSame(['2024-08-01', '2025-07-31'], MigrationPeriods::bounds($calendar, (int) $summary['fiscal_year']));
    }

    public function testFiscalAgendaInCalendarCompanyStopsWithSuggestedMonth(): void
    {
        $calendar = FiscalCalendar::calendar();
        $summary = Ms3Journal::summarize(self::fiscalAgenda(), $calendar);

        self::assertFalse($summary['single_year']);
        try {
            MigrationPeriods::assertSourceYear($calendar, $summary['source_dates'], '2025 (adresář ROK.001)');
            self::fail('Hospodářský rok se do kalendářních období nerozdělí.');
        } catch (FiscalYearMismatchException $e) {
            self::assertSame(8, $e->suggestedStartMonth, 'počáteční stavy datuje Money prvním dnem svého roku');
            self::assertStringContainsString('srpen', $e->getMessage());
        }
    }

    public function testCalendarAgendaInFiscalCompanyStops(): void
    {
        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $rows[] = ['Zdroj' => 'BK', 'Datum' => sprintf('2025-%02d-10', $m)];
        }
        $calendar = new FiscalCalendar(8);

        $this->expectException(FiscalYearMismatchException::class);
        MigrationPeriods::assertSourceYear($calendar, Ms3Journal::summarize($rows, $calendar)['source_dates'], '2025');
    }

    public function testSingleStrayEntryDoesNotStopCalendarYear(): void
    {
        $rows = [['Zdroj' => 'XP', 'Datum' => '2023-12-31']];
        for ($m = 1; $m <= 12; $m++) {
            $rows[] = ['Zdroj' => 'BK', 'Datum' => sprintf('2024-%02d-10', $m)];
        }
        $rows[] = ['Zdroj' => 'ID', 'Datum' => '2025-01-01'];
        $calendar = FiscalCalendar::calendar();
        $summary = Ms3Journal::summarize($rows, $calendar);

        self::assertSame(2024, $summary['fiscal_year']);
        self::assertTrue($summary['single_year']);
        self::assertSame(['2024-01-10', '2024-12-10'], $summary['source_dates'], 'zápis mimo rok ani datum PS kontrolu nezastaví');
        MigrationPeriods::assertSourceYear($calendar, $summary['source_dates'], '2024');
    }
}
