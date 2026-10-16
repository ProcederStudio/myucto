<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\CostCenterAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileException;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class DimensionValueClosedHistoryTest extends TestCase
{
    private const YEAR = 2093;
    private const FROM = '2093-01-01';
    private const TO = '2093-12-31';

    private Connection $db;
    private DimensionService $dimensions;
    private DimensionRepository $values;
    private DimensionProfitService $profit;
    private FinancialStatementService $statements;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private CostCenterRepository $centers;
    private ChartOfAccountsSeeder $seeder;
    private int $supplier;
    private int $user;
    private array $periodIds = [];
    private CompanyProfileImporter $profiles;
    private CostCenterAction $centerAction;
    private ?Connection $otherDb = null;
    private ?int $snapshotIsolation = null;
    private bool $committed = false;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->dimensions = $container->get(DimensionService::class);
        $this->values = $container->get(DimensionRepository::class);
        $this->profit = $container->get(DimensionProfitService::class);
        $this->statements = $container->get(FinancialStatementService::class);
        $this->posting = $container->get(PostingService::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);
        $this->centers = $container->get(CostCenterRepository::class);
        $this->seeder = $container->get(ChartOfAccountsSeeder::class);
        $this->profiles = $container->get(CompanyProfileImporter::class);
        $this->centerAction = $container->get(CostCenterAction::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->user);
        $pdo->beginTransaction();
        $this->supplier = $this->company();
    }

    protected function tearDown(): void
    {
        if ($this->otherDb !== null) {
            if ($this->otherDb->pdo()->inTransaction()) {
                $this->otherDb->pdo()->rollBack();
            }
            $this->otherDb->close();
        }
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            if ($this->snapshotIsolation !== null) {
                $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotIsolation);
            }
            if ($this->committed) {
                $pdo = $this->db->pdo();
                foreach (array_keys($this->periodIds) as $supplier) {
                    $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplier]);
                    $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$supplier]);
                    $pdo->prepare('UPDATE dimension_values SET parent_id = NULL WHERE supplier_id = ?')->execute([$supplier]);
                    $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplier]);
                }
            }
            $this->db->close();
        }
    }

    public static function branchCases(): iterable
    {
        yield 'explicit service closed' => [false, false, false, 'closed'];
        yield 'split repository reviewed' => [true, true, false, 'reviewed'];
        yield 'global B used A edits approved' => [false, false, true, 'approved'];
        yield 'global split repository closed' => [true, true, true, 'closed'];
    }

    #[DataProvider('branchCases')]
    public function testClosedBranchCannotMoveHistoricalAmounts(bool $split, bool $repository, bool $global, string $status): void
    {
        $usedBy = $this->supplier;
        if ($global) {
            $group = $this->dimensions->createGroup($this->supplier, 'Syntetická skupina historie');
            $usedBy = $this->company();
            $this->dimensions->joinGroup($usedBy, $group, [$this->supplier, $usedBy], true);
        }
        $type = $this->type('custom', $global);
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $extra = ['dimensions' => [$type => $leaf]];
        if ($split) {
            $second = $this->value($type, 'LEAF2', $branch);
            $extra = ['dimension_splits' => [$type => [$leaf => 0.25, $second => 0.75]]];
        }
        $this->post($usedBy, 100, $extra);
        $this->periods->setStatus($this->periodIds[$usedBy], $usedBy, $status);
        $before = $this->amounts($usedBy, $type, [$a, $b]);
        self::assertSame([100.0, 0.0], $before);

        $refused = $this->attemptChange(fn () => $this->update($repository, $branch, ['parent_id' => $b]));

        self::assertSame($before, $this->amounts($usedBy, $type, [$a, $b]), 'Uzavřená větev A=100/B=0 nesmí přesunem číselníku přejít na A=0/B=100.');
        self::assertTrue($refused, 'Skutečný přesun použité větve musí skončit period_not_open.');
        self::assertSame($a, $this->values->findValue($this->supplier, $branch)['parent_id']);
    }

    public static function mappingWriters(): iterable
    {
        yield 'service' => [false];
        yield 'repository' => [true];
    }

    public function testGlobalHistoryRemainsProtectedAfterUsingCompanyLeavesGroup(): void
    {
        $group = $this->dimensions->createGroup($this->supplier, 'Syntetická minulá skupina');
        $usedBy = $this->company();
        $this->dimensions->joinGroup($usedBy, $group, [$this->supplier, $usedBy], true);
        $type = $this->type('custom', true);
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $this->post($usedBy, 100, ['dimensions' => [$type => $leaf]]);
        $this->periods->setStatus($this->periodIds[$usedBy], $usedBy, 'closed');
        $this->values->setSupplierGroup($usedBy, null);
        self::assertSame([100.0, 0.0], $this->amounts($usedBy, $type, [$a, $b], $this->supplier));
        $refused = $this->attemptChange(fn () => $this->values->updateValue($this->supplier, $branch, ['parent_id' => $b]));
        self::assertSame([100.0, 0.0], $this->amounts($usedBy, $type, [$a, $b], $this->supplier));
        self::assertTrue($refused);
    }

    #[DataProvider('mappingWriters')]
    public function testClosedLegacyMappingCannotReplaceX100WithY200(bool $repository): void
    {
        $type = $this->type('cost_center');
        $x = $this->centers->create($this->supplier, 'H-X', 'Historické X');
        $y = $this->centers->create($this->supplier, 'H-Y', 'Historické Y');
        $value = (int) $this->dimensions->createValue($this->supplier, $type, [
            'code' => 'MAPPING', 'name' => 'Syntetické mapování', 'cost_center_id' => $x,
        ])['id'];
        $this->post($this->supplier, 100, ['cost_center' => 'H-X']);
        $this->post($this->supplier, 200, ['cost_center' => 'H-Y']);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        self::assertFalse($this->values->valueInUse($value), 'Legacy text nemá explicitní FK přiřazení.');
        $before = $this->amounts($this->supplier, $type, [$value]);
        self::assertSame([100.0], $before);

        $refused = $this->attemptChange(fn () => $this->update($repository, $value, ['cost_center_id' => $y]));

        self::assertSame($before, $this->amounts($this->supplier, $type, [$value]), 'Mapování uzavřeného legacy střediska nesmí vyměnit historických 100 za 200.');
        self::assertTrue($refused);
        self::assertSame($x, $this->values->findValue($this->supplier, $value)['cost_center_id']);
    }

    public function testClosedLegacyDescendantAlsoProtectsItsUnusedParent(): void
    {
        $type = $this->type('cost_center');
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $center = $this->values->findValue($this->supplier, $leaf)['cost_center_id'];
        $code = $this->centers->find($this->supplier, $center)['code'];
        $this->post($this->supplier, 100, ['cost_center' => $code]);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        self::assertSame([100.0, 0.0], $this->amounts($this->supplier, $type, [$a, $b]));

        $refused = $this->attemptChange(fn () => $this->values->updateValue($this->supplier, $branch, ['parent_id' => $b]));

        self::assertSame([100.0, 0.0], $this->amounts($this->supplier, $type, [$a, $b]));
        self::assertTrue($refused);
    }

    public function testClosedValueAllowsNoOpAndDescriptiveMetadata(): void
    {
        $type = $this->type('custom');
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $this->post($this->supplier, 100, ['dimensions' => [$type => $leaf]]);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        $this->dimensions->updateValue($this->supplier, $branch, ['parent_id' => $a]);
        $this->values->updateValue($this->supplier, $leaf, [
            'name' => 'Opravený název', 'note' => 'Opravená poznámka',
            'responsible_note' => 'Aktuální správce', 'responsible_user_id' => $this->user,
        ]);
        self::assertSame([100.0, 0.0], $this->amounts($this->supplier, $type, [$a, $b]));
        $saved = $this->values->findValue($this->supplier, $leaf);
        self::assertSame('Opravený název', $saved['name']);
        self::assertSame($this->user, $saved['responsible_user_id']);
    }

    public function testClosedLegacyMappingAllowsSameReference(): void
    {
        $type = $this->type('cost_center');
        $value = $this->value($type, 'LEGACY');
        $center = $this->values->findValue($this->supplier, $value)['cost_center_id'];
        $this->post($this->supplier, 100, ['cost_center' => 'LEGACY']);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        $this->dimensions->updateValue($this->supplier, $value, ['cost_center_id' => (string) $center]);
        $this->values->updateValue($this->supplier, $value, ['cost_center_id' => $center]);
        self::assertSame([100.0], $this->amounts($this->supplier, $type, [$value]));
    }

    public function testOpenCustomBranchRemainsOrganizationallyEditable(): void
    {
        $type = $this->type('custom');
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $this->post($this->supplier, 100, ['dimensions' => [$type => $leaf]]);
        self::assertSame([100.0, 0.0], $this->amounts($this->supplier, $type, [$a, $b]));
        $this->dimensions->updateValue($this->supplier, $branch, ['parent_id' => $b]);
        self::assertSame([0.0, 100.0], $this->amounts($this->supplier, $type, [$a, $b]));
    }

    public function testUnusedBranchRemainsEditableDespiteAnotherClosedBranch(): void
    {
        $type = $this->type('custom');
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $unused = $this->value($type, 'UNUSED', $a);
        $this->post($this->supplier, 100, ['dimensions' => [$type => $leaf]]);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        $this->values->updateValue($this->supplier, $unused, ['parent_id' => $b]);
        self::assertSame($b, $this->values->findValue($this->supplier, $unused)['parent_id']);
        self::assertSame([100.0, 0.0], $this->amounts($this->supplier, $type, [$a, $b]));
    }

    public static function deletionCases(): iterable
    {
        yield 'value service' => [false, false];
        yield 'value repository' => [false, true];
        yield 'type service' => [true, false];
        yield 'type repository' => [true, true];
    }

    #[DataProvider('deletionCases')]
    public function testClosedLegacyHistoryCannotBeDeleted(bool $wholeType, bool $repository): void
    {
        $type = $this->type('cost_center');
        $value = $this->value($type, 'LEGACY');
        $this->post($this->supplier, 100, ['cost_center' => 'LEGACY']);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        self::assertSame([100.0], $this->amounts($this->supplier, $type, [$value]));
        $writer = $repository ? $this->values : $this->dimensions;
        $refused = $this->attemptChange(fn () => $wholeType
            ? $writer->deleteType($this->supplier, $type)
            : $writer->deleteValue($this->supplier, $value));
        self::assertNotNull($this->values->findValue($this->supplier, $value));
        self::assertSame([100.0], $this->amounts($this->supplier, $type, [$value]));
        self::assertTrue($refused);
    }

    public function testExistingUnusedMappingCannotAcquireClosedTargetHistory(): void
    {
        $type = $this->type('cost_center');
        $value = $this->value($type, 'UNUSED');
        $used = $this->centers->create($this->supplier, 'USED', 'Použité středisko');
        $this->post($this->supplier, 200, ['cost_center' => 'USED']);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        self::assertSame([0.0], $this->amounts($this->supplier, $type, [$value]));
        $refused = $this->attemptChange(fn () => $this->dimensions->updateValue($this->supplier, $value, ['cost_center_id' => $used]));
        self::assertSame([0.0], $this->amounts($this->supplier, $type, [$value]));
        self::assertTrue($refused);
    }

    public function testClosedLegacyCenterCannotLoseMappingThroughForeignKey(): void
    {
        $type = $this->type('cost_center');
        $value = $this->value($type, 'LEGACY');
        $center = $this->values->findValue($this->supplier, $value)['cost_center_id'];
        $this->post($this->supplier, 100, ['cost_center' => 'LEGACY']);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        self::assertSame([100.0], $this->amounts($this->supplier, $type, [$value]));
        $refused = $this->attemptChange(fn () => $this->centers->delete($this->supplier, $center));
        self::assertSame([100.0], $this->amounts($this->supplier, $type, [$value]));
        self::assertSame($center, $this->values->findValue($this->supplier, $value)['cost_center_id']);
        self::assertTrue($refused);
    }

    public function testNewMappingAndReactivationRemainAllowed(): void
    {
        $type = $this->type('cost_center');
        $used = $this->centers->create($this->supplier, 'USED', 'Použité středisko');
        $this->post($this->supplier, 100, ['cost_center' => 'USED']);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        $value = (int) $this->dimensions->createValue($this->supplier, $type, [
            'code' => 'NEW', 'name' => 'Nový historický filtr', 'cost_center_id' => $used, 'is_active' => false,
        ])['id'];
        $this->values->updateValue($this->supplier, $value, ['is_active' => true]);
        self::assertTrue($this->values->findValue($this->supplier, $value)['is_active']);
        self::assertSame([100.0], $this->amounts($this->supplier, $type, [$value]));
    }

    public function testProfileImportRefusesHistoricalMoveAndRollsBackEarlierMetadata(): void
    {
        $type = $this->type('custom');
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $this->post($this->supplier, 100, ['dimensions' => [$type => $leaf]]);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        $before = $this->values->findValue($this->supplier, $branch);
        $refused = false;
        try {
            $this->profiles->import($this->supplier, [
                'format' => 'myucto.company-profile', 'version' => 1,
                'sections' => ['dimensions' => ['types' => [[
                    'code' => 'history-custom', 'name' => 'Historie dimenze', 'kind' => 'custom',
                    'values' => [['code' => 'BRANCH', 'name' => 'Změna před přesunem', 'parent_code' => 'B']],
                ]]]],
            ], false);
        } catch (CompanyProfileException $e) {
            self::assertSame('period_not_open', $e->errorCode);
            self::assertSame('dimensions', $e->section);
            self::assertSame(409, $e->httpStatus);
            $refused = true;
        }
        self::assertSame([100.0, 0.0], $this->amounts($this->supplier, $type, [$a, $b]));
        self::assertSame($before, $this->values->findValue($this->supplier, $branch));
        self::assertTrue($refused);
    }

    public function testPeriodLockSerializesStructuralMutationWithClosing(): void
    {
        $type = $this->type('custom');
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $this->post($this->supplier, 100, ['dimensions' => [$type => $leaf]]);
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->periods->findForUpdate($this->periodIds[$this->supplier], $this->supplier);
        $this->periods->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        $this->otherDb->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $other->get(DimensionRepository::class)->updateValue($this->supplier, $branch, ['parent_id' => $b]);
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) $e->errorInfo[1]);
            $blocked = true;
        }
        self::assertTrue($blocked, 'Druhé spojení musí čekat na období před změnou stromu.');
        $pdo->commit();
        self::assertTrue($this->attemptChange(fn () => $other->get(DimensionRepository::class)->updateValue($this->supplier, $branch, ['parent_id' => $b])));
        self::assertSame([100.0, 0.0], $this->amounts($this->supplier, $type, [$a, $b]));
    }

    public function testOldSnapshotCannotTurnApparentNoOpIntoHistoricalMove(): void
    {
        $type = $this->type('custom');
        [$a, $b, $branch, $leaf] = $this->tree($type);
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $pdo->beginTransaction();
        self::assertSame($a, $this->values->findValue($this->supplier, $branch)['parent_id']);
        $other->get(DimensionRepository::class)->updateValue($this->supplier, $branch, ['parent_id' => $b]);
        $other->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 100, 'dimensions' => [$type => $leaf]],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100, 'dimensions' => [$type => $leaf]],
        ], ['entry_date' => '2093-05-10', 'posted_by' => $this->user]);
        $other->get(AccountingPeriodRepository::class)->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        self::assertTrue($this->attemptChange(fn () => $this->values->updateValue($this->supplier, $branch, ['parent_id' => $a])));
        $this->values->updateValue($this->supplier, $branch, ['parent_id' => $b]);
        $pdo->rollBack();
        self::assertSame([0.0, 100.0], $this->amounts($this->supplier, $type, [$a, $b]));
    }

    public function testStaleCostCenterDeleteActionRefusesNewClosedLegacyUse(): void
    {
        $type = $this->type('cost_center');
        $value = $this->value($type, 'LEGACY');
        $center = $this->values->findValue($this->supplier, $value)['cost_center_id'];
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $pdo->beginTransaction();
        self::assertFalse($this->centers->hasUsage($this->supplier, 'LEGACY'));
        $other->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 100, 'cost_center' => 'LEGACY'],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100, 'cost_center' => 'LEGACY'],
        ], ['entry_date' => '2093-05-10', 'posted_by' => $this->user]);
        $other->get(AccountingPeriodRepository::class)->setStatus($this->periodIds[$this->supplier], $this->supplier, 'closed');
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/accounting/cost-centers/' . $center)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin']);
        $response = $this->centerAction->delete($request, new Response(), ['id' => $center]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('period_not_open', (string) $response->getBody());
        $pdo->rollBack();
        self::assertSame([100.0], $this->amounts($this->supplier, $type, [$value]));
    }

    private function commitAndConnect(): \Psr\Container\ContainerInterface
    {
        $this->db->pdo()->commit();
        $this->committed = true;
        $other = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class);
            return $container;
        });
        $this->otherDb = $other->get(Connection::class);
        self::assertNotSame(
            $this->db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
            $this->otherDb->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
        );
        return $other;
    }

    private function company(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode, accounting_enabled)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id, "double_entry", 1
               FROM supplier ORDER BY id LIMIT 1'
        )->execute(['Syntetická historie dimenzí', 'dimension-history-' . uniqid() . '@example.invalid']);
        $id = (int) $pdo->lastInsertId();
        self::assertGreaterThan(0, $id);
        $this->seeder->seedForSupplier($id);
        $this->periodIds[$id] = $this->periods->create($id, self::YEAR, self::FROM, self::TO);
        $this->dimensions->setEnabled($id, true);
        return $id;
    }

    private function type(string $kind, bool $global = false): int
    {
        return (int) $this->dimensions->createType($this->supplier, [
            'code' => 'history-' . $kind, 'name' => 'Historie dimenze',
            'kind' => $kind, 'level' => $global ? 'global' : 'company',
        ])['id'];
    }

    private function value(int $type, string $code, ?int $parent = null): int
    {
        return (int) $this->dimensions->createValue($this->supplier, $type, [
            'code' => $code, 'name' => 'Syntetická hodnota ' . $code, 'parent_id' => $parent,
        ])['id'];
    }

    private function tree(int $type): array
    {
        $a = $this->value($type, 'A');
        $b = $this->value($type, 'B');
        $branch = $this->value($type, 'BRANCH', $a);
        return [$a, $b, $branch, $this->value($type, 'LEAF', $branch)];
    }

    private function post(int $supplier, float $amount, array $extra): void
    {
        $this->posting->postDocument($supplier, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $amount] + $extra,
            ['account_code' => '602', 'side' => 'credit', 'amount' => $amount] + $extra,
        ], ['entry_date' => '2093-05-10', 'posted_by' => $this->user]);
    }

    private function amounts(int $supplier, int $type, array $values, ?int $visibleTo = null): array
    {
        $visibleTo ??= $supplier;
        $report = $this->profit->build($visibleTo, $type, self::FROM, self::TO, [$supplier]);
        $rows = array_column($report['rows'], null, 'value_id');
        $amounts = [];
        foreach ($values as $value) {
            self::assertArrayHasKey($value, $rows);
            $amount = (float) $rows[$value]['total']['result'];
            $filtered = $this->statements->incomeStatement(
                $supplier, $this->periodIds[$supplier], self::TO, 'full', $this->dimensions->filter($visibleTo, $value),
            );
            self::assertEqualsWithDelta($amount, (float) $filtered['checks']['profit_current'], 0.001);
            $amounts[] = $amount;
        }
        return $amounts;
    }

    private function update(bool $repository, int $value, array $changes): void
    {
        if ($repository) {
            $this->values->updateValue($this->supplier, $value, $changes);
        } else {
            $this->dimensions->updateValue($this->supplier, $value, $changes);
        }
    }

    private function attemptChange(callable $change): bool
    {
        try {
            $change();
            return false;
        } catch (DimensionException $e) {
            self::assertSame('period_not_open', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            return true;
        }
    }
}
