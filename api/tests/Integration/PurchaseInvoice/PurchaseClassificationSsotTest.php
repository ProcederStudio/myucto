<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Audit VAT klasifikací 2026-08, nález H-6 — klasifikaci přijatého dokladu smí určovat
 * jediný country-aware SSOT {@see PurchaseInvoiceRepository::defaultClassificationCode()}.
 *
 * Create/UpdatePurchaseInvoiceAction dřív kód předsadily přes VatClassificationDefaulter,
 * který zemi dodavatele ani plátcovství tenanta nezná: DB lookup pro (purchase, 21 %, RC)
 * vrací podle display_order '24e', takže tuzemský doklad v režimu přenesené povinnosti
 * (§ 92e stavební práce) skončil na ř. 5 + KH A.2 jako služba z EU místo ř. 10 + KH B.1.
 * A protože akce kód dosadila, replaceItems() už SSOT nepustil ke slovu (derivuje jen
 * z NULL). Test drží obojí: derivaci na řádcích i převzetí kódu na hlavičku.
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 */
#[Group('integration')]
final class PurchaseClassificationSsotTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;
    private int $usId = 0;
    private int $vatRate21Id = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];
    /** @var int[] */
    private array $vatHistoryIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId  = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId  = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId      = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId        = $this->countryId('CZ');
        $this->deId        = $this->countryId('DE');
        $this->usId        = $this->countryId('US');
        $this->vatRate21Id = (int) ($pdo->query('SELECT id FROM vat_rates WHERE ABS(rate_percent - 21) < 0.5 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0
            || $this->czId === 0 || $this->deId === 0 || $this->usId === 0 || $this->vatRate21Id === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vatHistoryIds as $id) {
            $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /** Tuzemský § 92a/§ 92e doklad → ř. 10 + KH B.1 (kód 5), NE služba z EU. */
    public function testDomesticReverseChargeGetsDomesticCode(): void
    {
        $vendor = $this->vendor('Stavební firma', $this->czId, 'CZ29100001');
        $code = $this->classify($vendor, 'SSOT-1', reverseCharge: true);

        self::assertSame('5', $code, 'tuzemský reverse charge je § 92a, ne přijetí služby z EU');
    }

    /** EU dodavatel + RC + základní sazba, povaha neznámá → přijetí služby z EU (ř. 5), issue #150. */
    public function testEuReverseChargeWithoutNatureGetsServiceCode(): void
    {
        $vendor = $this->vendor('EU dodavatel', $this->deId, 'DE123456789');
        $code = $this->classify($vendor, 'SSOT-2', reverseCharge: true);

        self::assertSame('24e', $code);
    }

    /** EU dodavatel + RC + materiál → pořízení zboží z JČS (ř. 3 + 43, KH A.2). */
    public function testEuReverseChargeGoodsGetsAcquisitionCode(): void
    {
        $vendor = $this->vendor('EU dodavatel zboží', $this->deId, 'DE123456780');
        $code = $this->classify($vendor, 'SSOT-2G', reverseCharge: true, item: ['expense_kind' => 'material']);

        self::assertSame('23', $code);
    }

    /** Dodavatel ze 3. země + RC → přijetí služby od neusazené osoby (ř. 12), ne § 92a. */
    public function testThirdCountryReverseChargeGetsServiceCode(): void
    {
        $vendor = $this->vendor('US dodavatel', $this->usId, null);
        $code = $this->classify($vendor, 'SSOT-3', reverseCharge: true);

        self::assertSame('24', $code, 'přenesenou povinnost § 92a se zahraničním dodavatelem uplatnit nelze');
    }

    /** Regrese: běžné tuzemské plnění se sazbou beze změny. */
    public function testDomesticStandardRateUnchanged(): void
    {
        $vendor = $this->vendor('Tuzemský dodavatel', $this->czId, 'CZ29100004');
        $code = $this->classify($vendor, 'SSOT-4', reverseCharge: false);

        self::assertSame('40', $code);
    }

    /**
     * Neplátce / identifikovaná osoba nemůže být v tuzemském § 92a — kód '5' by jí vyrobil
     * samovyměření na ř. 10 a větu KH B.1, které nedluží.
     */
    public function testNonPayerTenantGetsNoDomesticReverseCharge(): void
    {
        $this->forceTenantNonPayer('2099-01-01');
        $vendor = $this->vendor('Stavební firma pro neplátce', $this->czId, 'CZ29100005');
        $code = $this->classify($vendor, 'SSOT-5', reverseCharge: true);

        self::assertSame('40', $code, 'neplátci se tuzemský § 92a nepřiřadí');
    }

    /** Hlavička kód nevymýšlí — přebírá dominantní kód z řádků. */
    public function testHeaderClassificationIsTakenFromItems(): void
    {
        $vendor = $this->vendor('Dodavatel hlavička', $this->deId, 'DE987654321');
        $id = $this->createWithItem($vendor, 'SSOT-6', reverseCharge: true);

        self::assertNull(
            $this->repo->find($id, $this->supplierId)['vat_classification_code'],
            'hlavička je po uložení prázdná — kód nese řádek',
        );

        $this->repo->syncHeaderClassificationFromItems($id, $this->supplierId);

        self::assertSame('24e', $this->repo->find($id, $this->supplierId)['vat_classification_code']);
    }

    /** Ručně zvolený kód na hlavičce sync nepřepíše. */
    public function testHeaderSyncKeepsManualChoice(): void
    {
        $vendor = $this->vendor('Dodavatel ruční kód', $this->deId, 'DE555555555');
        $id = $this->createWithItem($vendor, 'SSOT-7', reverseCharge: true, headerCode: '25');

        $this->repo->syncHeaderClassificationFromItems($id, $this->supplierId);

        self::assertSame('25', $this->repo->find($id, $this->supplierId)['vat_classification_code']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Založí doklad s jedním řádkem bez kódu a vrátí kód, který SSOT derivoval. */
    /** @param array<string,mixed> $item */
    private function classify(int $vendorId, string $number, bool $reverseCharge, array $item = []): ?string
    {
        $id = $this->createWithItem($vendorId, $number, $reverseCharge, item: $item);
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_classification_code FROM purchase_invoice_items WHERE purchase_invoice_id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $code = $stmt->fetchColumn();
        return $code === false || $code === null ? null : (string) $code;
    }

    /** @param array<string,mixed> $item */
    private function createWithItem(int $vendorId, string $number, bool $reverseCharge, ?string $headerCode = null, array $item = []): int
    {
        $payload = [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => 'invoice',
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => $reverseCharge,
        ];
        if ($headerCode !== null) {
            $payload['vat_classification_code'] = $headerCode;
        }
        $id = $this->repo->createDraft($payload, $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        // Řádek BEZ kódu — přesně tak, jak ho po opravě H-6 posílá Create/UpdateAction.
        $this->repo->replaceItems($id, [$item + [
            'description'             => 'Test položka',
            'quantity'                => 1,
            'unit'                    => 'ks',
            'unit_price_without_vat'  => 10000,
            'vat_rate_id'             => $this->vatRate21Id,
        ]]);
        return $id;
    }

    private function vendor(string $name, int $countryId, ?string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  is_vat_payer)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /** Historie plátcovství tenanta: od daného data neplátce (identifikovaná osoba). */
    private function forceTenantNonPayer(string $from): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, ?, 0, 1)'
        )->execute([$this->supplierId, $from]);
        $this->vatHistoryIds[] = (int) $pdo->lastInsertId();
    }

    private function countryId(string $iso2): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$iso2]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
