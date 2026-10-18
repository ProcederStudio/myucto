<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorUnavailableException;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;

final class PayrollRunStatutoryAccumulatorApprover
{
    private const SAVEPOINT = 'payroll_statutory_accumulator_approval';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollStatutoryResultRepository $results,
        private readonly PayrollStatutoryAccumulatorRepository $accumulators,
    ) {}

    public function approve(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        if ($supplierId <= 0 || $revisionId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize a schvalovatel musí mít platná ID.',
            );
        }

        $this->transactional(function () use (
            $supplierId,
            $revisionId,
            $actorUserId,
        ): void {
            $this->approveSocialInsurance(
                $supplierId,
                $revisionId,
                $actorUserId,
            );
            $this->approveHealthInsurance(
                $supplierId,
                $revisionId,
                $actorUserId,
            );
            $this->approveIncomeTax(
                $supplierId,
                $revisionId,
                $actorUserId,
            );
        });
    }

    private function approveSocialInsurance(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        foreach (
            $this->calculatedPeople(
                $supplierId,
                $revisionId,
                'social_insurance',
            ) as $person
        ) {
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                'social_insurance.employee_id',
            );
            $result = $this->object(
                $person['result_snapshot'] ?? null,
                'social_insurance.result_snapshot',
            );
            $this->assertPersonReference(
                $result['person_id'] ?? null,
                $employeeId,
                'social_insurance.person_id',
            );
            $this->accumulators->appendApprovedResult(
                $supplierId,
                $revisionId,
                $employeeId,
                'social_insurance',
                [
                    'assessment_base_minor_units' => $this->nonNegativeInt(
                        $result[
                            'capped_assessment_base_minor_units'
                        ] ?? null,
                        'social_insurance.capped_assessment_base_minor_units',
                    ),
                ],
                $this->hash(
                    $person['result_snapshot_hash'] ?? null,
                    'social_insurance.result_snapshot_hash',
                ),
                $actorUserId,
            );
        }
    }

    /**
     * Roční kumulace zdravotního pojištění.
     *
     * Do měsíčního výpočtu z ní nic nevstupuje (ZP nemá roční strop ani roční
     * slevu), ale bez ní není proti čemu srovnat roční přehledy pojišťoven —
     * a zákazníkovi, který přešel uprostřed roku, by chyběla ta část roku,
     * kterou zpracoval jinde.
     *
     * Základ je ten VYKÁZANÝ: při dopočtu do minima se pojišťovně hlásí
     * minimum, ne skutečný příjem, takže roční úhrn musí sčítat totéž číslo,
     * jaké šlo v přehledech ven.
     */
    private function approveHealthInsurance(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        foreach (
            $this->calculatedPeople(
                $supplierId,
                $revisionId,
                'health_insurance',
            ) as $person
        ) {
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                'health_insurance.employee_id',
            );
            $result = $this->object(
                $person['result_snapshot'] ?? null,
                'health_insurance.result_snapshot',
            );
            $this->assertPersonReference(
                $result['person_id'] ?? null,
                $employeeId,
                'health_insurance.person_id',
            );
            $employeeTopUp = $this->nonNegativeInt(
                $result['employee_minimum_top_up_minor_units'] ?? null,
                'health_insurance.employee_minimum_top_up_minor_units',
            );
            $employerTopUp = $this->nonNegativeInt(
                $result['employer_minimum_top_up_minor_units'] ?? null,
                'health_insurance.employer_minimum_top_up_minor_units',
            );
            $this->accumulators->appendApprovedResult(
                $supplierId,
                $revisionId,
                $employeeId,
                'health_insurance',
                [
                    'assessment_base_minor_units' => $this->nonNegativeInt(
                        $result['ppz_assessment_base_minor_units'] ?? null,
                        'health_insurance.ppz_assessment_base_minor_units',
                    ),
                    'employee_contribution_minor_units' => $this->nonNegativeInt(
                        $result['employee_contribution_minor_units'] ?? null,
                        'health_insurance.employee_contribution_minor_units',
                    ),
                    'employer_contribution_minor_units' => $this->nonNegativeInt(
                        $result['employer_contribution_minor_units'] ?? null,
                        'health_insurance.employer_contribution_minor_units',
                    ),
                    'minimum_top_up_minor_units' => $employeeTopUp + $employerTopUp,
                ],
                $this->hash(
                    $person['result_snapshot_hash'] ?? null,
                    'health_insurance.result_snapshot_hash',
                ),
                $actorUserId,
            );
        }
    }

    private function approveIncomeTax(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        foreach (
            $this->calculatedPeople(
                $supplierId,
                $revisionId,
                'income_tax',
            ) as $person
        ) {
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                'income_tax.employee_id',
            );
            $result = $this->object(
                $person['result_snapshot'] ?? null,
                'income_tax.result_snapshot',
            );
            $this->assertPersonReference(
                $result['employee_reference'] ?? null,
                $employeeId,
                'income_tax.employee_reference',
            );
            $advance = $result['advance_tax'] ?? null;
            if ($advance !== null && (!is_array($advance) || array_is_list($advance))) {
                throw new \UnexpectedValueException(
                    'income_tax.advance_tax musí být objekt nebo null.',
                );
            }
            $advanceBase = $advance === null
                ? 0
                : $this->nonNegativeInt(
                    $advance['taxable_income_minor_units'] ?? null,
                    'income_tax.advance_tax.taxable_income_minor_units',
                );
            $advanceTax = $advance === null
                ? 0
                : $this->nonNegativeInt(
                    $advance['tax_after_credits_minor_units'] ?? null,
                    'income_tax.advance_tax.tax_after_credits_minor_units',
                );
            $taxBonus = $advance === null
                ? 0
                : $this->nonNegativeInt(
                    $advance['tax_bonus_minor_units'] ?? null,
                    'income_tax.advance_tax.tax_bonus_minor_units',
                );
            $this->accumulators->appendApprovedResult(
                $supplierId,
                $revisionId,
                $employeeId,
                'income_tax',
                [
                    'completed_months' => $advance === null ? 0 : 1,
                    'advance_base_minor_units' => $advanceBase,
                    'withholding_base_minor_units' => $this->nonNegativeInt(
                        $result['withholding_base_minor_units'] ?? null,
                        'income_tax.withholding_base_minor_units',
                    ),
                    'advance_tax_minor_units' => $advanceTax,
                    'withholding_tax_minor_units' => $this->nonNegativeInt(
                        $result['withholding_tax_minor_units'] ?? null,
                        'income_tax.withholding_tax_minor_units',
                    ),
                    'applied_non_refundable_credits_minor_units' =>
                        $this->nonNegativeInt(
                            $result[
                                'applied_non_refundable_credits_minor_units'
                            ] ?? null,
                            'income_tax.applied_non_refundable_credits_minor_units',
                        ),
                    'applied_child_credit_minor_units' => $this->nonNegativeInt(
                        $result['applied_child_credit_minor_units'] ?? null,
                        'income_tax.applied_child_credit_minor_units',
                    ),
                    'tax_bonus_minor_units' => $taxBonus,
                    'bonus_qualifying_income_minor_units' => $advanceBase,
                ],
                $this->hash(
                    $person['result_snapshot_hash'] ?? null,
                    'income_tax.result_snapshot_hash',
                ),
                $actorUserId,
            );
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function calculatedPeople(
        int $supplierId,
        int $revisionId,
        string $calculationKind,
    ): array {
        $result = $this->results->find(
            $supplierId,
            $revisionId,
            $calculationKind,
        );
        if ($result === null) {
            throw new PayrollStatutoryAccumulatorUnavailableException(
                "Revize nemá neměnný výsledek {$calculationKind}.",
            );
        }
        if (($result['result_status'] ?? null) !== 'calculated') {
            throw new PayrollStatutoryAccumulatorUnavailableException(
                "Výsledek {$calculationKind} není vypočtený.",
            );
        }
        $people = $result['people'] ?? null;
        if (!is_array($people) || !array_is_list($people) || $people === []) {
            throw new PayrollStatutoryAccumulatorUnavailableException(
                "Výsledek {$calculationKind} neobsahuje osoby.",
            );
        }
        foreach ($people as $index => $person) {
            if (!is_array($person) || array_is_list($person)) {
                throw new \UnexpectedValueException(
                    "Výsledek {$calculationKind} osoby {$index} není objekt.",
                );
            }
            if (($person['result_status'] ?? null) !== 'calculated') {
                throw new PayrollStatutoryAccumulatorUnavailableException(
                    "Výsledek {$calculationKind} osoby vyžaduje ruční kontrolu.",
                );
            }
        }

        return $people;
    }

    private function assertPersonReference(
        mixed $reference,
        int $employeeId,
        string $field,
    ): void {
        if ($reference !== "employee:{$employeeId}") {
            throw new \DomainException(
                "{$field} neodpovídá zaměstnanci neměnného výsledku.",
            );
        }
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException(
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException(
                "{$field} musí být nezáporné celé číslo.",
            );
        }

        return $value;
    }

    private function hash(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1
        ) {
            throw new \UnexpectedValueException(
                "{$field} musí být SHA-256 hash.",
            );
        }

        return $value;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
