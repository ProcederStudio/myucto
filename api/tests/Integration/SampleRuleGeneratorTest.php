<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingRuleValidator;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Sample\SampleRuleGenerator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class SampleRuleGeneratorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private PDO $pdo;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare('UPDATE supplier SET company_name = "Ukázkové pravidlo s.r.o.", display_name = "Ukázkové pravidlo", accounting_mode = "double_entry" WHERE id = ?')->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->pdo->prepare('INSERT INTO users (email, password_hash, name, role, locale, is_active) VALUES (?, ?, "Ukázková účetní", "readonly", "cs", 1)')->execute([
            'sample-rule-' . bin2hex(random_bytes(8)) . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testRulesMatchDemoItemsAndPreserveExistingRules(): void
    {
        $vendors = [];
        foreach (['Modelový Pronájem s.r.o.', 'Sample Cloud GmbH', 'Sample Office s.r.o.', 'Demo Hardware s.r.o.'] as $name) {
            $this->pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id, is_vendor, is_customer) VALUES (?, ?, "Ukázková 12", "Praha", "10000", (SELECT id FROM countries WHERE iso2 = "CZ" LIMIT 1), "vendor@example.invalid", (SELECT id FROM currencies WHERE code = "CZK" LIMIT 1), 1, 0)')->execute([$this->supplierId, $name]);
            $vendors[$name] = (int) $this->pdo->lastInsertId();
            $this->track('vendor', $vendors[$name]);
        }
        $expenseRepo = $this->container->get(ExpenseClassificationRuleRepository::class);
        $bankRepo = $this->container->get(BankPostingRuleRepository::class);
        $existingExpense = $expenseRepo->insert($this->supplierId, [
            'name' => 'Původní syntetické pravidlo', 'description_contains' => 'Původní školící položka',
            'expense_kind' => 'service', 'application_mode' => 'suggest',
        ], $this->userId);
        $existingBank = $bankRepo->insert($this->supplierId, $this->container->get(BankPostingRuleValidator::class)->normalizeRule($this->supplierId, [
            'name' => 'Původní syntetická banka', 'direction' => 'outgoing', 'message_contains' => 'Původní školící platba',
            'debit_account_code' => '568', 'credit_account_code' => '221',
        ]), $this->userId);
        $expenseBefore = $expenseRepo->find($this->supplierId, $existingExpense);
        $bankBefore = $bankRepo->find($this->supplierId, $existingBank);
        $postingBefore = $this->rows('SELECT * FROM posting_rules WHERE supplier_id = ? ORDER BY id', [$this->supplierId]);

        self::assertSame(['expense_rules' => 4, 'bank_posting_rules' => 3], $this->generate());
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame($expenseBefore, $expenseRepo->find($this->supplierId, $existingExpense));
        self::assertSame($bankBefore, $bankRepo->find($this->supplierId, $existingBank));
        self::assertSame($postingBefore, $this->rows('SELECT * FROM posting_rules WHERE supplier_id = ? ORDER BY id', [$this->supplierId]));

        $examples = [
            ['Modelový Pronájem s.r.o.', 'Nájem kanceláře a skladu', 12500.0, 'service'],
            ['Sample Cloud GmbH', 'Cloudové služby', 2500.0, 'service'],
            ['Sample Office s.r.o.', 'Kancelářský a obalový materiál', 650.0, 'material'],
            ['Demo Hardware s.r.o.', 'Výkonný server pro aplikační provoz', 180000.0, 'fixed_asset'],
        ];
        $rules = $expenseRepo->activeFor($this->supplierId);
        foreach ($examples as [$vendor, $text, $price, $kind]) {
            $suggestion = $this->container->get(ExpenseClassificationService::class)->suggestFromRules($this->supplierId, $text, $vendor, $vendors[$vendor], $price, 2026, $rules);
            self::assertNotNull($suggestion);
            self::assertSame('rule', $suggestion->source);
            self::assertSame($kind, $suggestion->kind->value);
            self::assertGreaterThan(0, $suggestion->ruleId);
        }
        $bankExamples = [
            'bank.fee' => ['amount' => -129.0, 'description' => 'Poplatek za vedení účtu'],
            'bank.interest' => ['amount' => 347.25, 'description' => 'Připsaný úrok z běžného účtu'],
            'bank.rule.custom' => ['amount' => -12500.0, 'description' => 'Nájem dle smlouvy DEMO-NAJEM za říjen'],
        ];
        foreach ($bankRepo->listForTenant($this->supplierId) as $rule) {
            if ($rule['id'] === $existingBank) continue;
            self::assertSame('suggest', $rule['mode']);
            self::assertTrue((new BankRuleMatcher())->matching($rule, $bankExamples[$rule['operation_type']]));
            self::assertFalse((new BankRuleMatcher())->matching($rule, ['amount' => 1000000.0, 'description' => 'Platba faktury']));
        }
        $owned = $this->rows('SELECT entity_type, entity_id FROM sample_data_entries WHERE supplier_id = ? AND entity_type IN ("expense_rule", "bank_posting_rule")', [$this->supplierId]);
        self::assertCount(7, $owned);
        foreach ($owned as $row) {
            if ($row['entity_type'] === 'expense_rule') $expenseRepo->delete($this->supplierId, (int) $row['entity_id']);
            else $bankRepo->delete($this->supplierId, (int) $row['entity_id']);
        }
        self::assertSame([$expenseBefore], $expenseRepo->activeFor($this->supplierId));
        self::assertSame($bankBefore, $bankRepo->find($this->supplierId, $existingBank));
    }

    public function testMissingTrackedVendorsFailBeforeCreatingRulesAndTaxEvidenceSkips(): void
    {
        self::assertSame(['expense_rules' => 0, 'bank_posting_rules' => 0], $this->generate(false));
        try {
            $this->generate();
            self::fail('Pravidla nesmějí používat neevidované dodavatele.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('evidovaný ukázkový dodavatel', $e->getMessage());
        }
        self::assertSame([], $this->container->get(ExpenseClassificationRuleRepository::class)->activeFor($this->supplierId));
        self::assertSame([], $this->container->get(BankPostingRuleRepository::class)->listForTenant($this->supplierId));
    }

    private function generate(bool $advanced = true): array
    {
        return $this->container->get(SampleRuleGenerator::class)->generate($this->supplierId, $this->userId, $advanced, $this->track(...));
    }

    private function track(string $type, int $id): void
    {
        $this->pdo->prepare('INSERT INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)')->execute([$this->supplierId, $type, $id]);
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
