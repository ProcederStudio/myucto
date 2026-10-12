<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierJournal;
use MyInvoice\Service\Migration\Premier\PremierSourceYears;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Tests\Fixtures\Premier\FiscalPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Účetní rok PREMIER (zdaňovací období podaného DPPO) proti účetnímu roku firmy a deník
 * řazený do období firmy s hospodářským rokem.
 */
final class PremierSourceYearsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_fy_' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->tmp . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->tmp);
    }

    public function testDeclaredPeriodComesFromFiledReturn(): void
    {
        self::assertSame([2024 => ['2024-08-01', '2025-07-31']], PremierSourceYears::declared($this->backup()));
        self::assertSame([], PremierSourceYears::declared($this->backup(false)));
    }

    public function testFiscalCompanyAcceptsFiscalSource(): void
    {
        $backup = $this->backup();
        foreach ([2024, 2025] as $year) {
            PremierSourceYears::assertCompatible(new FiscalCalendar(8), $backup, $year, $backup->journalDates(...));
        }
        $this->addToAssertionCount(1);
    }

    public function testCalendarCompanyStopsOnFiscalSource(): void
    {
        $backup = $this->backup();
        try {
            PremierSourceYears::assertCompatible(FiscalCalendar::calendar(), $backup, 2025, $backup->journalDates(...));
            self::fail('Hospodářský rok PREMIER se nesmí rozdělit do dvou kalendářních období.');
        } catch (FiscalYearMismatchException $e) {
            self::assertSame(8, $e->suggestedStartMonth);
            self::assertStringContainsString('2024-08-01', $e->getMessage());
            self::assertStringContainsString('srpen', $e->getMessage());
        }
    }

    /** Rok bez přiznání: tvar z posledního přiznání, data zápisů deníku. */
    public function testYearWithoutReturnUsesShapeOfLastReturn(): void
    {
        $backup = $this->backup();
        $this->expectException(FiscalYearMismatchException::class);
        $this->expectExceptionMessage('2025/2026');
        PremierSourceYears::assertCompatible(FiscalCalendar::calendar(), $backup, 2026, $backup->journalDates(...));
    }

    public function testWithoutFiledPeriodNothingToCompare(): void
    {
        $backup = $this->backup(false);
        PremierSourceYears::assertCompatible(FiscalCalendar::calendar(), $backup, 2025, $backup->journalDates(...));
        $this->addToAssertionCount(1);
    }

    public function testTaxReturnHeadFollowsCompanyYear(): void
    {
        $backup = $this->backup();
        self::assertSame('PO1', trim((string) (PremierSourceYears::taxReturnHead($backup, 2024, new FiscalCalendar(8))['ID_CISLO'] ?? '')));
        self::assertNull(PremierSourceYears::taxReturnHead($backup, 2025, new FiscalCalendar(8)));
        // Kalendářní firma páruje podle ROK beze změny.
        self::assertNotNull(PremierSourceYears::taxReturnHead($backup, 2024));
        self::assertSame([2024, 2025], $backup->fiscalYears(new FiscalCalendar(8)));
        self::assertSame([2024, 2025, 2026], $backup->fiscalYears(FiscalCalendar::calendar()));
    }

    public function testJournalYearIsCompanyPeriod(): void
    {
        $fiscal = new PremierJournal($this->backup(), 2025, new FiscalCalendar(8));
        self::assertSame([1, 2, 3], array_column($fiscal->year(2024), 'inter'));
        self::assertSame([4, 5], array_column($fiscal->year(2025), 'inter'));
        self::assertTrue($fiscal->hasRowsBefore(2025));
        self::assertSame(2024, $fiscal->firstYear());
        self::assertSame(['211001' => 12000.0, '411000' => -10000.0, '431000' => -2000.0], $fiscal->openingBalances(2025, '431000'));

        $calendar = new PremierJournal($this->backup(), 2025);
        self::assertSame([3, 4], array_column($calendar->year(2025), 'inter'));
        self::assertSame(['211001' => 9000.0, '411000' => -10000.0, '431000' => 1000.0], $calendar->openingBalances(2025, '431000'));
    }

    private function backup(bool $declared = true): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . ($declared ? 'declared' : 'plain');
        if (!is_dir($dir)) {
            FiscalPremierBackup::writeDir($dir, $declared);
        }
        return PremierBackup::open($dir);
    }
}
