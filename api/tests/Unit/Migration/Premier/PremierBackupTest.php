<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierException;
use MyInvoice\Tests\Fixtures\Premier\CabWriter;
use MyInvoice\Tests\Fixtures\Premier\FiscalPremierBackup;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/** Rozbalení zálohy PREMIER (iZIP / iCAB) a přehled firmy a let v ní. */
final class PremierBackupTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_backup_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp === '' || !is_dir($this->tmp)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    public function testZipAndCabExtractToTheSameUppercaseTables(): void
    {
        $zipDir = $this->tmp . '/zip';
        $cabDir = $this->tmp . '/cab';
        PremierBackup::extractArchive(SyntheticPremierBackup::writeZip($this->tmp . '/zaloha.izip', $this->tmp), $zipDir);
        PremierBackup::extractArchive(SyntheticPremierBackup::writeCab($this->tmp . '/zaloha.icab', $this->tmp), $cabDir);

        $expected = array_keys(SyntheticPremierBackup::files($this->tmp));
        self::assertContains('PUB_UCTO.DBF', $expected);
        self::assertContains('PUB_UCTO.FPT', $expected);
        self::assertSame($expected, $this->listing($zipDir), 'Jména se sjednotí na velká písmena, zaloha.txt se vynechá.');
        self::assertSame($expected, $this->listing($cabDir));
        foreach ($expected as $name) {
            self::assertSame(md5_file($zipDir . '/' . $name), md5_file($cabDir . '/' . $name), $name);
        }

        $zip = PremierBackup::open($zipDir);
        $cab = PremierBackup::open($cabDir);
        self::assertSame($zip->all('PUB_UCTO'), $cab->all('PUB_UCTO'));
        self::assertSame([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2], $cab->years());
    }

    public function testOverviewListsYearsWithCompany(): void
    {
        $dir = SyntheticPremierBackup::writeDir($this->tmp . '/data');
        $backup = PremierBackup::open($dir);
        self::assertSame(SyntheticPremierBackup::ICO, $backup->ico);
        self::assertSame(SyntheticPremierBackup::DIC, $backup->dic, 'DIČ bez mezer.');
        self::assertSame(SyntheticPremierBackup::NAME, $backup->company);
        self::assertSame('CZK', $backup->homeCurrency);
        self::assertSame('2025-01-01', $backup->setting('A_ZACATEK'), 'Datum z D_SET, klíč bez ohledu na velikost písmen.');
        self::assertSame(100.0, (float) $backup->setting('a_koef'));
        self::assertNull($backup->setting('neexistuje'));

        // Smazaný zápis (hvězdička) se do počtu nezapočte.
        self::assertSame([2025 => 21, 2026 => 6], $backup->journalCountsByYear());

        $overview = PremierBackup::overview($dir);
        self::assertSame([2025, 2026], array_column($overview, 'year'));
        self::assertSame([21, 6], array_column($overview, 'entries'));
        foreach ($overview as $agenda) {
            self::assertSame(SyntheticPremierBackup::ICO, $agenda['ico']);
            self::assertSame(SyntheticPremierBackup::NAME, $agenda['company']);
            self::assertTrue($agenda['has_accounting']);
            self::assertFalse($agenda['has_payroll']);
        }
    }

    /**
     * Náhled průvodce vychází z přehledu nahrání v `meta.json`: kontrola před převodem
     * každého roku dřív znovu četla celý deník (roky) a mzdy (poslední měsíc) zálohy, u
     * reálné zálohy desítky sekund. S přehledem se deník ani mzdy nečtou - tady je po
     * otevření zálohy přepíše prázdná tabulka a hodnoty zůstanou z přehledu.
     */
    public function testBackupWithOverviewDoesNotReadJournalOrPayrollAgain(): void
    {
        $dir = SyntheticPremierBackup::writeDir($this->tmp . '/data', false, ['payroll' => true]);
        $overview = PremierBackup::overview($dir);
        $last = PremierBackup::open($dir)->lastPayrollPeriod();
        self::assertNotNull($last);
        foreach ($overview as $agenda) {
            self::assertTrue($agenda['has_payroll']);
            self::assertSame($last, $agenda['payroll_last'], 'Přehled nese poslední měsíc mezd.');
        }

        $backup = PremierBackup::open($dir)->withOverview($overview);
        copy($dir . '/SET_GLOB.DBF', $dir . '/PUB_UCTO.DBF');
        copy($dir . '/SET_GLOB.DBF', $dir . '/MZDY.DBF');
        self::assertSame([2025, 2026], $backup->years());
        self::assertSame($last, $backup->lastPayrollPeriod());
        self::assertSame([], PremierBackup::open($dir)->years(), 'Bez přehledu se roky čtou z (teď prázdného) deníku.');

        // Přehled nahraný dřív poslední měsíc mezd nemá: ten se dočte ze zálohy.
        $legacy = array_map(static function (array $agenda): array {
            unset($agenda['payroll_last']);
            return $agenda;
        }, $overview);
        self::assertNull(PremierBackup::open($dir)->withOverview($legacy)->lastPayrollPeriod());
    }

    /**
     * Účetní roky firmy s hospodářským rokem z přehledu nahrání: počty po měsících stačí,
     * deník se znovu nečte (tady ho po přehledu přepíše prázdná tabulka). Firma založená
     * v únoru 2025 má hospodářský rok 2024, který kalendářní rok zálohy nemá.
     */
    public function testCompanyOverviewUsesCompanyYearsFromMonthCounts(): void
    {
        $dir = FiscalPremierBackup::writeDir($this->tmp . '/fiscal', false, ['2025-02-10', '2025-03-15', '2025-06-20', '2025-09-01', '2026-01-15']);
        $overview = PremierBackup::overview($dir);
        self::assertSame([2025, 2026], array_column($overview, 'year'));
        self::assertSame(['2025-02' => 1, '2025-03' => 1, '2025-06' => 1, '2025-09' => 1], $overview[0]['months']);

        copy($dir . '/SET_GLOB.DBF', $dir . '/PUB_UCTO.DBF');
        $backup = PremierBackup::open($dir)->withOverview($overview);
        $fiscal = new FiscalCalendar(8);
        self::assertSame([2024, 2025], $backup->fiscalYears($fiscal));
        self::assertSame([
            ['year' => 2024, 'entries' => 3, 'period_start' => '2024-08-01', 'period_end' => '2025-07-31'],
            ['year' => 2025, 'entries' => 2, 'period_start' => '2025-08-01', 'period_end' => '2026-07-31'],
        ], array_map(static fn (array $a): array => array_intersect_key($a, array_flip(['year', 'entries', 'period_start', 'period_end'])), $backup->companyOverview($overview, $fiscal)));

        // Kalendářní firma: přehled nahrání beze změny, jen bez počtů po měsících.
        $calendar = $backup->companyOverview($overview, FiscalCalendar::calendar());
        self::assertSame(PremierBackup::withoutMonths($overview), $calendar);
        self::assertArrayNotHasKey('months', $calendar[0]);
    }

    public function testArchiveWithoutJournalIsRejected(): void
    {
        $files = SyntheticPremierBackup::files($this->tmp);
        unset($files['PUB_UCTO.DBF'], $files['PUB_UCTO.FPT']);
        $zip = new \ZipArchive();
        $zip->open($this->tmp . '/bez_deniku.izip', \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        self::assertSame('backup_not_premier', $this->error(fn () => PremierBackup::extractArchive($this->tmp . '/bez_deniku.izip', $this->tmp . '/out')));
    }

    public function testNonArchiveIsRejected(): void
    {
        file_put_contents($this->tmp . '/text.izip', 'toto není archiv');
        self::assertSame('backup_not_archive', $this->error(fn () => PremierBackup::extractArchive($this->tmp . '/text.izip', $this->tmp . '/out')));
    }

    public function testOpenRequiresCoreTables(): void
    {
        $dir = SyntheticPremierBackup::writeDir($this->tmp . '/data');
        unlink($dir . '/KODY_DPH.DBF');
        try {
            PremierBackup::open($dir);
            self::fail('Záloha bez číselníku DPH musí být odmítnuta.');
        } catch (PremierException $e) {
            self::assertSame(['backup_incomplete', 'KODY_DPH'], [$e->errorCode, $e->context['table']]);
        }
    }

    /**
     * Přebalený archiv: kopie tabulek o složku hlouběji (s jiným obsahem) a soubory, které
     * nejsou tabulky. Platí mělčí kopie, i když je v archivu až za hlubší.
     */
    public function testShallowestCopyWinsAndNonTablesAreIgnored(): void
    {
        $files = SyntheticPremierBackup::files($this->tmp);
        $zip = new \ZipArchive();
        $zip->open($this->tmp . '/prebaleny.izip', \ZipArchive::CREATE);
        $zip->addFromString('ZALOHA/STARA/pub_ucto.dbf', 'hlubší kopie - neplatí');
        $zip->addFromString('ZALOHA/STARA/Set_Glob.DBF', 'hlubší kopie - neplatí');
        foreach ($files as $name => $content) {
            $zip->addFromString('ZALOHA/' . $name, $content);
        }
        $zip->addFromString('ZALOHA/readme.txt', 'text');
        $zip->addFromString('ZALOHA/FOXUSER.DBF.bak', 'záloha');
        $zip->addFromString('ZALOHA/../../utek.dbf', 'mimo cíl');
        $zip->addFromString('ZALOHA/podivne jmeno.dbf', 'mezera ve jménu');
        $zip->close();

        PremierBackup::extractArchive($this->tmp . '/prebaleny.izip', $this->tmp . '/out');

        self::assertSame(md5($files['PUB_UCTO.DBF']), md5_file($this->tmp . '/out/PUB_UCTO.DBF'));
        self::assertSame(md5($files['SET_GLOB.DBF']), md5_file($this->tmp . '/out/SET_GLOB.DBF'));
        $expected = array_merge(array_keys($files), ['UTEK.DBF']);
        sort($expected);
        self::assertSame($expected, $this->listing($this->tmp . '/out'), 'Mimo cíl nic nevznikne - jméno se bere bez cesty.');
        self::assertFileDoesNotExist($this->tmp . '/utek.dbf');
        self::assertSame(SyntheticPremierBackup::ICO, PremierBackup::open($this->tmp . '/out')->ico);
    }

    public function testCabWithNestedDuplicateKeepsFirstCopy(): void
    {
        $files = SyntheticPremierBackup::files($this->tmp);
        $cab = [];
        foreach ($files as $name => $content) {
            $cab['DATA\\' . $name] = $content;
        }
        $cab['DATA\\STARA\\PUB_UCTO.DBF'] = 'druhá kopie - neplatí';
        CabWriter::write($this->tmp . '/zaloha.icab', $cab);

        PremierBackup::extractArchive($this->tmp . '/zaloha.icab', $this->tmp . '/out');
        self::assertSame(md5($files['PUB_UCTO.DBF']), md5_file($this->tmp . '/out/PUB_UCTO.DBF'));
    }

    /** @return list<string> */
    private function listing(string $dir): array
    {
        $out = array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => is_file($dir . '/' . $f)));
        sort($out);
        return $out;
    }

    private function error(callable $fn): string
    {
        try {
            $fn();
        } catch (PremierException $e) {
            return $e->errorCode;
        }
        self::fail('Očekávaná chyba PremierException nenastala.');
    }
}
