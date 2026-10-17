<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DppoTurnoverAnnualizationTest extends TestCase
{

    private Connection $db;
    private PostingService $posting;
    private EntityCategoryService $categories;
    private \MyInvoice\Service\Accounting\Reports\FinancialStatementService $statements;
    private AccountingPeriodRepository $periods;
    private \MyInvoice\Service\Tax\Return\TaxReturnService $returns;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            self::fail('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db         = $container->get(Connection::class);
            $this->posting    = $container->get(PostingService::class);
            $this->categories = $container->get(EntityCategoryService::class);
            $this->statements = $container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $this->returns = $container->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            self::fail('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            self::fail('Chybí základní data (supplier/user) v DB.');
        }
        $hasSeed = (int) $pdo->query(
            "SELECT COUNT(*) FROM statement_versions WHERE version_code = 'vyhl500-2002/2024'"
        )->fetchColumn();
        if ($hasSeed < 2) {
            self::fail('Seed výkazů 1012 není aplikovaný (statement_versions).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolovaný supplier (kopie FK hodnot z prvního): kumulativní PS rozvahových
        // účtů (R6) jde přes celou historii deníku, sdílený dev supplier s reálnými
        // zápisy by rozbil bilanční asserty a previousPeriod.
        $isoStmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $isoStmt->execute(['Izolovaný test s.r.o.', 'izolace@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
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
    }





    #[DataProvider('periodCases')]
    public function testNativeStatementPeriodIsAnnualizedOnlyAtXmlBoundary(string $from, string $to, float $amount, string $expected): void
    {
        $year = (int) substr($to, 0, 4);
        $this->periodId = $this->periods->create($this->supplierId, $year, $from, $to);
        $this->manual([self::l('221', 'debit', $amount), self::l('602', 'credit', $amount)], $from);
        $statement = $this->statements->incomeStatement($this->supplierId, $this->periodId, $to, 'full');
        self::assertSame(self::cents($amount), self::cents($statement['checks']['net_turnover']));
        self::assertSame(self::cents($amount), self::cents($this->categories->evaluate($this->supplierId, $this->periodId)['criteria']['net_turnover']));
        $meta = [
            'zdobd_od' => (new \DateTimeImmutable($from))->format('d.m.Y'),
            'zdobd_do' => (new \DateTimeImmutable($to))->format('d.m.Y'),
        ];
        $result = (new \MyInvoice\Service\Tax\Return\DppoXmlBuilder())->build(
            ['company_name' => 'Syntetický roční obrat', 'taxpayer_type' => 'po', 'country_iso2' => 'CZ', 'financial_office_code' => '451'],
            $year,
            (new \MyInvoice\Service\Tax\Return\DppoReturnCalculator())->compute(
                ['vh' => $amount, 'depreciation' => ['tax' => 0, 'accounting' => 0]], [],
                \MyInvoice\Service\Tax\TaxConstants::forYear($year),
            ),
            $meta, ['income_statement' => $statement, 'settings' => ['avg_employees' => 0]],
        );
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($result['xml']));
        $vetaD = $dom->getElementsByTagName('VetaD')->item(0);
        self::assertSame($meta['zdobd_od'], $vetaD->getAttribute('zdobd_od'));
        self::assertSame($meta['zdobd_do'], $vetaD->getAttribute('zdobd_do'));
        self::assertSame($expected, $dom->getElementsByTagName('VetaS')->item(0)->getAttribute('kc_dpp_i1'));
        self::assertSame(self::cents($amount), self::cents($statement['checks']['net_turnover']));
    }

    public static function periodCases(): array
    {
        return [
            'six months' => ['2025-01-01', '2025-06-30', 100.25, '201'],
            'eighteen months' => ['2024-07-01', '2025-12-31', 100.25, '67'],
            'crossed year six months' => ['2024-10-01', '2025-03-31', 100.25, '201'],
            'calendar twelve months parity' => ['2025-01-01', '2025-12-31', 100.25, '100'],
            'fiscal twelve months parity' => ['2024-07-01', '2025-06-30', 100.50, '101'],
            'commenced partial leap months' => ['2024-02-29', '2024-03-01', 100.25, '602'],
        ];
    }

    #[DataProvider('periodCases')]
    public function testNativeTaxReturnServiceUsesAccountingPeriod(string $from, string $to, float $amount, string $expected): void
    {
        $year = (int) substr($to, 0, 4);
        $this->periodId = $this->periods->create($this->supplierId, $year, $from, $to);
        $this->db->pdo()->prepare('UPDATE supplier SET taxpayer_type = "po", financial_office_code = "451", ic = "12345678", dic = "CZ12345678" WHERE id = ?')->execute([$this->supplierId]);
        $this->manual([self::l('221', 'debit', $amount), self::l('602', 'credit', $amount)], $from);
        $result = $this->returns->buildXml($this->supplierId, $year, 'po');
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($result['xml']));
        self::assertSame((new \DateTimeImmutable($from))->format('d.m.Y'), $dom->getElementsByTagName('VetaD')->item(0)->getAttribute('zdobd_od'));
        self::assertSame((new \DateTimeImmutable($to))->format('d.m.Y'), $dom->getElementsByTagName('VetaD')->item(0)->getAttribute('zdobd_do'));
        self::assertSame($expected, $dom->getElementsByTagName('VetaS')->item(0)->getAttribute('kc_dpp_i1'), implode("\n", $result['warnings']));
    }

    public function testCurrentWorkingReturnDoesNotPretendToContainACompleteYear(): void
    {
        $year = (int) date('Y') + 1;
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = $year;
        $this->db->pdo()->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute([$year, json_encode($constants, JSON_THROW_ON_ERROR)]);
        $from = date('Y') . '-01-01';
        $to = $year . '-12-31';
        $this->periodId = $this->periods->create($this->supplierId, $year, $from, $to);
        $this->db->pdo()->prepare('UPDATE supplier SET taxpayer_type = "po", financial_office_code = "451" WHERE id = ?')->execute([$this->supplierId]);
        $this->manual([self::l('221', 'debit', 100.25), self::l('602', 'credit', 100.25)], $from);
        $statement = $this->statements->incomeStatement($this->supplierId, $this->periodId, null, 'full');
        self::assertSame(date('Y-m-d'), $statement['as_of']);
        self::assertSame(10025, self::cents($statement['checks']['net_turnover']));
        $result = $this->returns->buildXml($this->supplierId, $year, 'po');
        self::assertStringNotContainsString('kc_dpp_i1=', $result['xml']);
        self::assertStringContainsString('výsledovka není sestavena ke konci účetního období', implode("\n", $result['warnings']));
    }

    private function manual(array $lines, string $date, array $meta = []): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            array_merge(['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId], $meta),
        );
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
