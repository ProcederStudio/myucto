<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollMonthlyOverviewPdfRenderer;
use MyInvoice\Service\Payroll\Document\PayrollMonthlyOverviewService;
use PHPUnit\Framework\TestCase;

final class PayrollMonthlyOverviewTest extends TestCase
{
    public function testComputedRowTakesAmountsFromApprovedResultAndFrozenInput(): void
    {
        $row = PayrollMonthlyOverviewService::computedRow(
            self::inputPerson(),
            self::resultPerson(),
            self::payslip(),
        );

        self::assertSame('Testovací Zaměstnanec', $row['name']);
        self::assertSame('Z9001', $row['personal_number']);
        self::assertTrue($row['to_account']);
        self::assertSame(120_000, $row['worked_millihours']);
        self::assertSame(4_200_000, $row['gross_minor']);
        self::assertSame(298_200, $row['employee_social_minor']);
        self::assertSame(189_000, $row['employee_health_minor']);
        self::assertSame(372_000, $row['tax_minor']);
        self::assertSame(185_800, $row['meal_allowance_minor']);
        self::assertSame(50_000, $row['deductions_minor']);
        self::assertNull($row['wage_advance_minor']);
        self::assertSame(3_290_800, $row['payable_minor']);
    }

    public function testRowsShowSurnameFirstInCzechAlphabeticalOrder(): void
    {
        $rows = PayrollMonthlyOverviewService::ordered(array_map(
            static fn (string $name): array => ['name' => $name, 'personal_number' => ''],
            ['Ing. Adéla Králová', 'Jan Šimek', 'Petr Dluhoš', 'Eva Dlábková', 'Karel Chudý', 'Iva Horká', 'Ota Čermák', 'Ivo Cibulka'],
        ));

        self::assertSame(
            ['Cibulka Ivo', 'Čermák Ota', 'Dlábková Eva', 'Dluhoš Petr', 'Horká Iva', 'Chudý Karel', 'Králová Adéla Ing.', 'Šimek Jan'],
            array_column($rows, 'name'),
        );
    }

    public function testTotalsSkipUnknownValuesInsteadOfCountingZero(): void
    {
        $totals = PayrollMonthlyOverviewService::totals([
            ['gross_minor' => 100, 'worked_millihours' => null, 'payable_minor' => 80],
            ['gross_minor' => 50, 'worked_millihours' => null, 'payable_minor' => 40],
        ]);

        self::assertSame(150, $totals['gross_minor']);
        self::assertSame(120, $totals['payable_minor']);
        self::assertNull($totals['worked_millihours']);
    }

    public function testRendersEmployeeTableAndPersonalCostsSummary(): void
    {
        $row = PayrollMonthlyOverviewService::computedRow(
            self::inputPerson(),
            self::resultPerson(),
            self::payslip(),
        );
        $html = (new PayrollMonthlyOverviewPdfRenderer())->renderHtml([
            'period' => '2026-09',
            'period_label' => '09/2026',
            'source' => 'calculated',
            'employer' => ['name' => 'Ukázková společnost s.r.o.', 'identification_number' => '00000000'],
            'rows' => [$row],
            'totals' => PayrollMonthlyOverviewService::totals([$row]),
            'summary' => array_fill_keys([
                'social_base_minor', 'social_employee_minor', 'social_pensioner_discount_minor',
                'social_employer_minor', 'health_base_minor', 'health_employee_minor',
                'health_employer_minor', 'taxable_income_minor', 'tax_base_minor',
                'tax_before_credits_minor', 'tax_credits_applied_minor', 'child_credit_minor',
                'advance_tax_minor', 'tax_bonus_minor', 'withholding_base_minor',
                'withholding_tax_minor', 'reimbursements_minor', 'meal_allowance_minor',
                'deductions_minor', 'payout_account_minor', 'payout_cash_minor', 'gross_minor',
            ], null) + ['personal_costs_minor' => 5_620_000],
        ]);

        self::assertStringContainsString('Přehled mezd', $html);
        self::assertStringContainsString('Testovací Zaměstnanec', $html);
        self::assertStringContainsString('42 000', $html);
        self::assertStringContainsString('32 908', $html);
        self::assertStringContainsString('Osobní náklady celkem', $html);
        self::assertStringContainsString('56 200', $html);
    }

    /** @return array<string,mixed> */
    private static function inputPerson(): array
    {
        return [
            'employee' => ['id' => 7, 'full_name' => 'Testovací Zaměstnanec'],
            'payout_accounts' => [['bank_account_masked' => '••••0005/0100']],
            'employments' => [[
                'employment' => ['id' => 3, 'code' => 'Z9001'],
                'time_month' => ['jmhz_work_summary' => ['values' => [
                    'evidence_days' => 30,
                    'worked_days' => 16,
                    'worked_millihours' => 120_000,
                    'overtime_millihours' => 0,
                ]]],
                'inputs' => [
                    ['id' => 11, 'component' => ['code' => 'MZDA_MESICNI', 'name' => 'Základní měsíční mzda', 'component_kind' => 'base_wage']],
                    ['id' => 13, 'component' => ['code' => 'PRISPEVEK_STRAVOVANI', 'name' => 'Příspěvek na stravování', 'component_kind' => 'benefit_meal']],
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private static function resultPerson(): array
    {
        return [
            'employee_id' => 7,
            'employments' => [[
                'employment_id' => 3,
                'inputs' => [
                    ['input_id' => 11, 'totals' => ['source_amount_minor' => 4_014_200]],
                    ['input_id' => 13, 'totals' => ['source_amount_minor' => 185_800]],
                ],
            ]],
            'statutory' => [
                'social_insurance' => ['working_pensioner_discount_minor_units' => 0],
                'net_pay' => ['withholding_tax_minor_units' => 0],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function payslip(): array
    {
        return [
            'employee_display_name' => 'Testovací Zaměstnanec',
            'gross_minor_units' => 4_200_000,
            'employee_social_minor_units' => 298_200,
            'employee_health_minor_units' => 189_000,
            'health_minimum_top_up_minor_units' => 0,
            'tax_after_credits_minor_units' => 372_000,
            'other_deduction_lines' => [['label' => 'Syntetická srážka', 'amount_minor_units' => 50_000]],
            'net_minor_units' => 3_290_800,
        ];
    }
}
