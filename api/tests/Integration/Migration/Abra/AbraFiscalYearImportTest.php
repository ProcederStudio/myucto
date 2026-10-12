<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Migration\Abra;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Abra\AbraAccountingImporter;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Účetní období ABRA Flexi proti kalendáři účetního roku firmy: hospodářský rok se
 * převede jen do firmy, která ho vede, období zakládá sdílená vrstva převodů.
 */
#[Group('integration')]
final class AbraFiscalYearImportTest extends TestCase
{
    private Connection $db;
    private AbraAccountingImporter $importer;
    private int $supplier;
    private int $user;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(AbraAccountingImporter::class);
        $pdo = $this->db->pdo();
        $base = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $base);
        self::assertGreaterThan(0, $this->user);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO supplier (company_name, ic, street, city, zip, country_id, email, default_currency_id,
            default_vat_rate_id, accounting_enabled, accounting_mode)
            SELECT "Synthetic ABRA fiscal target", "88888888", "Testovací", "Praha", "11000", country_id,
                "abra-fiscal@example.com", default_currency_id, default_vat_rate_id, 1, "double_entry" FROM supplier WHERE id = ?')->execute([$base]);
        $this->supplier = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, "CZK", "CZK", "Kč", "Koruna", "Koruna", 2, 1, 1)')->execute([$this->supplier]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $this->supplier]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testFiscalAbraPeriodIntoFiscalCompanyIsCreatedBySharedLayer(): void
    {
        $this->startMonth(8);
        $report = $this->transfer($this->snapshot('2024', '2024-08-01', '2025-07-31'), 2024);

        self::assertFalse($report['blocked'], json_encode($report, JSON_UNESCAPED_UNICODE));
        $periods = $this->periods();
        self::assertSame([[
            'fiscal_year' => 2024, 'starts_on' => '2024-08-01', 'ends_on' => '2025-07-31',
            'created_reason' => MigrationPeriods::CREATED_REASON,
        ]], array_map(static fn (array $row): array => array_diff_key($row, ['id' => true]), $periods));
        $map = $this->db->pdo()->prepare("SELECT target_type, target_id FROM abra_flexi_import_map
            WHERE supplier_id = ? AND kind = 'ucetni-obdobi'");
        $map->execute([$this->supplier]);
        self::assertSame([['target_type' => 'accounting_period', 'target_id' => $periods[0]['id']]],
            array_map(static fn (array $row): array => ['target_type' => $row['target_type'], 'target_id' => (int) $row['target_id']],
                $map->fetchAll(\PDO::FETCH_ASSOC)));
        $entries = $this->db->pdo()->prepare('SELECT DISTINCT period_id FROM journal_entries WHERE supplier_id = ?');
        $entries->execute([$this->supplier]);
        self::assertSame([$periods[0]['id']], array_map('intval', $entries->fetchAll(\PDO::FETCH_COLUMN)));

        $again = $this->transfer($this->snapshot('2024', '2024-08-01', '2025-07-31'), 2024);
        self::assertFalse($again['blocked'], json_encode($again, JSON_UNESCAPED_UNICODE));
        self::assertCount(1, $this->periods());
    }

    public function testFiscalAbraPeriodIntoCalendarCompanyStops(): void
    {
        $report = $this->transfer($this->snapshot('2024', '2024-08-01', '2025-07-31'), 2024);

        self::assertTrue($report['blocked']);
        self::assertContains('fiscal_year_mismatch:2024', $report['warnings']);
        $messages = array_values(array_filter($report['warnings'], static fn (string $w): bool => str_contains($w, 'Účetní rok začíná')));
        self::assertCount(1, $messages);
        self::assertStringContainsString('měsíc srpen', $messages[0]);
        self::assertSame([], $this->periods());
    }

    public function testCalendarAbraPeriodIntoFiscalCompanyStops(): void
    {
        $this->startMonth(8);
        $report = $this->transfer($this->snapshot('2025', '2025-01-01', '2025-12-31'), 2025);

        self::assertTrue($report['blocked']);
        self::assertContains('fiscal_year_mismatch:2025', $report['warnings']);
        self::assertSame([], $this->periods());
    }

    public function testFiscalAbraPeriodLabelledByEndYearStops(): void
    {
        $this->startMonth(8);
        $report = $this->transfer($this->snapshot('2025', '2024-08-01', '2025-07-31'), 2025);

        self::assertTrue($report['blocked']);
        self::assertContains('source_period_label_mismatch:2025', $report['warnings']);
        self::assertSame([], $this->periods());
    }

    public function testCalendarAbraPeriodIntoCalendarCompanyKeepsCalendarBounds(): void
    {
        $report = $this->transfer($this->snapshot('2025', '2025-01-01', '2025-12-31'), 2025);

        self::assertFalse($report['blocked'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame([[
            'fiscal_year' => 2025, 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31',
            'created_reason' => MigrationPeriods::CREATED_REASON,
        ]], array_map(static fn (array $row): array => array_diff_key($row, ['id' => true]), $this->periods()));
    }

    private function startMonth(int $month): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = ? WHERE id = ?')->execute([$month, $this->supplier]);
    }

    private function transfer(array $snapshot, int $year): array
    {
        return $this->importer->import($this->supplier, $this->user, $snapshot, [$year], static function (): void {}, static fn (): bool => false);
    }

    /** @return list<array{id:int,fiscal_year:int,starts_on:string,ends_on:string,created_reason:?string}> */
    private function periods(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, fiscal_year, starts_on, ends_on, created_reason
            FROM accounting_periods WHERE supplier_id = ? ORDER BY fiscal_year');
        $stmt->execute([$this->supplier]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'fiscal_year' => (int) $row['fiscal_year'], 'starts_on' => (string) $row['starts_on'],
            'ends_on' => (string) $row['ends_on'], 'created_reason' => $row['created_reason'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Syntetický zdroj: jedno období ABRA, deník a předvaha s datem v jeho druhé polovině. */
    private function snapshot(string $code, string $starts, string $ends): array
    {
        $period = ['id' => '1', 'kod' => $code, 'platiOdData' => $starts, 'platiDoData' => $ends];
        $date = substr($ends, 0, 4) . '-06-01';
        $entries = [
            ['idUcetniDenik' => '1', 'datUcto' => $date, 'mdUcet' => 'code:221000', 'dalUcet' => 'code:602000', 'sumTuz' => '100.00'],
        ];
        return [
            '_meta' => ['mode' => 'initial', 'company' => ['ico' => '88888888', 'base_currency' => 'CZK'], 'periods' => [$period]],
            'ucetni-obdobi' => [$period],
            'ucetni-osnova' => array_map(static fn ($code) => ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'],
                ['221', '602', '221000', '602000']),
            'ucetni-denik' => $entries,
            'pohyb-na-uctech' => array_map(static fn ($row) => $row + ['sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz']], $entries),
            'faktura-vydana' => [], 'faktura-prijata' => [], 'pokladni-pohyb' => [], 'banka' => [], 'vazba' => [],
        ];
    }
}
