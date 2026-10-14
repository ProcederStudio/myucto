<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollPersonDisplayName;
use PDO;

/**
 * Přehled mezd za měsíc — všichni zaměstnanci v jedné tabulce a souhrn
 * osobních nákladů za období (vzor „Přehled mezd" z PAMICA).
 *
 * Měsíc spočítaný v MyÚčtu se skládá ze schválených revizí běhů období
 * (výsledky osob a zmrazené vstupy). Měsíc, za který MyÚčto žádnou revizi
 * nemá, se vezme z převzatých úhrnů předchozího programu
 * ({@see PayrollSheetTakenOverMonthsReader}); co zdroj nenese, zůstane `null`.
 *
 * Je to pracovní sestava účetní, ne doklad zaměstnance — nearchivuje se
 * a pokaždé se sestaví z aktuálního stavu schválených revizí.
 */
final class PayrollMonthlyOverviewService
{
    /** Sloupce tabulky po zaměstnancích, které se sčítají do součtového řádku. */
    public const ROW_AMOUNTS = [
        'worked_millihours',
        'overtime_millihours',
        'gross_minor',
        'employee_social_minor',
        'pensioner_discount_minor',
        'employee_health_minor',
        'tax_minor',
        'reimbursements_minor',
        'deductions_minor',
        'wage_advance_minor',
        'meal_allowance_minor',
        'payable_minor',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSheetTakenOverMonthsReader $takenOver,
    ) {}

