<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Tests\Fixtures\Premier\FiscalPremierBackup;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod z PREMIER do firmy s hospodářským rokem (od srpna) nad skutečnou DB: období mají
 * tvar 1. 8. - 31. 7., zápisy padnou do období podle data, počáteční stavy dalšího roku jsou
 * k 1. 8. Hospodářský rok PREMIER do kalendářní firmy převod zastaví. Izolovaná firma,
 * transakce s rollbackem.
 */
#[Group('integration')]
final class PremierFiscalYearImportTest extends TestCase
{
    private Connection $db;
    private PremierImporter $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PremierImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('premier_import_map', 'premier_key') || !$this->db->hasColumn('supplier', 'fiscal_year_start_month')) {
            $this->markTestSkipped('Chybí migrace převodu PREMIER nebo začátku účetního roku.');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_fy_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testFiscalCompanyGetsFiscalPeriodsAndOpeningOnFirstOfAugust(): void
    {
        $supplierId = $this->supplier(8);
        $backup = $this->backup();

        $first = $this->importer->run($supplierId, $this->userId, $backup, FiscalPremierBackup::YEAR, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertSame(['2024-08-01', '2025-07-31'], $this->bounds($supplierId, FiscalPremierBackup::YEAR));
        self::assertSame(['2024-08-01', '2024-09-15', '2025-03-10'], $this->entryDates($supplierId, FiscalPremierBackup::YEAR));
        self::assertTrue($first->get('reconciliation')[0]['ok'] ?? false, $this->explain($first));
        // Podané DPPO za 2024/2025: rok se uzavře posledním dnem hospodářského roku.
        self::assertSame(['2025-07-31'], $this->entryDates($supplierId, FiscalPremierBackup::YEAR, 'closing'), $this->explain($first));

        $second = $this->importer->run($supplierId, $this->userId, $backup, FiscalPremierBackup::NEXT, false);
        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertSame(['2025-08-01', '2026-07-31'], $this->bounds($supplierId, FiscalPremierBackup::NEXT));
        self::assertSame(['2025-08-05', '2026-02-01'], $this->entryDates($supplierId, FiscalPremierBackup::NEXT));
        self::assertTrue($second->get('reconciliation')[0]['ok'] ?? false, $this->explain($second));

        // Jediný otevírací zápis roku 2025/2026 k 1. 8. 2025 (z uzávěrky, nebo z převodu).
        $opening = $this->db->pdo()->prepare(
            "SELECT e.entry_date, c.account_code AS code, SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS balance
               FROM journal_entries e
               JOIN accounting_periods p ON p.id = e.period_id
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE e.supplier_id = ? AND p.fiscal_year = ? AND e.source_type = 'opening' AND e.reversed_by IS NULL
                AND c.account_code IN ('211.001', '411.000')
              GROUP BY e.entry_date, c.account_code ORDER BY c.account_code"
        );
        $opening->execute([$supplierId, FiscalPremierBackup::NEXT]);
        $rows = $opening->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['2025-08-01', '211.001', 12000.0], ['2025-08-01', '411.000', -10000.0]],
            array_map(static fn (array $r): array => [(string) $r['entry_date'], (string) $r['code'], round((float) $r['balance'], 2)], $rows), $this->explain($second));
    }

    public function testCalendarCompanyStopsOnFiscalSource(): void
    {
        $supplierId = $this->supplier(1);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), FiscalPremierBackup::NEXT, false);
        self::assertTrue($protocol->hasErrors());
        $errors = array_values(array_filter((array) $protocol->get('preflight'), static fn (array $m): bool => $m['code'] === 'fiscal_year_mismatch'));
        self::assertCount(1, $errors, $this->explain($protocol));
        self::assertStringContainsString('srpen', $errors[0]['message']);
        self::assertSame(8, $errors[0]['context']['suggested_start_month']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM accounting_periods WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?', $supplierId));
    }

    private function backup(): PremierBackup
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup';
        if (!is_dir($dir)) {
            FiscalPremierBackup::writeDir($dir);
        }
        return PremierBackup::open($dir);
    }

    private function supplier(int $startMonth): int
    {
        $pdo = $this->db->pdo();
        $ico = SyntheticPremierBackup::ICO;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, vat_period, default_currency_id, default_vat_rate_id, accounting_mode, fiscal_year_start_month)
             VALUES (?, "Účetní 1", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, "monthly", ?, ?, "tax_evidence", ?)'
        )->execute([SyntheticPremierBackup::NAME, $this->czId, $ico, 'CZ' . $ico, $this->anyCurrencyId, $this->vatRateId, $startMonth]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @return array{0:string,1:string}|null */
    private function bounds(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?');
        $stmt->execute([$supplierId, $year]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : [(string) $row['starts_on'], (string) $row['ends_on']];
    }

    /** @return list<string> data zápisů období: převzatých, nebo jen daného zdroje */
    private function entryDates(int $supplierId, int $year, ?string $source = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.entry_date FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND p.fiscal_year = ? AND e.reversed_by IS NULL
                AND " . ($source === null ? "e.source_type NOT IN ('opening', 'closing')" : 'e.source_type = ' . $this->db->pdo()->quote($source)) . '
              ORDER BY e.entry_date'
        );
        $stmt->execute([$supplierId, $year]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function scalar(string $sql, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
