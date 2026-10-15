<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Daňové termíny v dashboard "Akce pro tebe" respektují periodicitu DPH dodavatele
 * (supplier.vat_period + taxpayer_type). Regrese k issue #156:
 *   - Čtvrtletní plátce NESMÍ v půlce kvartálu dostat výzvu "DPH za uplynulý měsíc".
 *   - DPH za kvartál se ohlásí až po skončení kvartálu (Q2 → kolem 25. 7.).
 *   - KH se u právnické osoby (PO) podává VŽDY měsíčně → u čtvrtletní PO je KH
 *     samostatná měsíční položka oddělená od čtvrtletního DPH.
 *
 * Izolace: každý test používá vlastního prázdného dodavatele. userId = null →
 * žádné dismissals. Soft-skip bez DB.
 */
#[Group('integration')]
final class CrmTaxDeadlineTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($countryId === 0 || $vatRateId === 0 || $currencyId === 0) {
            $this->markTestSkipped('Chybí předpoklady (country/vat/currency).');
        }
        $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email, default_currency_id,
                 default_vat_rate_id, taxpayer_type, is_vat_payer, vat_period)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            '__CRM_TAX_DEADLINE_TEST__', 'Test 1', 'Praha', '11000', $countryId,
            'crm-action-deadline@example.invalid', $currencyId, $vatRateId, 'po', 'monthly',
        ]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->supplierId > 0) {
            $this->db->pdo()->prepare('DELETE FROM tax_submissions WHERE supplier_id = ?')->execute([$this->supplierId]);
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    /** Archivuje "podání" (tax_submissions) daného výkazu pro danou periodu. */
    private function markSubmitted(string $formCode, int $year, ?int $month, ?int $quarter): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter,
                 xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'submitted\', NOW())'
        )->execute([
            $this->supplierId, $formCode, $year, $month, $quarter,
            '<x/>', 4, str_repeat('0', 64),
        ]);
    }

    private function configure(string $vatPeriod, string $taxpayerType): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE supplier SET is_vat_payer = 1, vat_period = ?, taxpayer_type = ? WHERE id = ?'
        );
        $stmt->execute([$vatPeriod, $taxpayerType, $this->supplierId]);
    }

    /** @return array<string, array<string,mixed>> položky daňových termínů klíčované typem */
    private function taxItems(string $today): array
    {
        $res = $this->crm->actionItems($this->supplierId, null, new \DateTimeImmutable($today));
        $out = [];
        foreach ($res['items'] as $item) {
            if (in_array($item['type'], ['tax_deadline', 'kh_deadline'], true)) {
                $out[(string) $item['type']] = $item;
            }
        }
        return $out;
    }

    public function testMonthlyZobraziDphAKhZaMesic(): void
    {
        $this->configure('monthly', 'po');
        $items = $this->taxItems('2026-06-19');
        self::assertArrayHasKey('tax_deadline', $items, 'Měsíční plátce dostane výzvu DPH+KH.');
        self::assertSame('DPH + KH za uplynulý měsíc', $items['tax_deadline']['title']);
        self::assertSame('/reports/dph?year=2026&month=5&period=monthly', $items['tax_deadline']['link']);
        self::assertArrayNotHasKey('kh_deadline', $items, 'Měsíčně je KH sloučené s DPH, ne samostatně.');
    }

    public function testQuarterlyFoNezobraziVPuliKvartalu(): void
    {
        $this->configure('quarterly', 'fo');
        $items = $this->taxItems('2026-06-19');
        self::assertArrayNotHasKey('tax_deadline', $items, 'Čtvrtletní FO nemá v červnu výzvu k DPH.');
        self::assertArrayNotHasKey('kh_deadline', $items, 'Čtvrtletní FO nemá v červnu ani KH (kopíruje DPH periodu).');
    }

    public function testQuarterlyFoZobraziPoKonciKvartalu(): void
    {
        $this->configure('quarterly', 'fo');
        $items = $this->taxItems('2026-07-20'); // 5 dní do 25. 7.
        self::assertArrayHasKey('tax_deadline', $items, 'Čtvrtletní FO dostane výzvu po skončení Q2.');
        self::assertSame('DPH + KH za 2. čtvrtletí 2026', $items['tax_deadline']['title']);
        self::assertSame('/reports/dph?year=2026&month=4&period=quarterly', $items['tax_deadline']['link']);
        self::assertArrayNotHasKey('kh_deadline', $items, 'FO má KH sloučené s DPH (čtvrtletně).');
    }

    public function testQuarterlyPoMaKhMesicneOddeleneOdDph(): void
    {
        $this->configure('quarterly', 'po');

        // V půlce kvartálu: KH měsíčně ANO, DPH čtvrtletní NE.
        $june = $this->taxItems('2026-06-19');
        self::assertArrayHasKey('kh_deadline', $june, 'Čtvrtletní PO má KH každý měsíc.');
        self::assertSame('Kontrolní hlášení za uplynulý měsíc', $june['kh_deadline']['title']);
        self::assertArrayNotHasKey('tax_deadline', $june, 'DPH se u čtvrtletní PO v červnu nezobrazí.');

        // Po skončení kvartálu: obě položky (KH měsíční + DPH čtvrtletní).
        $july = $this->taxItems('2026-07-20');
        self::assertArrayHasKey('kh_deadline', $july, 'KH za červen.');
        self::assertArrayHasKey('tax_deadline', $july, 'DPH za Q2.');
        self::assertSame('DPH za 2. čtvrtletí 2026', $july['tax_deadline']['title']);
        self::assertSame('/reports/dph?year=2026&month=4&period=quarterly', $july['tax_deadline']['link']);
        self::assertSame('/reports/kh?year=2026&month=6&period=monthly', $july['kh_deadline']['link']);
    }

    public function testQuarterlyPoSkryjeUzPodaneKhIDph(): void
    {
        $this->configure('quarterly', 'po');

        // Podané KH za červen (měsíčně) → měsíční KH výzva zmizí, DPH za Q2 zůstává.
        $this->markSubmitted('dphkh1', 2026, 6, null);
        $afterKh = $this->taxItems('2026-07-20');
        self::assertArrayNotHasKey('kh_deadline', $afterKh, 'Podané KH už do Akcí pro tebe nepatří.');
        self::assertArrayHasKey('tax_deadline', $afterKh, 'DPH za Q2 zatím podané není — zůstává.');

        // Podané i DPH za Q2 (čtvrtletně) → zmizí obojí.
        $this->markSubmitted('dphdp3', 2026, null, 2);
        $afterBoth = $this->taxItems('2026-07-20');
        self::assertArrayNotHasKey('kh_deadline', $afterBoth);
        self::assertArrayNotHasKey('tax_deadline', $afterBoth, 'Po podání DPH zmizí i čtvrtletní výzva.');
    }

    public function testMonthlySloucenaVyzvaZmiziAzPoPodaniObou(): void
    {
        $this->configure('monthly', 'po');

        // Jen DPH podané, KH ne → sloučená výzva DPH+KH stále svítí (KH chybí).
        $this->markSubmitted('dphdp3', 2026, 5, null);
        $partial = $this->taxItems('2026-06-19');
        self::assertArrayHasKey('tax_deadline', $partial, 'Dokud chybí KH, sloučená výzva zůstává.');

        // Podané i KH → sloučená výzva zmizí.
        $this->markSubmitted('dphkh1', 2026, 5, null);
        $full = $this->taxItems('2026-06-19');
        self::assertArrayNotHasKey('tax_deadline', $full, 'Po podání DPH i KH sloučená výzva zmizí.');
    }
}