    /** @return array<string,mixed> */
    public function build(int $supplierId, string $period): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Období přehledu mezd musí být ve tvaru YYYY-MM.');
        }
        $periodStart = $period . '-01';
        $employer = $this->employer($supplierId);
        $revisions = $this->approvedRevisions($supplierId, $periodStart);
        if ($revisions !== []) {
            [$rows, $summary] = $this->fromRevisions($revisions);
            $source = 'calculated';
        } else {
            [$rows, $summary] = $this->fromTakenOver($supplierId, $periodStart);
            $source = 'taken_over';
        }
        if ($rows === []) {
            throw new \DomainException(
                'Za zvolené období není schválený mzdový běh ani převzatá mzda z předchozího programu.',
            );
        }
        $rows = self::ordered($rows);

        return [
            'period' => $period,
            'period_label' => substr($period, 5, 2) . '/' . substr($period, 0, 4),
            'source' => $source,
            'employer' => $employer,
            'rows' => $rows,
            'totals' => self::totals($rows),
            'summary' => $summary,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,?int>
     */
    public static function totals(array $rows): array
    {
        $totals = [];
        foreach (self::ROW_AMOUNTS as $key) {
            $total = null;
            foreach ($rows as $row) {
                if (is_int($row[$key] ?? null)) {
                    $total = ($total ?? 0) + $row[$key];
                }
            }
            $totals[$key] = $total;
        }

        return $totals;
    }

    /**
     * @param list<array{input:array<string,mixed>,result:array<string,mixed>}> $revisions
     * @return array{0:list<array<string,mixed>>,1:array<string,?int>}
     */
    private function fromRevisions(array $revisions): array
    {
        $rows = [];
        $summary = self::emptySummary();
        foreach ($revisions as $revision) {
            $inputPeople = [];
            foreach (is_array($revision['input']['people'] ?? null) ? $revision['input']['people'] : [] as $person) {
                $id = is_array($person) && is_array($person['employee'] ?? null) ? ($person['employee']['id'] ?? null) : null;
                if (is_int($id)) {
                    $inputPeople[$id] = $person;
                }
            }
            foreach (is_array($revision['result']['people'] ?? null) ? $revision['result']['people'] : [] as $person) {
                if (!is_array($person) || !is_int($person['employee_id'] ?? null)) {
                    continue;
                }
                $payslip = is_array($person['payslip_document'] ?? null) ? $person['payslip_document'] : null;
                if ($payslip === null) {
                    continue;
                }
                $input = $inputPeople[$person['employee_id']] ?? [];
                $row = self::computedRow($input, $person, $payslip);
                $rows[] = $row;
                self::addSummary($summary, self::computedSummary($person, $payslip, $row));
            }
        }

        return [$rows, $summary];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $person
     * @param array<string,mixed> $payslip
     * @return array<string,mixed>
     */
    public static function computedRow(array $input, array $person, array $payslip): array
    {
        $facts = PayrollFrozenMonthFacts::fromFrozen($input, $person);
        $groups = $facts->groupTotals();
        $statutory = is_array($person['statutory'] ?? null) ? $person['statutory'] : [];
        $social = is_array($statutory['social_insurance'] ?? null) ? $statutory['social_insurance'] : [];
        $net = is_array($statutory['net_pay'] ?? null) ? $statutory['net_pay'] : [];
        $deductions = 0;
        foreach (is_array($payslip['other_deduction_lines'] ?? null) ? $payslip['other_deduction_lines'] : [] as $line) {
            if (is_array($line) && is_int($line['amount_minor_units'] ?? null)) {
                $deductions += $line['amount_minor_units'];
            }
        }
        $name = is_string($payslip['employee_display_name'] ?? null)
            ? $payslip['employee_display_name']
            : (string) ($input['employee']['full_name'] ?? '');

        return [
            'employee_id' => (int) $person['employee_id'],
            'name' => $name,
            'personal_number' => $facts->employmentCodes === [] ? '' : implode(', ', $facts->employmentCodes),
            'to_account' => $facts->payoutAccountMasked !== null ? true : null,
            'worked_millihours' => $facts->time['worked_millihours'],
            'overtime_millihours' => $facts->time['overtime_millihours'],
            'gross_minor' => self::int($payslip, 'gross_minor_units'),
            'employee_social_minor' => self::int($payslip, 'employee_social_minor_units'),
            'pensioner_discount_minor' => self::int($social, 'working_pensioner_discount_minor_units') ?? 0,
            'employee_health_minor' => (self::int($payslip, 'employee_health_minor_units') ?? 0)
                + (self::int($payslip, 'health_minimum_top_up_minor_units') ?? 0),
            'tax_minor' => (self::int($payslip, 'tax_after_credits_minor_units') ?? 0)
                + (self::int($net, 'withholding_tax_minor_units') ?? 0),
            'reimbursements_minor' => $groups['reimbursements'],
            'deductions_minor' => $deductions,
            'wage_advance_minor' => null,
            'meal_allowance_minor' => $groups['meal_allowance'],
            'payable_minor' => self::int($payslip, 'net_minor_units'),
        ];
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $payslip
     * @param array<string,mixed> $row
     * @return array<string,?int>
     */
    private static function computedSummary(array $person, array $payslip, array $row): array
    {
        $statutory = is_array($person['statutory'] ?? null) ? $person['statutory'] : [];
        $social = is_array($statutory['social_insurance'] ?? null) ? $statutory['social_insurance'] : [];
        $health = is_array($statutory['health_insurance'] ?? null) ? $statutory['health_insurance'] : [];
        $tax = is_array($statutory['income_tax'] ?? null) ? $statutory['income_tax'] : [];
        $advance = is_array($tax['advance_tax'] ?? null) ? $tax['advance_tax'] : [];
        $net = is_array($statutory['net_pay'] ?? null) ? $statutory['net_pay'] : [];
        $employerSocial = self::int($payslip, 'employer_social_minor_units');
        $employerHealth = self::int($payslip, 'employer_health_minor_units');
        $payable = $row['payable_minor'];

        return [
            'social_base_minor' => self::int($social, 'capped_assessment_base_minor_units'),
            'social_employee_minor' => $row['employee_social_minor'],
            'social_pensioner_discount_minor' => $row['pensioner_discount_minor'],
            'social_employer_minor' => $employerSocial,
            'health_base_minor' => self::int($health, 'assessment_base_minor_units'),
            'health_employee_minor' => $row['employee_health_minor'],
            'health_employer_minor' => $employerHealth,
            'taxable_income_minor' => self::int($advance, 'taxable_income_minor_units') ?? 0,
            'tax_base_minor' => self::int($payslip, 'tax_base_minor_units'),
            'tax_before_credits_minor' => self::int($payslip, 'tax_before_credits_minor_units'),
            'tax_credits_applied_minor' => self::int($tax, 'applied_non_refundable_credits_minor_units') ?? 0,
            'child_credit_minor' => self::int($tax, 'applied_child_credit_minor_units') ?? 0,
            'advance_tax_minor' => self::int($payslip, 'tax_after_credits_minor_units'),
            'tax_bonus_minor' => self::int($payslip, 'tax_bonus_minor_units'),
            'withholding_base_minor' => self::int($tax, 'withholding_base_minor_units') ?? 0,
            'withholding_tax_minor' => self::int($net, 'withholding_tax_minor_units') ?? 0,
            'reimbursements_minor' => $row['reimbursements_minor'],
            'meal_allowance_minor' => $row['meal_allowance_minor'],
            'deductions_minor' => $row['deductions_minor'],
            'payout_account_minor' => $row['to_account'] === true ? $payable : 0,
            'payout_cash_minor' => $row['to_account'] === true ? 0 : $payable,
            'gross_minor' => $row['gross_minor'],
            'personal_costs_minor' => ($row['gross_minor'] ?? 0) + ($employerSocial ?? 0) + ($employerHealth ?? 0),
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:array<string,?int>} */
    private function fromTakenOver(int $supplierId, string $periodStart): array
    {
        $taken = $this->takenOver->readPeriod($supplierId, $periodStart);
        if ($taken === []) {
            return [[], self::emptySummary()];
        }
        $names = $this->employeeNames($supplierId, array_map(
            static fn (array $row): int => (int) $row['employee_id'],
            $taken,
        ));
        $rows = [];
        $summary = self::emptySummary();
        foreach ($taken as $item) {
            $employeeId = (int) $item['employee_id'];
            $employeeSocial = $item['employee_social_minor'];
            $employeeHealth = $item['employee_health_minor'];
            $row = [
                'employee_id' => $employeeId,
                'name' => $names[$employeeId]['name'] ?? ('#' . $employeeId),
                'personal_number' => $names[$employeeId]['codes'] ?? '',
                'to_account' => null,
                'worked_millihours' => is_int($item['worked_minutes']) && $item['worked_minutes'] > 0
                    ? (int) round($item['worked_minutes'] * 1000 / 60)
                    : null,
                'overtime_millihours' => null,
                'gross_minor' => $item['gross_minor'],
                'employee_social_minor' => $employeeSocial,
                'pensioner_discount_minor' => null,
                'employee_health_minor' => $employeeHealth,
                'tax_minor' => is_int($item['advance_tax_minor']) || is_int($item['withholding_tax_minor'])
                    ? (int) $item['advance_tax_minor'] + (int) $item['withholding_tax_minor']
                    : null,
                'reimbursements_minor' => null,
                'deductions_minor' => $item['deductions_minor'],
                'wage_advance_minor' => null,
                'meal_allowance_minor' => null,
                'payable_minor' => ($item['net_payable_minor'] ?? 0) !== 0 || ($item['net_minor'] ?? 0) === 0
                    ? $item['net_payable_minor']
                    : $item['net_minor'],
            ];
            $rows[] = $row;
            self::addSummary($summary, [
                'social_base_minor' => $item['social_base_minor'],
                'social_employee_minor' => $employeeSocial,
                'social_employer_minor' => $item['employer_social_minor'],
                'health_base_minor' => $item['health_base_minor'],
                'health_employee_minor' => $employeeHealth,
                'health_employer_minor' => $item['employer_health_minor'],
                'advance_tax_minor' => $item['advance_tax_minor'],
                'tax_bonus_minor' => $item['tax_bonus_minor'],
                'withholding_tax_minor' => $item['withholding_tax_minor'],
                'deductions_minor' => $item['deductions_minor'],
                'gross_minor' => $item['gross_minor'],
                'personal_costs_minor' => (int) $item['gross_minor']
                    + (int) $item['employer_social_minor']
                    + (int) $item['employer_health_minor'],
            ]);
        }

        return [$rows, $summary];
    }

    /** @return array<string,?int> */
    private static function emptySummary(): array
    {
        return array_fill_keys([
            'social_base_minor',
            'social_employee_minor',
            'social_pensioner_discount_minor',
            'social_employer_minor',
            'health_base_minor',
            'health_employee_minor',
            'health_employer_minor',
            'taxable_income_minor',
            'tax_base_minor',
            'tax_before_credits_minor',
            'tax_credits_applied_minor',
            'child_credit_minor',
            'advance_tax_minor',
            'tax_bonus_minor',
            'withholding_base_minor',
            'withholding_tax_minor',
            'reimbursements_minor',
            'meal_allowance_minor',
            'deductions_minor',
            'payout_account_minor',
            'payout_cash_minor',
            'gross_minor',
            'personal_costs_minor',
        ], null);
    }

    /**
     * @param array<string,?int> $summary
     * @param array<string,?int> $values
     */
    private static function addSummary(array &$summary, array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_int($value)) {
                $summary[$key] = ($summary[$key] ?? 0) + $value;
            }
        }
    }

    /**
     * Poslední schválená revize každého běhu období (ne převzatého).
     *
     * @return list<array{input:array<string,mixed>,result:array<string,mixed>}>
     */
    private function approvedRevisions(int $supplierId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.input_snapshot_json, revision.result_snapshot_json
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND run.period_start = ?
                AND revision.status = "approved"
                AND revision.result_snapshot_json IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions newer
                     WHERE newer.supplier_id = revision.supplier_id
                       AND newer.run_id = revision.run_id
                       AND newer.revision_no > revision.revision_no
                       AND newer.status = "approved"
                )
              ORDER BY run.id'
        );
        $statement->execute([$supplierId, $periodStart]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $input = json_decode((string) $row['input_snapshot_json'], true);
            $output = json_decode((string) $row['result_snapshot_json'], true);
            if (is_array($input) && is_array($output)) {
                $result[] = ['input' => $input, 'result' => $output];
            }
        }

        return $result;
    }

    /**
     * @param list<int> $employeeIds
     * @return array<int,array{name:string,codes:string}>
     */
    private function employeeNames(int $supplierId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT employee.id, employee.full_name,
                    GROUP_CONCAT(employment.code ORDER BY employment.start_date SEPARATOR ", ") AS codes
               FROM payroll_employees employee
               LEFT JOIN payroll_employments employment
                 ON employment.supplier_id = employee.supplier_id
                AND employment.employee_id = employee.id
              WHERE employee.supplier_id = ?
                AND employee.id IN (' . $placeholders . ')
              GROUP BY employee.id, employee.full_name'
        );
        $statement->execute([$supplierId, ...$employeeIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'name' => (string) $row['full_name'],
                'codes' => (string) ($row['codes'] ?? ''),
            ];
        }

        return $result;
    }

    /** @return array{name:string,identification_number:string} */
    private function employer(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name) AS name, ic
               FROM supplier
              WHERE id = ?'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Zaměstnavatel přehledu mezd neexistuje.');
        }

        return [
            'name' => trim((string) $row['name']),
            'identification_number' => trim((string) ($row['ic'] ?? '')),
        ];
    }

    /**
     * Jako přehled PAMICA: „Příjmení Jméno Titul“, řazené podle české abecedy
     * (Č za C, Ch za H, Ř za R, Š za S); shodná jména podle osobního čísla.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function ordered(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['name'] = PayrollPersonDisplayName::surnameFirst((string) $row['name']);
        }
        unset($row);
        usort($rows, static fn (array $a, array $b): int => PayrollPersonDisplayName::compare($a['name'], $b['name'])
            ?: strcmp((string) ($a['personal_number'] ?? ''), (string) ($b['personal_number'] ?? '')));

        return $rows;
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $key): ?int
    {
        return is_int($row[$key] ?? null) ? $row[$key] : null;
    }
}
