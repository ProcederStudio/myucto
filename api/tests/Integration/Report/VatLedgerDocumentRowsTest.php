<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Řádky DPH evidence jednoho dokladu pro zaúčtování.
 *
 * Zaúčtování dřív načetlo celou knihu DPH za rok a doklad si z ní vyfiltrovalo. U firmy
 * s tisíci doklady za rok to vyčerpalo paměť PHP a vystavení faktury skončilo HTTP 500
 * bez zaúčtování. documentRows() musí vrátit přesně tytéž normalizované řádky jako
 * filtr nad rows(), jen bez materializace ostatních dokladů.
 *
 * Izolovaný rok 2097 pod existujícím supplierem, úklid v tearDown.
 */
#[Group('integration')]
final class VatLedgerDocumentRowsTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private VatLedgerService $ledger;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $vendorId = 0;

    /** @var int[] */
    private array $purchaseIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container    = Bootstrap::buildContainer();
            $this->db     = $container->get(Connection::class);
            $this->ledger = $container->get(VatLedgerService::class);
        } catch (\Exception $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dodavatel řádků dokladu", "Test 1", "Praha", "11000", ?, "doc-rows@example.test", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->purchaseIds !== []) {
            $ph = implode(',', array_fill(0, count($this->purchaseIds), '?'));
            $pdo->prepare("DELETE FROM purchase_invoice_items WHERE purchase_invoice_id IN ($ph)")->execute($this->purchaseIds);
            $pdo->prepare("DELETE FROM purchase_invoices WHERE id IN ($ph)")->execute($this->purchaseIds);
        }
        if ($this->vendorId !== 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->vendorId]);
        }
        $this->db->close();
    }

    public function testPurchaseDocumentRowsMatchFilteredYearLedger(): void
    {
        $first  = $this->purchase('DOC-ROWS-1', 1000.0, 210.0);
        $target = $this->purchase('DOC-ROWS-2', 2000.0, 420.0, withSecondItem: true);
        $this->purchase('DOC-ROWS-3', 3000.0, 630.0);

        $start = sprintf('%04d-01-01', self::YEAR);
        $end   = sprintf('%04d-12-31', self::YEAR);

        $expected = array_values(array_filter(
            $this->ledger->rows($this->supplierId, $start, $end, true),
            static fn (array $r): bool => ($r['document_kind'] ?? null) !== 'cash'
                && $r['source'] === 'purchase'
                && (int) $r['invoice_id'] === $target,
        ));
        $actual = $this->ledger->documentRows($this->supplierId, 'purchase', $target, $start, $end, true);

        self::assertCount(2, $expected, 'Cílový doklad má dva řádky evidence.');
        self::assertSame($expected, $actual, 'Řádky dokladu musí být totožné s filtrem nad celoroční knihou.');
        self::assertSame([], $this->ledger->documentRows($this->supplierId, 'sale', $first, $start, $end, true),
            'Přijatý doklad se pod vydanou stranou nesmí objevit.');
    }

    public function testIterateRowsNarrowedToDocumentsMatchesFilteredPeriod(): void
    {
        $first  = $this->purchase('DOC-ROWS-A', 1000.0, 210.0);
        $second = $this->purchase('DOC-ROWS-B', 2000.0, 420.0, withSecondItem: true);
        $this->purchase('DOC-ROWS-C', 3000.0, 630.0);

        $start = sprintf('%04d-01-01', self::YEAR);
        $end   = sprintf('%04d-12-31', self::YEAR);
        $wanted = [$first => true, $second => true];

        $all = iterator_to_array($this->ledger->iterateRows($this->supplierId, $start, $end, true), false);
        self::assertSame($this->ledger->rows($this->supplierId, $start, $end, true), $all, 'Proud a pole nesou tytéž řádky.');

        $expected = array_values(array_filter(
            $all,
            static fn (array $r): bool => $r['source'] === 'purchase' && isset($wanted[(int) $r['invoice_id']]),
        ));
        $narrowed = iterator_to_array($this->ledger->iterateRows(
            $this->supplierId, $start, $end, true, ['purchase' => [$first, $second]],
        ), false);

        self::assertCount(3, $expected);
        self::assertSame($expected, $narrowed, 'Zúžení na doklady vrací přesně jejich řádky z období.');
        self::assertSame([], iterator_to_array($this->ledger->iterateRows(
            $this->supplierId, $start, $end, true, ['sale' => []],
        ), false), 'Prázdné zúžení nevrací nic, ani přijaté a pokladní doklady.');
    }

    private function purchase(string $number, float $base, float $vat, bool $withSecondItem = false): int
    {
        $date = sprintf('%04d-05-10', self::YEAR);
        $totalBase = $withSecondItem ? $base + 100.0 : $base;
        $totalVat  = $withSecondItem ? $vat + 21.0 : $vat;
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, received_at_source, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "manual", ?, 1, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->vendorId, $number, $date, $date, $date, $date,
            $this->currencyId, $totalBase, $totalVat, $totalBase + $totalVat, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;

        $item = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, "Test", 1, "ks", ?, ?, 21, ?, ?, ?, ?, "40")'
        );
        $item->execute([$id, $base, $this->vatRateId, $base, $vat, $base + $vat, 0]);
        if ($withSecondItem) {
            $item->execute([$id, 100.0, $this->vatRateId, 100.0, 21.0, 121.0, 1]);
        }

        return $id;
    }
}
