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
final class EntityCategoryTurnoverProofTest extends TestCase
{

    private Connection $db;
    private PostingService $posting;
    private EntityCategoryService $categories;
    private \MyInvoice\Service\Accounting\Reports\FinancialStatementService $statements;
    private AccountingSupplierSettingsRepository $settings;
    private AccountingPeriodRepository $periods;
    private \MyInvoice\Service\Accounting\Closing\ClosingService $closing;

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
            $this->settings   = $container->get(AccountingSupplierSettingsRepository::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $this->closing = $container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
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




    #[DataProvider('compositionCases')]
    public function testCategoryMatchesNativeStatementTurnoverComposition(int $year, string $code, bool $extra, float $expectedTurnover, string $expectedRaw): void
    {
        $from = $year . '-01-01';
        $to = $year . '-12-31';
        $this->periodId = $this->periods->create($this->supplierId, $year, $from, $to);
        $this->settings->upsert($this->supplierId, 11, null);
        if ($extra) {
            $this->settings->setNetTurnoverExtraRows($this->supplierId, [
                'income_statement' => ['III.', 'III.3.'],
                'income_statement_purpose' => ['II.'],
            ]);
        }
        $this->manual([
            self::l('221', 'debit', 30000000),
            self::l($code, 'credit', 30000000),
            self::l('518', 'debit', 30000000),
            self::l('221', 'credit', 30000000),
        ], $year . '-06-10');
        (new \MyInvoice\Repository\StatementDefinitionRepository($this->db))->setFunctionMapping($this->supplierId, '518', 'administration', $this->userId);
        $statements = $this->statements;
        $statement = $statements->incomeStatement($this->supplierId, $this->periodId, $to, 'full');
        $purpose = $statements->incomeStatementByFunction($this->supplierId, $this->periodId, $to, 'full');
        self::assertSame(self::cents($expectedTurnover), self::cents($statement['checks']['net_turnover']));
        self::assertSame(self::cents($expectedTurnover), self::cents($purpose['checks']['net_turnover']));
        $category = $this->categories->evaluate($this->supplierId, $this->periodId);
        self::assertSame(0, self::cents($category['criteria']['assets_net']));
        self::assertSame(11, $category['criteria']['employees']);
        self::assertSame($expectedRaw, $category['raw_current'], json_encode(['category_turnover' => $category['criteria']['net_turnover'], 'statement_turnover' => $statement['checks']['net_turnover'], 'purpose_turnover' => $purpose['checks']['net_turnover']], JSON_THROW_ON_ERROR));
        self::assertSame(self::cents($expectedTurnover), self::cents($category['criteria']['net_turnover']));
    }

    #[DataProvider('compositionCases')]
    public function testNativeTurnoverIsPreservedInDppoXml(int $year, string $code, bool $extra, float $expectedTurnover, string $expectedRaw): void
    {
        $this->periodId = $this->periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        if ($extra) {
            $this->settings->setNetTurnoverExtraRows($this->supplierId, [
                'income_statement' => ['III.', 'III.3.'],
                'income_statement_purpose' => ['II.'],
            ]);
        }
        $this->manual([self::l('221', 'debit', 30000000), self::l($code, 'credit', 30000000)], $year . '-06-10');
        $statement = $this->statements->incomeStatement($this->supplierId, $this->periodId, $year . '-12-31', 'full');
        self::assertSame(self::cents($expectedTurnover), self::cents($statement['checks']['net_turnover']));
        $result = (new \MyInvoice\Service\Tax\Return\DppoXmlBuilder())->build(
            ['company_name' => 'Syntetický test obratu', 'taxpayer_type' => 'po', 'country_iso2' => 'CZ', 'financial_office_code' => '451'],
            $year,
            (new \MyInvoice\Service\Tax\Return\DppoReturnCalculator())->compute(
                ['vh' => 30000000, 'depreciation' => ['tax' => 0, 'accounting' => 0]], [],
                \MyInvoice\Service\Tax\TaxConstants::forYear($year),
            ),
            [], ['income_statement' => $statement, 'settings' => ['avg_employees' => 0]],
        );
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($result['xml']));
        self::assertSame((string) (int) $expectedTurnover, $dom->getElementsByTagName('VetaS')->item(0)->getAttribute('kc_dpp_i1'));
    }

