<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use DateTimeImmutable;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Sample\SamplePayrollGenerator;
use MyInvoice\Service\Sample\SamplePayrollPurger;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class SamplePayrollGeneratorTest extends TestCase
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
        $this->pdo->prepare('UPDATE supplier SET company_name = "Ukázková školící společnost", display_name = "Ukázková školící společnost", ic = "00000019", accounting_mode = "double_entry", payroll_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->pdo->prepare('INSERT INTO users (email, password_hash, name, role, locale, is_active) VALUES (?, ?, "Ukázková účetní", "readonly", "cs", 1)')->execute([
            'sample-payroll-' . bin2hex(random_bytes(8)) . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('SET @payroll_sample_purge_supplier_id = NULL');
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        }
    }

    public function testLinkedPayrollAndPurgePreserveExistingComponents(): void
    {
        $this->container->get(PayrollComponentRepository::class)->ensureDefaults($this->supplierId);
        $before = SamplePayrollPurger::snapshot($this->pdo, $this->supplierId);
        $counts = $this->generate();
        self::assertSame(4, $counts['payroll_persons']);
        self::assertSame(4, $counts['payroll_runs']);
        self::assertSame(12, $counts['payroll_results']);
        self::assertSame(3, $counts['payroll_posting_batches']);
        self::assertCount(3, $counts['journal_entry_ids']);
        self::assertSame('active', $this->scalar('SELECT status FROM payroll_module_state WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(24, (int) $this->scalar('SELECT COUNT(*) FROM payroll_payment_liabilities WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM payroll_absences WHERE supplier_id = ? AND status = "approved"', [$this->supplierId]));
        self::assertSame(2_600_000, (int) $this->scalar('SELECT amount_minor FROM payroll_inputs WHERE supplier_id = ? AND external_id = "quick-monthly:sample:2026-08:base:1"', [$this->supplierId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payroll_run_validations WHERE supplier_id = ? AND (severity = "blocker" OR requires_override = 1)', [$this->supplierId]));
        $removed = $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
        self::assertSame(4, $removed['payroll_employees']);
        self::assertSame(4, $removed['payroll_period_ownership']);
        self::assertSame(array_filter($before), array_filter(SamplePayrollPurger::snapshot($this->pdo, $this->supplierId)));
        self::assertSame(0, (int) $this->scalar('SELECT payroll_enabled FROM supplier WHERE id = ?', [$this->supplierId]));
        self::assertNull($this->scalar('SELECT @payroll_sample_purge_supplier_id', []));
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = "payroll"', [$this->supplierId]));
    }

    public function testImmutableGuardRequiresExactOwnershipAndSupplier(): void
    {
        $this->generate();
        $auditId = (int) $this->scalar('SELECT MIN(id) FROM payroll_employer_policy_audit WHERE supplier_id = ?', [$this->supplierId]);
        self::assertGreaterThan(0, $auditId);
        $this->assertDeleteBlocked($auditId);
        $this->pdo->prepare('SET @payroll_sample_purge_supplier_id = ?')->execute([$this->supplierId + 1]);
        $this->assertDeleteBlocked($auditId);
        $type = SamplePayrollPurger::ownershipType('payroll_employer_policy_audit');
        $this->pdo->prepare('DELETE FROM sample_data_entries WHERE supplier_id = ? AND entity_type = ? AND entity_id = ?')->execute([$this->supplierId, $type, $auditId]);
        $this->pdo->prepare('SET @payroll_sample_purge_supplier_id = ?')->execute([$this->supplierId]);
        $this->assertDeleteBlocked($auditId);
        $this->pdo->exec('SET @payroll_sample_purge_supplier_id = NULL');
        try {
            $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
            self::fail('Odebrání nesmí odstranit neevidovaný závislý řádek.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('neevidovanými daty', $e->getMessage());
        }
        self::assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', [$this->supplierId]));
        $this->pdo->prepare('INSERT INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)')->execute([$this->supplierId, $type, $auditId]);
        $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
        self::assertSame([], array_filter(SamplePayrollPurger::snapshot($this->pdo, $this->supplierId)));
    }

    public function testTaxEvidencePayrollDoesNotCreateJournalEntries(): void
    {
        $this->pdo->prepare('UPDATE supplier SET accounting_mode = "tax_evidence" WHERE id = ?')->execute([$this->supplierId]);
        $counts = $this->generate(false);
        self::assertSame(0, $counts['payroll_posting_batches']);
        self::assertSame([], $counts['journal_entry_ids']);
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM payroll_runs WHERE supplier_id = ? AND status = "approved"', [$this->supplierId]));
        $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
        self::assertSame([], array_filter(SamplePayrollPurger::snapshot($this->pdo, $this->supplierId)));
    }

    private function generate(bool $advanced = true): array
    {
        return $this->container->get(SamplePayrollGenerator::class)->generate($this->supplierId, $this->userId, new DateTimeImmutable('2026-10-11'), $advanced, function (string $type, int $id): void {
            $this->pdo->prepare('INSERT IGNORE INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)')->execute([$this->supplierId, $type, $id]);
        });
    }

    private function assertDeleteBlocked(int $auditId): void
    {
        try {
            $this->pdo->prepare('DELETE FROM payroll_employer_policy_audit WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $auditId]);
            self::fail('Neměnný řádek nesmí být odstraněn bez přesné evidence a rozsahu.');
        } catch (PDOException $e) {
            self::assertSame('45000', $e->getCode());
        }
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
