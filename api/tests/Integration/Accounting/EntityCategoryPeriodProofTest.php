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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class EntityCategoryPeriodProofTest extends TestCase
{

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
    public function testNativeCategoryUsesAnnualTurnoverAndLegallyApplicablePeriodLimits(
        string $from, string $to, string $postingDate, float $turnover, float $expectedAnnualTurnover,
        float $expectedMicroAssetLimit, string $expectedRaw,
    ): void {
        $this->periodId = $this->periods->create($this->supplierId, (int) substr($to, 0, 4), $from, $to);
        $this->settings->upsert($this->supplierId, 11, null);
        $this->manual([
            self::l('221', 'debit', $turnover),
            self::l('602', 'credit', $turnover),
            self::l('518', 'debit', $turnover),
            self::l('221', 'credit', $turnover),
        ], $postingDate);
        $result = $this->categories->evaluate($this->supplierId, $this->periodId);
        self::assertSame(0, self::cents($result['criteria']['assets_net']));
        self::assertSame(11, $result['criteria']['employees']);
        self::assertSame(self::cents($turnover), self::cents($result['criteria']['net_turnover']));
        self::assertSame($expectedRaw, $result['raw_current'], 'Required annual turnover: ' . $expectedAnnualTurnover);
        self::assertSame(self::cents($expectedMicroAssetLimit), self::cents($result['thresholds']['micro']['assets_net']));
    }

    public static function periodCases(): array
    {
        return [
            'six months 2025:12M becomes24M' => ['2025-01-01','2025-06-30','2025-03-10',12000000,24000000,11000000,'small'],
            'eighteen months:30M becomes20M' => ['2024-07-01','2025-12-31','2025-03-10',30000000,20000000,11000000,'micro'],
            'FY starts2023:old limits mandatory' => ['2023-07-01','2024-06-30','2023-12-10',20000000,20000000,9000000,'small'],
            'calendar2023 old parity' => ['2023-01-01','2023-12-31','2023-06-10',20000000,20000000,9000000,'small'],
            'calendar2024 new parity' => ['2024-01-01','2024-12-31','2024-06-10',20000000,20000000,11000000,'micro'],
            'FY starts2024 new parity' => ['2024-07-01','2025-06-30','2025-03-10',20000000,20000000,11000000,'micro'],
        ];
    }

    #[DataProvider('periodCases')]
    public function testFreshFreezeAndUnfrozenHistoryAgreeWithoutChangingStoredPeriodFacts(
        string $from, string $to, string $postingDate, float $turnover, float $expectedAnnualTurnover,
        float $expectedMicroAssetLimit, string $expectedRaw,
    ): void {
        $this->testNativeCategoryUsesAnnualTurnoverAndLegallyApplicablePeriodLimits(
            $from, $to, $postingDate, $turnover, $expectedAnnualTurnover, $expectedMicroAssetLimit, $expectedRaw,
        );
        $nextStart = (new \DateTimeImmutable($to))->modify('+1 day');
        $nextEnd = $nextStart->modify('+1 year -1 day');
        $next = $this->periods->create(
            $this->supplierId, (int) substr($to, 0, 4) + 1, $nextStart->format('Y-m-d'), $nextEnd->format('Y-m-d'),
        );
        $before = $this->categories->evaluate($this->supplierId, $next);
        self::assertSame($expectedRaw, $before['raw_previous']);
        self::assertFalse($this->categories->isFrozen($this->supplierId, $this->periodId));
        $this->categories->freeze($this->supplierId, $this->periodId);
        self::assertTrue($this->categories->isFrozen($this->supplierId, $this->periodId));
        self::assertSame($before, $this->categories->evaluate($this->supplierId, $next));
        $stmt = $this->db->pdo()->prepare(
            'SELECT assets_net, net_turnover, avg_employees, raw_category FROM entity_category_history WHERE supplier_id = ? AND period_id = ?',
        );
        $stmt->execute([$this->supplierId, $this->periodId]);
        $frozen = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(0, self::cents($frozen['assets_net']));
        self::assertSame(self::cents($turnover), self::cents($frozen['net_turnover']));
        self::assertSame(11, (int) $frozen['avg_employees']);
        self::assertSame($expectedRaw, $frozen['raw_category']);
    }

    public function testExistingFrozenCategoryIsNotSilentlyReclassified(): void
    {
        $this->testNativeCategoryUsesAnnualTurnoverAndLegallyApplicablePeriodLimits(
            '2025-01-01', '2025-06-30', '2025-03-10', 12000000, 24000000, 11000000, 'small',
        );
        $history = new \MyInvoice\Repository\EntityCategoryHistoryRepository($this->db);
        $history->upsert($this->supplierId, $this->periodId, 0, 12000000, 11, 'micro');
        $next = $this->periods->create($this->supplierId, 2026, '2025-07-01', '2026-06-30');
        $this->categories->ensureFrozen($this->supplierId, $this->periodId);
        self::assertSame('micro', $this->categories->evaluate($this->supplierId, $next)['raw_previous']);
        self::assertSame('micro', $history->findRaw($this->supplierId, $this->periodId));
        self::assertSame('small', $this->categories->evaluate($this->supplierId, $this->periodId)['raw_current']);
    }

    public function testPartialFirstAndLastMonthsComeFromStoredPeriodDates(): void
    {
        $this->testNativeCategoryUsesAnnualTurnoverAndLegallyApplicablePeriodLimits(
            '2025-01-15', '2025-06-01', '2025-03-10', 12000000, 24000000, 11000000, 'small',
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
