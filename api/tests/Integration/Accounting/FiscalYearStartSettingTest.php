<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\AccountingPeriodAction;
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

    public function testShortFirstFiscalPeriodKeepsCompanyFiscalCalendar(): void
    {
        // Firma vzniklá 1. 3. s hospodářským rokem od srpna má první období jen 1. 3.–31. 7.
        // Podle tvaru (~5 měsíců) by vypadalo kalendářně; rozhodne nastavení firmy, ze kterého
        // berou kalendář i odpisy a karta majetku.
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplierId]);
        $repo = new AccountingPeriodRepository($this->db);
        $repo->create($this->supplierId, 2098, '2099-03-01', '2099-07-31');

        self::assertSame(8, $repo->fiscalCalendar($this->supplierId)->startMonth());
        $next = $repo->ensureOpenPeriodFor($this->supplierId, '2099-09-15');
        self::assertSame(['2099-08-01', '2100-07-31'], [(string) $next['starts_on'], (string) $next['ends_on']]);
    }

    public function testShortFirstFiscalPeriodIsLabelledByFiscalYearOfItsStart(): void
    {
        // Zkrácené první období 1. 3.–31. 7. 2099 patří do účetního roku 2098 (srpen 2098 až
        // červenec 2099); s labelem 2099 by kolidovalo s obdobím od 1. 8. 2099.
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplierId]);
        $action = $this->container->get(AccountingPeriodAction::class);

        $wrong = $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2099, 'starts_on' => '2099-03-01', 'ends_on' => '2099-07-31']);
        self::assertSame(422, $wrong['status']);

        $short = $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2098, 'starts_on' => '2099-03-01', 'ends_on' => '2099-07-31']);
        self::assertSame(201, $short['status'], json_encode($short['body'], JSON_UNESCAPED_UNICODE));

        $next = $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2099, 'starts_on' => '2099-08-01', 'ends_on' => '2100-07-31']);
        self::assertSame(201, $next['status'], json_encode($next['body'], JSON_UNESCAPED_UNICODE));
    }

    public function testCalendarCompanyLabelStaysYearOfStart(): void
    {
        $this->db->pdo()->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 1 WHERE id = ?')->execute([$this->supplierId]);
        $action = $this->container->get(AccountingPeriodAction::class);

        self::assertSame(422, $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2098, 'starts_on' => '2099-03-01', 'ends_on' => '2099-12-31'])['status']);
        self::assertSame(201, $this->callAction($action, 'create', 'POST', 'admin', ['fiscal_year' => 2099, 'starts_on' => '2099-03-01', 'ends_on' => '2099-12-31'])['status']);
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
