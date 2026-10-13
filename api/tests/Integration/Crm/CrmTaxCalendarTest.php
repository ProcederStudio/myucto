<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CrmAggregationService::taxCalendarItems() — daňový kalendář widget (Fáze F,
 * audit 2026-07 P2/S): širší okno než action-items + stav "podáno" odvozený z
 * archivu tax_submissions (C7').
 *
 * Izolace: mění jen vat_period/taxpayer_type/is_vat_payer/flat_tax_band prvního
 * dodavatele, v tearDown vrací zpět; archivní řádky tax_submissions se mažou.
 * Soft-skip bez DB.
 */
#[Group('integration')]
final class CrmTaxCalendarTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    private int $origVatPayer = 0;
    private ?string $origTaxpayerType = null;
    private ?string $origVatPeriod = null;
    private ?string $origFlatTaxBand = null;
    /** @var list<int> */
    private array $createdSubmissions = [];
    private bool $inTx = false;

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
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $row = $pdo->query("SELECT is_vat_payer, taxpayer_type, vat_period, flat_tax_band FROM supplier WHERE id = {$this->supplierId}")
            ->fetch(\PDO::FETCH_ASSOC) ?: [];
        $this->origVatPayer = (int) ($row['is_vat_payer'] ?? 0);
        $this->origTaxpayerType = $row['taxpayer_type'] ?? null;
        $this->origVatPeriod = $row['vat_period'] ?? null;
        $this->origFlatTaxBand = $row['flat_tax_band'] ?? 'none';

        // Izolace v transakci (rollback v tearDown vrátí vše zpět). Uvnitř transakce
        // smažeme případné cizí/zbytkové archivy dodavatele, aby detekce "podáno"
        // (submittedAt) vycházela výhradně z řádků vytvořených tímto testem — jinak
        // committed archiv (např. dphkh1 za shodné období z jiného běhu) rozbije
        // asserty submitted=false.
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare('DELETE FROM tax_submissions WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function configure(string $vatPeriod, string $taxpayerType): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE supplier SET is_vat_payer = 1, vat_period = ?, taxpayer_type = ?, flat_tax_band = 'none' WHERE id = ?"
        );
        $stmt->execute([$vatPeriod, $taxpayerType, $this->supplierId]);
    }

    private function archiveSubmission(string $formCode, int $year, ?int $month, ?int $quarter, string $status = 'submitted', string $xml = '<xml/>'): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, period_quarter,
                                          xml_content, xml_size_bytes, xml_sha256, validation_status,
                                          status, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, SHA2(CONCAT(?, RAND()), 256), 'passed',
                     ?, IF(? IN ('submitted', 'accepted'), NOW(), NULL))"
        );
        $stmt->execute([$this->supplierId, $formCode, $year, $month, $quarter, $xml, strlen($xml), $formCode, $status, $status]);
        $this->createdSubmissions[] = (int) $this->db->pdo()->lastInsertId();
    }

    /** @param list<array<string,mixed>> $items */
    private function findByType(array $items, string $type): ?array
    {
        foreach ($items as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }
        return null;
    }

    public function testMonthlyPayerListsDphAndKhSeparatelyWithSubmittedFalse(): void
    {
        $this->configure('monthly', 'po');
        $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable('2026-06-19'), 45);

        $dph = $this->findByType($items, 'tax_deadline');
        $kh  = $this->findByType($items, 'kh_deadline');
        self::assertNotNull($dph);
        self::assertNotNull($kh);
        self::assertSame('2026-06-25', $dph['deadline']);
        self::assertSame('2026-06-25', $kh['deadline']);
        self::assertFalse($dph['submitted'], 'Bez archivovaného podání musí být submitted=false.');
        self::assertNull($dph['submitted_at']);
    }

    public function testArchivedSubmissionMarksItemAsSubmitted(): void
    {
        $this->configure('monthly', 'po');
        // DPH termín 2026-06-25 kryje období KVĚTEN 2026 (předchozí měsíc).
        $this->archiveSubmission('dphdp3', 2026, 5, null);

        $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable('2026-06-19'), 45);
        $dph = $this->findByType($items, 'tax_deadline');
        self::assertNotNull($dph);
        self::assertTrue($dph['submitted'], 'Archivované podání za odpovídající období musí nastavit submitted=true.');
        self::assertNotNull($dph['submitted_at']);

        // KH nemá vlastní archiv → zůstává nepodáno.
        $kh = $this->findByType($items, 'kh_deadline');
        self::assertNotNull($kh);
        self::assertFalse($kh['submitted']);
    }

    public function testGeneratedXmlWithoutFilingIsNotSubmitted(): void
    {
        $this->configure('quarterly', 'po');
        // Vygenerovaný a stažený XML za Q3 2026, na EPO ale neodešel.
        $this->archiveSubmission('dphdp3', 2026, null, 3, 'generated');
        $this->archiveSubmission('dphdp3', 2026, null, 3, 'downloaded');

        $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable('2026-10-13'), 45);
        $dph = $this->findByType($items, 'tax_deadline');
        self::assertNotNull($dph);
        self::assertSame('2026-10-26', $dph['deadline']);
        self::assertFalse($dph['submitted'], 'Vygenerovaný XML není podání.');
        self::assertNull($dph['submitted_at']);
    }

    public function testQuarterlyDeadlinePeriodMatchesArchivedQuarter(): void
    {
        $this->configure('quarterly', 'fo');
        // 2026-07-25 (Q2 2026) → period_year=2026, period_quarter=2.
        $this->archiveSubmission('dphdp3', 2026, null, 2);

        $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable('2026-07-20'), 45);
        $dph = $this->findByType($items, 'tax_deadline');
        self::assertNotNull($dph);
        // 25. 7. 2026 je sobota → posun na pondělí 27. 7. (§ 33/4 DŘ).
        self::assertSame('2026-07-27', $dph['deadline']);
        self::assertTrue($dph['submitted']);
    }

    public function testFlatTaxOsvcHasNoIncomeTaxDeadline(): void
    {
        $this->configure('monthly', 'fo');
        $this->db->pdo()->prepare("UPDATE supplier SET flat_tax_band = 'band1' WHERE id = ?")->execute([$this->supplierId]);

        $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable('2026-03-15'), 45);
        self::assertNull($this->findByType($items, 'income_tax_deadline'), 'OSVČ v paušálu nepodává DPFO.');
    }

    public function testNonFlatTaxFoGetsIncomeTaxDeadlineNearApril(): void
    {
        $this->configure('monthly', 'fo');
        $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable('2026-03-15'), 45);

        $incomeTaxItems = array_filter($items, static fn (array $i) => $i['type'] === 'income_tax_deadline');
        self::assertNotEmpty($incomeTaxItems, 'Standardní FO má vidět termín(y) DPFO v okně kolem 1.4./1.5.');
    }

    public function testItemsAreSortedByDeadlineAscending(): void
    {
        $this->configure('monthly', 'po');
        $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable('2026-06-19'), 90);
        $dates = array_column($items, 'deadline');
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates, 'Položky musí být seřazené dle termínu vzestupně.');
    }
}
