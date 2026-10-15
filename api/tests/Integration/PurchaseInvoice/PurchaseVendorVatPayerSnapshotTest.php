<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot plátcovství dodavatele NA přijaté faktuře (migrace 0133).
 *
 * Motivace (bug): plátcovství je časově závislé. Dřív se četl jen ŽIVÝ globální příznak
 * `clients.is_vat_payer`, který se navíc při otevření/uložení faktury přepisoval dnešním
 * stavem z ARES/VIES. U historické faktury, kde dodavatel v době plnění plátce BYL, ale dnes
 * už není, se příznak „odškrtl" → riziko ztráty nároku na odpočet.
 *
 * Fix: doklad si drží vlastní `vendor_is_vat_payer` snapshot; čte se ze snapshotu, u legacy
 * dokladů (NULL) fallback na živý flag klienta. Tady to ověřujeme přes společný choke-point
 * PurchaseInvoiceRepository::createDraft()/updateDraft()/find().
 *
 * Klíčový scénář uživatele: rok starou fakturu jde označit za „dodavatel plátce" i když je
 * dnes dodavatel neplátce (živý flag = 0), a hodnota se drží.
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseVendorVatPayerSnapshotTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

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
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /**
     * Jádro scénáře uživatele: dodavatel je DNES neplátce (živý flag 0), ale historickou
     * fakturu založíme s vendor_is_vat_payer=true → snapshot drží true nezávisle na živém flagu.
     */
    public function testSnapshotFrozenAtCreateIndependentOfLiveFlag(): void
    {
        $vendor = $this->vendor('Dodavatel dnes neplátce', 'CZ29000001', isVatPayer: false);

        $id = $this->repo->createDraft(
            $this->payload($vendor, 'PVVP-1') + ['vendor_is_vat_payer' => true],
            $this->userId,
            $this->supplierId,
        );
        $this->piIds[] = $id;

        $inv = $this->repo->find($id, $this->supplierId);
        self::assertTrue($inv['vendor_is_vat_payer'],
            'snapshot=true se musí držet i když je živý flag klienta 0 (dodavatel byl plátce v době plnění)');
    }

    /** Bez explicitního snapshotu se při create zmrazí AKTUÁLNÍ živý flag klienta. */
    public function testCreateDefaultsToLiveFlag(): void
    {
        $payer    = $this->vendor('Dodavatel plátce', 'CZ29000002', isVatPayer: true);
        $nonPayer = $this->vendor('Dodavatel neplátce', 'CZ29000003', isVatPayer: false);

        $idPayer = $this->repo->createDraft($this->payload($payer, 'PVVP-2a'), $this->userId, $this->supplierId);
        $this->piIds[] = $idPayer;
        $idNon = $this->repo->createDraft($this->payload($nonPayer, 'PVVP-2b'), $this->userId, $this->supplierId);
        $this->piIds[] = $idNon;

        self::assertTrue($this->repo->find($idPayer, $this->supplierId)['vendor_is_vat_payer']);
        self::assertFalse($this->repo->find($idNon, $this->supplierId)['vendor_is_vat_payer']);
    }

    /** Update BEZ klíče vendor_is_vat_payer nesmí zmrazený snapshot přepsat. */
    public function testUpdateWithoutKeyPreservesSnapshot(): void
    {
        $vendor = $this->vendor('Dodavatel neplátce dnes', 'CZ29000004', isVatPayer: false);

        $id = $this->repo->createDraft(
            $this->payload($vendor, 'PVVP-3') + ['vendor_is_vat_payer' => true],
            $this->userId,
            $this->supplierId,
        );
        $this->piIds[] = $id;

        // Update běžnou cestou bez snapshotu (interní update path) — musí zůstat true.
        $this->repo->updateDraft($id, $this->payload($vendor, 'PVVP-3'), $this->supplierId);
        self::assertTrue($this->repo->find($id, $this->supplierId)['vendor_is_vat_payer'],
            'update bez vendor_is_vat_payer nesmí přepsat zmrazený snapshot');
    }

    /** Update S klíčem snapshot přepíše (ruční změna checkboxu v editoru). */
    public function testUpdateWithKeyOverwritesSnapshot(): void
    {
        $vendor = $this->vendor('Dodavatel k překlopení', 'CZ29000005', isVatPayer: true);

        $id = $this->repo->createDraft(
            $this->payload($vendor, 'PVVP-4') + ['vendor_is_vat_payer' => true],
            $this->userId,
            $this->supplierId,
        );
        $this->piIds[] = $id;

        $this->repo->updateDraft(
            $id,
            $this->payload($vendor, 'PVVP-4') + ['vendor_is_vat_payer' => false],
            $this->supplierId,
        );
        self::assertFalse($this->repo->find($id, $this->supplierId)['vendor_is_vat_payer'],
            'update s vendor_is_vat_payer=false musí snapshot překlopit na neplátce');
    }

    /** Legacy doklad (snapshot NULL) fallbackuje na živý flag klienta. */
    public function testLegacyNullFallsBackToLiveFlag(): void
    {
        $vendor = $this->vendor('Dodavatel legacy', 'CZ29000006', isVatPayer: true);

        $id = $this->repo->createDraft($this->payload($vendor, 'PVVP-5'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        // Simuluj legacy řádek (před migrací 0133 / před backfillem).
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET vendor_is_vat_payer = NULL WHERE id = ?')->execute([$id]);

        $this->setLiveFlag($vendor, true);
        self::assertTrue($this->repo->find($id, $this->supplierId)['vendor_is_vat_payer'],
            'legacy NULL + živý flag=1 → true');

        $this->setLiveFlag($vendor, false);
        self::assertFalse($this->repo->find($id, $this->supplierId)['vendor_is_vat_payer'],
            'legacy NULL + živý flag=0 → false');
    }

    /**
     * Import bez režimu odpočtu (ISDOC, Pohoda XML) od neplátce → bez nároku. Reverse
     * charge, plátce a výslovná volba volajícího zůstávají beze změny.
     */
    public function testNonPayerWithoutDeductionDefaultsToNone(): void
    {
        $nonPayer = $this->vendor('Dodavatel neplátce import', 'CZ29000007', isVatPayer: false);
        $payer    = $this->vendor('Dodavatel plátce import', 'CZ29000008', isVatPayer: true);

        $cases = [
            'PVVP-6a' => [$nonPayer, [], 'none'],
            'PVVP-6b' => [$nonPayer, ['reverse_charge' => true], 'full'],
            'PVVP-6c' => [$nonPayer, ['vat_deduction' => 'full'], 'full'],
            'PVVP-6d' => [$payer, [], 'full'],
            'PVVP-6e' => [$payer, ['vendor_is_vat_payer' => false], 'none'],
        ];
        foreach ($cases as $number => [$vendor, $extra, $expected]) {
            $id = $this->repo->createDraft($this->payload($vendor, $number) + $extra, $this->userId, $this->supplierId);
            $this->piIds[] = $id;
            self::assertSame($expected, $this->repo->find($id, $this->supplierId)['vat_deduction'], $number);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function setLiveFlag(int $vendorId, bool $isPayer): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET is_vat_payer = ? WHERE id = ?')
            ->execute([$isPayer ? 1 : 0, $vendorId]);
    }

    private function vendor(string $name, string $dic, bool $isVatPayer): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  is_vat_payer)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId, $isVatPayer ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /** Minimální validní payload pro createDraft (povinné: vendor_invoice_number, datumy, currency). */
    private function payload(int $vendorId, string $number): array
    {
        return [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => 'invoice',
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
        ];
    }
}