    public static function compositionCases(): array
    {
        return [
            '2023 all revenue648' => [2023, '648', false, 30000000, 'small'],
            '2025 selected business-model648' => [2025, '648', true, 30000000, 'small'],
            '2025 excluded non-business-model648 parity' => [2025, '648', false, 0, 'micro'],
            '2025 core602 parity' => [2025, '602', false, 30000000, 'small'],
        ];
    }

    public function testSignedDebitsRedStornoAndReversalUseTheSameMembership(): void
    {
        $this->prepareExtraPeriod();
        $this->manual([self::l('221', 'debit', 100.25), self::l('648', 'credit', 100.25)], '2025-03-01');
        $this->manual([self::l('648', 'debit', 20.10), self::l('221', 'credit', 20.10)], '2025-03-02');
        $this->manual([
            self::l('221', 'debit', 5.05) + ['is_red_storno' => true],
            self::l('648', 'credit', 5.05) + ['is_red_storno' => true],
        ], '2025-03-03');
        $entry = $this->manual([
            self::l('648', 'debit', 2.02) + ['is_red_storno' => true],
            self::l('221', 'credit', 2.02) + ['is_red_storno' => true],
        ], '2025-03-04');
        $this->assertAllTurnovers(77.12);
        $this->posting->reverse($this->supplierId, $entry, ['entry_date' => '2025-03-05', 'user_id' => $this->userId]);
        $this->assertAllTurnovers(75.10);
    }

