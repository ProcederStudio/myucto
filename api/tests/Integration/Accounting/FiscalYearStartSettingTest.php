<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Nastavení „Účetní rok začíná" (migrace 2003) se zadává PŘEDEM. Proti existujícím
 * obdobím jiného tvaru se změnit nedá (změna účetního roku je samostatný úkon
 * s přechodným obdobím) a fyzická osoba hospodářský rok mít nemůže.
 * Vše v transakci BankPostingTestCase → rollback.
 */
#[Group('integration')]
final class FiscalYearStartSettingTest extends BankPostingTestCase
{
    public function testChangeAgainstExistingCalendarPeriodsIsRejected(): void
    {
        $this->ensurePeriod();

        $res = $this->update(['fiscal_year_start_month' => 8]);

        self::assertSame(409, $res['status']);
        self::assertSame('fiscal_year_conflicts_periods', $res['body']['error']['code']);
    }

    public function testUnchangedValueSavesEvenWithPeriods(): void
    {
        $this->ensurePeriod();

        self::assertSame(200, $this->update(['fiscal_year_start_month' => 1])['status']);
    }

    public function testCompanyWithoutPeriodsGetsFiscalYearAndFirstPeriodFollowsIt(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET taxpayer_type = 'po' WHERE id = ?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);

        $res = $this->update(['fiscal_year_start_month' => 8]);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $period = (new AccountingPeriodRepository($this->db))->ensureOpenPeriodFor($this->supplierId, '2099-03-10');
        self::assertSame(['2098-08-01', '2099-07-31'], [(string) $period['starts_on'], (string) $period['ends_on']]);
    }

    public function testNaturalPersonCannotHaveFiscalYear(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET taxpayer_type = 'fo' WHERE id = ?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);

        $res = $this->update(['fiscal_year_start_month' => 8]);

        self::assertSame(400, $res['status']);
        self::assertSame('fiscal_year_not_for_fo', $res['body']['error']['code']);
    }

    public function testOutOfRangeMonthIsRejected(): void
    {
        self::assertSame(400, $this->update(['fiscal_year_start_month' => 13])['status']);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function update(array $body): array
    {
        return $this->callAction($this->container->get(SettingsAction::class), 'updateSupplier', 'PUT', 'admin', $body);
    }

    private function ensurePeriod(): void
    {
        (new AccountingPeriodRepository($this->db))->ensureOpenPeriodFor($this->supplierId, self::YEAR . '-06-01');
    }
}
