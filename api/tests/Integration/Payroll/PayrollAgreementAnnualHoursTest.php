<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessImpact;
use MyInvoice\Service\Payroll\Time\PayrollAgreementAnnualHours;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 75 zákoníku práce: dohoda o provedení práce nejvýš 300 hodin v roce
 * u téhož zaměstnavatele, včetně jiných DPP. Hlídané to nebylo vůbec — a u
 * firmy po přechodu leží část hodin jen v převzatých mzdách.
 */
#[Group('integration')]
final class PayrollAgreementAnnualHoursTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierId;
    private int $employeeId;
    private int $firstAgreement;
    private int $secondAgreement;

    protected function setUp(): void
    {
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-10-01")')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická brigádnice", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $this->firstAgreement = $this->agreement('DPP-1');
        $this->secondAgreement = $this->agreement('DPP-2');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testTakenOverHoursOfAllAgreementsCountTowardsTheLimit(): void
    {
        // Leden až září 2× 20 h měsíčně na dvou dohodách = 360 h převzatých.
        for ($month = 1; $month <= 9; ++$month) {
            $this->takeover($this->firstAgreement, $month, 20 * 60);
            $this->takeover($this->secondAgreement, $month, 20 * 60);
        }
        $service = new PayrollAgreementAnnualHours($this->db);

        $hours = $service->yearToDate($this->supplierId, [$this->employeeId], '2026-10-01');
        self::assertSame(360 * 60, $hours[$this->employeeId]['takeover_minutes']);

        $validations = $service->validations($this->supplierId, [[
            'employment_id' => $this->firstAgreement,
            'employee_id' => $this->employeeId,
            'relation_type' => 'dpp',
            'full_name' => 'Syntetická brigádnice',
        ]], '2026-10-01');

        self::assertCount(1, $validations);
        self::assertSame('dpp_annual_hours_exceeded', $validations[0]->code);
        self::assertStringContainsString('Syntetická brigádnice', $validations[0]->message);
        self::assertStringContainsString('360 h', $validations[0]->message);
        self::assertStringContainsString('převzatých', $validations[0]->message);
        self::assertStringContainsString("person={$this->employeeId}", (string) $validations[0]->remediationPath);
        // Varování, ne závora: odpracovanou práci je potřeba zaplatit.
        self::assertSame('warning', PayrollRunReadinessImpact::describe('dpp_annual_hours_exceeded')['severity']);
    }

    public function testHoursWithinTheLimitProduceNoWarning(): void
    {
        for ($month = 1; $month <= 9; ++$month) {
            $this->takeover($this->firstAgreement, $month, 30 * 60);
        }
        $validations = (new PayrollAgreementAnnualHours($this->db))->validations($this->supplierId, [[
            'employment_id' => $this->firstAgreement,
            'employee_id' => $this->employeeId,
            'relation_type' => 'dpp',
            'full_name' => 'Syntetická brigádnice',
        ]], '2026-10-01');

        self::assertSame([], $validations);
    }

    /**
     * Převod zakládá pracovní souhrn i za převzaté měsíce. Tytéž hodiny se nesmí
     * sečíst dvakrát (TOPTAX 7/2026: 550 h místo 285,5 h a falešné varování).
     */
    public function testTakenOverMonthIsNotCountedAgainFromOwnWorkSummary(): void
    {
        $hours = PayrollAgreementAnnualHours::combine(
            [7],
            [
                ['employee_id' => 7, 'period_start' => '2026-05-01', 'minutes' => 40 * 60],
                ['employee_id' => 7, 'period_start' => '2026-06-01', 'minutes' => 50 * 60],
            ],
            [
                ['employee_id' => 7, 'period_start' => '2026-05-01', 'millihours' => 40_000],
                ['employee_id' => 7, 'period_start' => '2026-06-01', 'millihours' => 50_000],
                ['employee_id' => 7, 'period_start' => '2026-07-01', 'millihours' => 10_500],
            ],
        );

        self::assertSame(90 * 60, $hours[7]['takeover_minutes']);
        self::assertSame(630, $hours[7]['own_minutes']);
        self::assertSame(90 * 60 + 630, $hours[7]['total_minutes']);
    }

    private function agreement(string $code): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "dpp", "active", "2026-01-01", "2026-01-01", 0, 0, 0)',
        )->execute([$this->supplierId, $this->employeeId, $code]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function takeover(int $employmentId, int $month, int $minutes): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref, external_relationship_ref,
                 employee_id, employment_id, gross_minor, worked_minutes)
             VALUES (?, "other", ?, ?, ?, ?, ?, 400000, ?)',
        )->execute([
            $this->supplierId,
            sprintf('2026-%02d-01', $month),
            'employee:' . $this->employeeId,
            'employment:' . $employmentId,
            $this->employeeId,
            $employmentId,
            $minutes,
        ]);
    }
}