    public function testEqualTotalsFromDifferentRevenueMembersAreAConflict(): void
    {
        $this->prepareExtraPeriod();
        $this->manual([self::l('221', 'debit', 100), self::l('648', 'credit', 100)], '2025-03-01');
        $this->manual([self::l('221', 'debit', 100), self::l('662', 'credit', 100)], '2025-03-01');
        $definitions = new \MyInvoice\Repository\StatementDefinitionRepository($this->db);
        $version = $definitions->findVersion('income_statement_purpose', '2025-12-31');
        $map = $definitions->accountMap((int) $version['id']);
        $interestRow = array_values(array_filter($map, static fn(array $row): bool => $row['account_prefix'] === '662'))[0]['row_code'];
        $this->settings->setNetTurnoverExtraRows($this->supplierId, [
            'income_statement' => ['III.3.'], 'income_statement_purpose' => [$interestRow],
        ]);
        self::assertSame(10000, self::cents($this->statements->incomeStatement($this->supplierId, $this->periodId, '2025-12-31', 'full')['checks']['net_turnover']));
        self::assertSame(10000, self::cents($this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, '2025-12-31', 'full')['checks']['net_turnover']));
        try {
            $this->categories->evaluate($this->supplierId, $this->periodId);
            self::fail('Different source members must not be treated as the same turnover.');
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) {
            self::assertSame('net_turnover_membership_conflict', $e->errorCode);
            self::assertStringContainsString('Čistý obrat: výnosy obchodního modelu', $e->getMessage());
            self::assertStringContainsString('business model revenue', \MyInvoice\I18n\ErrorCatalog::lookup($e->getMessage(), 'en'));
        }
    }

    public function testAutoScopeRejectsConflictWhileFullStatementRetainsItsAmount(): void
    {
        $this->prepareExtraPeriod();
        $this->manual([self::l('221', 'debit', 100), self::l('648', 'credit', 100)], '2025-03-01');
        $this->settings->setNetTurnoverExtraRows($this->supplierId, ['income_statement' => ['III.3.'], 'income_statement_purpose' => []]);
        self::assertSame(10000, self::cents($this->statements->incomeStatement($this->supplierId, $this->periodId, '2025-12-31', 'full')['checks']['net_turnover']));
        self::assertSame(0, self::cents($this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, '2025-12-31', 'full')['checks']['net_turnover']));
        $this->expectException(\MyInvoice\Service\Accounting\Reports\ReportException::class);
        $this->expectExceptionMessage('Čistý obrat: výnosy obchodního modelu');
        $this->statements->incomeStatement($this->supplierId, $this->periodId, '2025-12-31', 'auto');
    }

    public function testAnotherTenantRevenueAndChoicesCannotChangeTheProjection(): void
    {
        $this->prepareExtraPeriod();
        $this->manual([self::l('221', 'debit', 100), self::l('648', 'credit', 100)], '2025-03-01');
        $insert = $this->db->pdo()->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id) SELECT ?, street, city, zip, country_id, ?, default_currency_id, default_vat_rate_id FROM supplier WHERE id = ?');
        $insert->execute(['Another synthetic company', 'another@example.com', $this->supplierId]);
        $other = (int) $this->db->pdo()->lastInsertId();
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($other);
        $otherPeriod = $this->periods->create($other, 2025, '2025-01-01', '2025-12-31');
        $this->settings->setNetTurnoverExtraRows($other, ['income_statement' => [], 'income_statement_purpose' => []]);
        $this->posting->postDocument($other, 'manual', null,
            [self::l('221', 'debit', 999999), self::l('648', 'credit', 999999)],
            ['entry_date' => '2025-03-01', 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
        $this->assertAllTurnovers(100);
        self::assertSame(0, self::cents($this->categories->evaluate($other, $otherPeriod)['criteria']['net_turnover']));
    }

    public function testUnmappedRevenueIsNotSilentlyClassifiedAsZero(): void
    {
        $this->prepareExtraPeriod();
        $this->db->pdo()->prepare('INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic) VALUES (?, ?, ?, "revenue", "credit", 1)')
            ->execute([$this->supplierId, '699', 'Unmapped synthetic revenue']);
        $this->manual([self::l('221', 'debit', 100), self::l('699', 'credit', 100)], '2025-03-01');
        $this->expectException(\MyInvoice\Service\Accounting\Reports\ReportException::class);
        $this->categories->evaluate($this->supplierId, $this->periodId);
    }

    public function testDatedAnalyticMappingsUseTheSameActualMembers(): void
    {
        $this->prepareExtraPeriod();
        $this->settings->setNetTurnoverExtraRows($this->supplierId, ['income_statement' => [], 'income_statement_purpose' => []]);
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$this->supplierId, '648']);
        $id = (int) $parent->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id) VALUES (?, ?, ?, "revenue", "credit", 0, ?)');
        foreach (['648100', '648200'] as $code) {
            $insert->execute([$this->supplierId, $code, 'Synthetic revenue ' . $code, $id]);
        }
        $definitions = new \MyInvoice\Repository\StatementDefinitionRepository($this->db);
        $overrides = new \MyInvoice\Repository\StatementOverrideRepository($this->db);
        foreach (['income_statement', 'income_statement_purpose'] as $type) {
            $version = $definitions->findVersion($type, '2025-12-31');
            $overrides->create($this->supplierId, (int) $version['id'], [
                'account_prefix' => '648100', 'row_code' => 'I.', 'target' => 'gross', 'balance_condition' => 'any',
                'sign' => 1, 'note' => 'Synthetic business-model revenue', 'valid_from_year' => 2025, 'valid_to_year' => 2025,
            ], $this->userId);
        }
        $this->manual([self::l('221', 'debit', 100), self::l('648100', 'credit', 100)], '2025-03-01');
        $this->manual([self::l('221', 'debit', 200), self::l('648200', 'credit', 200)], '2025-03-01');
        $this->assertAllTurnovers(100);
        $this->periodId = $this->periods->create($this->supplierId, 2024, '2024-01-01', '2024-12-31');
        $this->manual([self::l('221', 'debit', 300), self::l('648100', 'credit', 300)], '2024-03-01');
        $this->assertAllTurnovers(0, '2024-12-31');
    }

    public function testFiscalBoundaryAndTenantDoNotLeakRevenue(): void
    {
        $this->prepareExtraPeriod('2023-07-01', '2024-06-30', 2024);
        $this->settings->setNetTurnoverExtraRows($this->supplierId, ['income_statement' => [], 'income_statement_purpose' => []]);
        $this->periods->create($this->supplierId, 2023, '2022-07-01', '2023-06-30');
        $this->periods->create($this->supplierId, 2025, '2024-07-01', '2025-06-30');
        $this->manual([self::l('221', 'debit', 11), self::l('648', 'credit', 11)], '2023-06-30');
        $this->manual([self::l('221', 'debit', 100), self::l('648', 'credit', 100)], '2023-07-01');
        $this->manual([self::l('221', 'debit', 200), self::l('648', 'credit', 200)], '2024-06-30');
        $this->manual([self::l('221', 'debit', 13), self::l('648', 'credit', 13)], '2024-07-01');
        $this->assertAllTurnovers(300, '2024-06-30');
        $this->expectException(\MyInvoice\Service\Accounting\Reports\ReportException::class);
        $this->categories->evaluate($this->supplierId + 1000000, $this->periodId);
    }

    public function testClosingOpeningAndFrozenHistoryRetainOriginalTurnover(): void
    {
        $this->prepareExtraPeriod();
        $this->manual([self::l('221', 'debit', 100.25), self::l('648', 'credit', 100.25)], '2025-03-01');
        $this->assertAllTurnovers(100.25);
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $meta = ['user_id' => $this->userId, 'posted_by' => $this->userId];
        $rv = fn(): int => (int) $this->periods->findById($sid, $pid)['row_version'];
        $this->closing->start($sid, $pid, $rv(), $meta);
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $row) {
            $items[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $rv(), ['complete' => true], $items, $meta);
        $this->closing->runPrecheck($sid, $pid, $rv(), $meta);
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $rv(), $meta);
        $this->closing->runFxRevaluation($sid, $pid, [], $rv(), $meta);
        foreach (['estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $this->closing->confirmStep($sid, $pid, $step, 'skipped', null, $rv(), $meta);
        }
        $this->closing->closeBooks($sid, $pid, $rv(), $meta);
        $this->assertAllTurnovers(100.25);
        $frozen = $this->db->pdo()->prepare('SELECT * FROM entity_category_history WHERE supplier_id = ? AND period_id = ?');
        $frozen->execute([$sid, $pid]);
        $before = $frozen->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(10025, self::cents($before['net_turnover']));
        $opened = $this->closing->openNext($sid, $pid, $rv(), $meta);
        $this->periodId = (int) $opened['next_period_id'];
        $this->assertAllTurnovers(0, '2026-12-31');
        $this->settings->setNetTurnoverExtraRows($sid, ['income_statement' => [], 'income_statement_purpose' => []]);
        $this->categories->evaluate($sid, $this->periodId);
        $frozen->execute([$sid, $pid]);
        self::assertSame($before, $frozen->fetch(\PDO::FETCH_ASSOC));
    }

    private function prepareExtraPeriod(string $from = '2025-01-01', string $to = '2025-12-31', int $year = 2025): void
    {
        $this->periodId = $this->periods->create($this->supplierId, $year, $from, $to);
        $this->settings->setNetTurnoverExtraRows($this->supplierId, [
            'income_statement' => ['III.', 'III.3.'], 'income_statement_purpose' => ['II.'],
        ]);
    }

    private function assertAllTurnovers(float $expected, string $asOf = '2025-12-31'): void
    {
        foreach ([
            $this->categories->evaluate($this->supplierId, $this->periodId)['criteria']['net_turnover'],
            $this->statements->incomeStatement($this->supplierId, $this->periodId, $asOf, 'full')['checks']['net_turnover'],
            $this->statements->incomeStatementByFunction($this->supplierId, $this->periodId, $asOf, 'full')['checks']['net_turnover'],
        ] as $amount) {
            self::assertSame(self::cents($expected), self::cents($amount));
        }
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
