<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class EntityCategoryThresholdTransitionTest extends TestCase
{
    private const YEAR = 2025;

    private Connection $db;
    private PostingService $posting;
    private EntityCategoryService $categories;
    private AccountingSupplierSettingsRepository $settings;
    private AccountingPeriodRepository $periods;

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
            $this->settings   = $container->get(AccountingSupplierSettingsRepository::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
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
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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


    public function testDifferentRawCategoriesConfirmOnlyTheirCommonUpwardBoundary(): void
    {
        $this->frozen(2022, 'micro', 8000000, 16000000, 5);
        $this->frozen(2023, 'small', 30000000, 60000000, 15);
        $this->frozen(2024, 'medium', 150000000, 300000000, 60);
        $this->settings->upsert($this->supplierId, 0, null);
        $result = $this->categories->evaluate($this->supplierId, $this->periodId);
        self::assertSame('medium', $result['raw_previous']);
        self::assertSame('micro', $result['raw_current']);
        self::assertSame('small', $result['category']);
        self::assertSame('small', $this->categories->statementScope($this->supplierId, $this->periodId));
    }

    public function testDifferentRawCategoriesConfirmOnlyTheirCommonDownwardBoundary(): void
    {
        $this->frozen(2022, 'large', 700000000, 1400000000, 300);
        $this->frozen(2023, 'small', 30000000, 60000000, 15);
        $this->frozen(2024, 'micro', 8000000, 16000000, 5);
        $this->settings->upsert($this->supplierId, 0, null);
        $result = $this->categories->evaluate($this->supplierId, $this->periodId);
        self::assertSame('micro', $result['raw_previous']);
        self::assertSame('small', $result['category']);
        self::assertSame('small', $result['scope']);
    }

    public function testUnfrozenNativeLedgerUsesTheSameBoundaryTransition(): void
    {
        $this->frozen(2022, 'micro', 8000000, 16000000, 5);
        $this->frozen(2023, 'small', 30000000, 60000000, 15);
        $last = $this->periods->create($this->supplierId, 2024, '2024-01-01', '2024-12-31');
        $this->settings->upsert($this->supplierId, 60, null);
        $this->manual([
            self::l('311', 'debit', 300000000.00),
            self::l('602', 'credit', 300000000.00),
        ], '2024-06-15');
        $direct = $this->categories->evaluate($this->supplierId, $last);
        self::assertSame('medium', $direct['raw_current']);
        $result = $this->categories->evaluate($this->supplierId, $this->periodId);
        self::assertSame('medium', $result['raw_previous']);
        self::assertSame('small', $result['category']);
        $this->categories->freeze($this->supplierId, $last);
        self::assertSame($result, $this->categories->evaluate($this->supplierId, $this->periodId));
        $this->settings->upsert($this->supplierId, 60, 'full');
        self::assertSame('full', $this->categories->statementScope($this->supplierId, $this->periodId));
    }

    private function frozen(int $year, string $raw, float $assets, float $turnover, int $employees): void
    {
        $id = $this->periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        (new \MyInvoice\Repository\EntityCategoryHistoryRepository($this->db))->upsert(
            $this->supplierId, $id, $assets, $turnover, $employees, $raw,
        );
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
