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

    public function testSubmittedVatReturnShowsPaymentUntilBankStatementCoversIt(): void
    {
        $this->configure('quarterly', 'po');
        $xml = '<Pisemnost><DPHDP3><VetaD dapdph_forma="B" rok="2026" ctvrt="3"/>'
            . '<VetaP c_ufo="454" dic="24681357"/>'
            . '<Veta6 dan_zocelk="1500" odp_zocelk="500" dano_da="1000"/></DPHDP3></Pisemnost>';
        $this->archiveSubmission('dphdp3', 2026, null, 3, 'submitted', $xml);
        $now = new \DateTimeImmutable('2026-10-13');

        $dph = $this->findByType($this->crm->taxCalendarItems($this->supplierId, $now, 45), 'tax_deadline');
        self::assertNotNull($dph);
        self::assertSame('/reports/submissions?submission_id=' . $dph['payment']['submission_id'], $dph['link']);
        self::assertSame(1000.0, $dph['payment']['payable']);
        self::assertSame('705-77627311/0710', $dph['payment']['account']);
        self::assertSame('24681357', $dph['payment']['variable_symbol']);
        self::assertSame('2026-10-26', $dph['payment']['due_date']);
        self::assertFalse($dph['payment']['paid']);

        $items = $this->crm->vatPaymentItems($this->supplierId, $now);
        self::assertCount(1, $items);
        self::assertSame('vat_payment', $items[0]['type']);
        self::assertStringContainsString('705-77627311/0710', $items[0]['hint']);

        // Úhrada na účet jiné daně (713) se stejným VS DPH nezaplatí.
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, '__test_vat_payment.gpc', SHA2(RAND(), 256), '1000000005', '0100', 'CZK', '2026-10-20')"
        )->execute([$this->supplierId]);
        $statementId = (int) $pdo->lastInsertId();
        $tx = $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, counterparty_account, counterparty_bank, variable_symbol)
             VALUES (?, '2026-10-20', ?, 'CZK', ?, '0710', '24681357')"
        );
        $tx->execute([$statementId, -1000, '713-77627311']);
        self::assertCount(1, $this->crm->vatPaymentItems($this->supplierId, $now));

        $tx->execute([$statementId, -1000, '0007050077627311']);
        self::assertSame([], $this->crm->vatPaymentItems($this->supplierId, $now));
        $dph = $this->findByType($this->crm->taxCalendarItems($this->supplierId, $now, 45), 'tax_deadline');
        self::assertTrue($dph['payment']['paid']);
        self::assertSame('2026-10-20', $dph['payment']['paid_at']);
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
    public function testCalendarLinksAndPeriodsMatchMonthlyAndQuarterlyObligations(): void
    {
        foreach ([
            ['monthly', 'po', '2026-10-15', ['year' => 2026, 'month' => 9, 'quarter' => null], ['year' => 2026, 'month' => 9, 'quarter' => null]],
            ['monthly', 'fo', '2026-01-15', ['year' => 2025, 'month' => 12, 'quarter' => null], ['year' => 2025, 'month' => 12, 'quarter' => null]],
            ['quarterly', 'po', '2026-10-15', ['year' => 2026, 'month' => null, 'quarter' => 3], ['year' => 2026, 'month' => 9, 'quarter' => null]],
            ['quarterly', 'fo', '2026-10-15', ['year' => 2026, 'month' => null, 'quarter' => 3], ['year' => 2026, 'month' => null, 'quarter' => 3]],
            ['quarterly', 'po', '2026-01-15', ['year' => 2025, 'month' => null, 'quarter' => 4], ['year' => 2025, 'month' => 12, 'quarter' => null]],
        ] as [$frequency, $taxpayer, $today, $dphPeriod, $khPeriod]) {
            $this->configure($frequency, $taxpayer);
            $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable($today), 45);
            foreach (['tax_deadline' => ['dph', $dphPeriod], 'kh_deadline' => ['kh', $khPeriod]] as $type => [$report, $period]) {
                $item = $this->findByType($items, $type);
                self::assertNotNull($item);
                self::assertSame($period, $item['period'] ?? null, "$frequency $taxpayer $today $report");
                $month = $period['month'] ?? (($period['quarter'] - 1) * 3 + 1);
                $mode = $period['quarter'] === null ? 'monthly' : 'quarterly';
                self::assertSame("/reports/$report?year={$period['year']}&month=$month&period=$mode", $item['link']);
            }
        }
    }

    public function testIncomeTaxLinksTargetPreviousTaxYear(): void
    {
        foreach (['po', 'fo'] as $taxpayer) {
            $this->configure('monthly', $taxpayer);
            $items = $this->crm->taxCalendarItems($this->supplierId, new \DateTimeImmutable('2026-03-15'), 90);
            $income = array_filter($items, static fn (array $item): bool => $item['type'] === 'income_tax_deadline');
            self::assertCount(2, $income);
            foreach ($income as $item) {
                self::assertSame('/reports/income-tax?year=2025', $item['link']);
                self::assertSame(['year' => 2025, 'month' => null, 'quarter' => null], $item['period'] ?? null);
            }
        }
    }

    public function testShLinksKeepMonthlyPeriodForQuarterlyPayerInCalendarAndActions(): void
    {
        $this->configure('quarterly', 'po');
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'DE'")->fetchColumn();
        $currency = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email, currency_default_id)
             VALUES (?, '__SH_PERIOD_TEST__', 'Test 1', 'Berlin', '10000', ?, 'DE999999999', 'sh@example.invalid', ?)"
        )->execute([$this->supplierId, $country, $currency]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, client_id, issue_date, tax_date, due_date, currency_id, status, created_by)
             VALUES (?, ?, '2025-12-10', '2025-12-10', '2025-12-24', ?, 'issued', ?)"
        )->execute([$this->supplierId, $client, $currency, $user]);
        $invoice = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoice_items (invoice_id, description, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                        total_without_vat, total_vat, total_with_vat, vat_classification_code)
             VALUES (?, 'Test service', 1000, ?, 0, 1000, 0, 1000, '22')"
        )->execute([$invoice, $vat]);
        $now = new \DateTimeImmutable('2026-01-20');
        $sh = $this->findByType($this->crm->taxCalendarItems($this->supplierId, $now, 45), 'shv_deadline');
        self::assertNotNull($sh);
        self::assertSame('/reports/shv?year=2025&month=12&period=monthly', $sh['link']);
        self::assertSame(['year' => 2025, 'month' => 12, 'quarter' => null], $sh['period'] ?? null);
        $action = $this->findByType($this->crm->actionItems($this->supplierId, null, $now)['items'], 'shv_deadline');
        self::assertNotNull($action);
        self::assertSame($sh['link'], $action['link']);
    }

}
