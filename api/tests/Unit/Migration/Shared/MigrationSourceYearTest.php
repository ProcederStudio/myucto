<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Shared;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use PHPUnit\Framework\TestCase;

/**
 * Jeden účetní rok zdroje musí padnout do jednoho účetního roku firmy — jinak se převod
 * zastaví a navrhne začátek účetního roku podle zdroje.
 */
final class MigrationSourceYearTest extends TestCase
{
    public function testFiscalSourceFitsFiscalCompany(): void
    {
        MigrationPeriods::assertSourceYear(new FiscalCalendar(8), ['2024-08-01', '2024-12-31', '2025-07-31'], '2024');
        $this->addToAssertionCount(1);
    }

    public function testCalendarSourceFitsCalendarCompany(): void
    {
        MigrationPeriods::assertSourceYear(FiscalCalendar::calendar(), ['2025-01-01 00:00:00', '2025-12-31'], '2025');
        $this->addToAssertionCount(1);
    }

    public function testFiscalSourceIntoCalendarCompanyStopsWithAugustSuggestion(): void
    {
        try {
            MigrationPeriods::assertSourceYear(FiscalCalendar::calendar(), ['2024-08-01', '2025-03-01', '2025-07-31'], '2024/2025');
            self::fail('Hospodářský rok zdroje se nesmí rozdělit do dvou kalendářních let.');
        } catch (FiscalYearMismatchException $e) {
            self::assertSame(8, $e->suggestedStartMonth);
            self::assertStringContainsString('srpen', $e->getMessage());
        }
    }

    public function testCalendarSourceIntoFiscalCompanyStopsWithJanuarySuggestion(): void
    {
        try {
            MigrationPeriods::assertSourceYear(new FiscalCalendar(8), ['2025-01-02', '2025-09-30'], '2025');
            self::fail('Kalendářní rok zdroje se nesmí rozdělit do dvou hospodářských let.');
        } catch (FiscalYearMismatchException $e) {
            self::assertSame(1, $e->suggestedStartMonth);
        }
    }

    public function testInvalidAndEmptyDatesAreIgnored(): void
    {
        MigrationPeriods::assertSourceYear(FiscalCalendar::calendar(), ['', 'n/a', '2025-05-05'], '2025');
        MigrationPeriods::assertSourceYear(FiscalCalendar::calendar(), [], '2025');
        $this->addToAssertionCount(2);
    }

    public function testBoundsFollowCalendar(): void
    {
        self::assertSame(['2025-08-01', '2026-07-31'], MigrationPeriods::bounds(new FiscalCalendar(8), 2025));
        self::assertSame(['2025-01-01', '2025-12-31'], MigrationPeriods::calendarYear(2025));
    }
}
