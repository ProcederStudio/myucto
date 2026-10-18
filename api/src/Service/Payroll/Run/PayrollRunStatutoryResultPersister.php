<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentIncomeTaxPolicy2026;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\Net\PayrollNetPolicyV1;
use MyInvoice\Service\Payroll\Net\PayrollNetResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus;

final class PayrollRunStatutoryResultPersister
{
    private const SAVEPOINT = 'payroll_statutory_result_persister';

    public function __construct(
        private readonly PayrollStatutoryResultRepository $repository,
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array<int,MonthlyEmploymentIncomeTaxResult|PayrollStatutoryBlockedPerson> $incomeTaxByEmployeeId
     * @param array<int,PayrollNetResult|PayrollStatutoryBlockedPerson> $netByEmployeeId
     * @return array{
     *     social_insurance:int,
     *     health_insurance:int,
     *     income_tax:int,
     *     net_pay:int
     * }
     */
    public function persist(
        int $supplierId,
        int $revisionId,
        ?int $actorUserId,
        array $inputSnapshot,
        SocialInsuranceMonthResult $socialInsurance,
        HealthInsuranceMonthResult $healthInsurance,
        array $incomeTaxByEmployeeId,
        array $netByEmployeeId,
    ): array {
        $snapshot = $this->indexSnapshot($supplierId, $inputSnapshot);
        $this->assertRevisionSnapshot(
            $supplierId,
            $revisionId,
            $inputSnapshot,
        );
        $sets = [
            'social_insurance' => $this->socialSet(
                $inputSnapshot,
                $snapshot,
                $socialInsurance,
            ),
            'health_insurance' => $this->healthSet(
                $inputSnapshot,
                $snapshot,
                $healthInsurance,
            ),
            'income_tax' => $this->taxSet(
                $inputSnapshot,
                $snapshot,
                $incomeTaxByEmployeeId,
            ),
            'net_pay' => $this->netSet(
                $inputSnapshot,
                $snapshot,
                $netByEmployeeId,
            ),
        ];

        return $this->transactional(function () use (
            $supplierId,
            $revisionId,
            $actorUserId,
            $sets,
        ): array {
            $ids = [];
            foreach ($sets as $kind => $set) {
                $ids[$kind] = $this->repository->store(
                    $supplierId,
                    $revisionId,
                    $kind,
                    $set['schema_version'],
                    $set['result_status'],
                    $set['ruleset_id'],
                    $set['ruleset_hash'],
                    $set['input_snapshot'],
                    $set['result_snapshot'],
                    $set['people'],
                    $actorUserId,
                );
            }

            return $ids;
        });
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @return array{
     *     people:array<int,array{
     *         input:array<string,mixed>,
     *         relationships:array<int,array<string,mixed>>
     *     }>,
     *     manifest:array<string,string>,
     *     statutory_period:array<string,string>
     * }
     */
    private function indexSnapshot(int $supplierId, array $inputSnapshot): array
    {
        if (($inputSnapshot['schema_version'] ?? null) !== 'payroll-run-input.v2') {
            throw new \DomainException(
                'Zákonné výsledky vyžadují zmrazený vstup payroll-run-input.v2.',
            );
        }
        if (($inputSnapshot['supplier_id'] ?? null) !== $supplierId) {
            throw new \DomainException('Zmrazený vstup patří jiné firmě.');
        }

        $statutoryPeriod = $this->statutoryPeriod(
            $inputSnapshot['statutory_period'] ?? null,
        );
        $manifest = $this->manifest($inputSnapshot['ruleset_manifest'] ?? null);
        $rawPeople = $inputSnapshot['people'] ?? null;
        if (!is_array($rawPeople) || !array_is_list($rawPeople) || $rawPeople === []) {
            throw new \DomainException(
                'Zmrazený vstup zákonného výpočtu neobsahuje žádnou osobu.',
            );
        }

        $people = [];
        $allRelationships = [];
        foreach ($rawPeople as $personIndex => $person) {
            if (!is_array($person) || array_is_list($person)) {
                throw new \DomainException(
                    "Zmrazená osoba {$personIndex} není objekt.",
                );
            }
            $employee = $person['employee'] ?? null;
            if (!is_array($employee) || array_is_list($employee)) {
                throw new \DomainException(
                    "Zmrazená osoba {$personIndex} nemá identitu zaměstnance.",
                );
            }
            $employeeId = $this->positiveInt(
                $employee['id'] ?? null,
                "people.{$personIndex}.employee.id",
            );
            if (isset($people[$employeeId])) {
                throw new \DomainException(
                    "Zmrazený vstup obsahuje osobu employee:{$employeeId} vícekrát.",
                );
            }
            $rawRelationships = $person['employments'] ?? null;
            if (!is_array($rawRelationships) || !array_is_list($rawRelationships)) {
                throw new \DomainException(
                    "Zmrazená osoba employee:{$employeeId} nemá seznam vztahů.",
                );
            }
            $relationships = [];
            foreach ($rawRelationships as $relationshipIndex => $relationship) {
                if (!is_array($relationship) || array_is_list($relationship)) {
                    throw new \DomainException(
                        "Zmrazený vztah {$relationshipIndex} není objekt.",
                    );
                }
                $employment = $relationship['employment'] ?? null;
                if (!is_array($employment) || array_is_list($employment)) {
                    throw new \DomainException(
                        "Zmrazený vztah {$relationshipIndex} nemá identitu.",
                    );
                }
                $employmentId = $this->positiveInt(
                    $employment['id'] ?? null,
                    "employment.{$relationshipIndex}.id",
                );
                if (($employment['employee_id'] ?? null) !== $employeeId) {
                    throw new \DomainException(
                        "Vztah employment:{$employmentId} patří jiné osobě.",
                    );
                }
                if (isset($allRelationships[$employmentId])) {
                    throw new \DomainException(
                        "Zmrazený vstup obsahuje vztah employment:{$employmentId} vícekrát.",
                    );
                }
                $allRelationships[$employmentId] = $employeeId;
                $relationships[$employmentId] = $relationship;
            }
            ksort($relationships, SORT_NUMERIC);
            $people[$employeeId] = [
                'input' => $person,
                'relationships' => $relationships,
            ];
        }
        ksort($people, SORT_NUMERIC);

        return [
            'people' => $people,
            'manifest' => $manifest,
            'statutory_period' => $statutoryPeriod,
        ];
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array{
     *     people:array<int,array{
     *         input:array<string,mixed>,
     *         relationships:array<int,array<string,mixed>>
     *     }>,
     *     manifest:array<string,string>,
     *     statutory_period:array<string,string>
     * } $snapshot
     * @return array<string,mixed>
     */
    private function socialSet(
        array $inputSnapshot,
        array $snapshot,
        SocialInsuranceMonthResult $result,
    ): array {
        $this->assertDate(
            $result->calculationDate,
            $snapshot['statutory_period']['social_calculation_date'],
            'sociálního pojištění',
        );
        $this->assertRuleset(
            $snapshot['manifest'],
            $result->rulesetId,
            $result->rulesetHash,
            'sociálního pojištění',
        );
        $rootStatus = $this->status($result->status);
        $serialized = $result->jsonSerialize();
        $people = [];
        $seen = [];
        foreach ($result->people as $person) {
            $employeeId = $this->referenceId(
                $person->personId,
                'employee',
                'osoby sociálního pojištění',
            );
            $frozen = $this->frozenPerson(
                $snapshot,
                $employeeId,
                $person->personId,
            );
            if (isset($seen[$employeeId])) {
                throw new \DomainException(
                    "Sociální výsledek obsahuje {$person->personId} vícekrát.",
                );
            }
            $seen[$employeeId] = true;
            $personStatus = $this->status($person->status);
            $this->assertIssuesDoNotHideStatus(
                $personStatus,
                $person->issues,
                $person->personId,
            );
            $relationships = [];
            $relationshipSeen = [];
            foreach ($person->relationships as $relationship) {
                $employmentId = $this->referenceId(
                    $relationship->relationshipId,
                    'employment',
                    'vztahu sociálního pojištění',
                );
                $relationshipInput = $this->frozenRelationship(
                    $frozen,
                    $employmentId,
                    $relationship->relationshipId,
                );
                if (isset($relationshipSeen[$employmentId])) {
                    throw new \DomainException(
                        "Sociální výsledek obsahuje {$relationship->relationshipId} vícekrát.",
                    );
                }
                $relationshipSeen[$employmentId] = true;
                $relationshipStatus =
                    $relationship->participation->status
                        === SocialParticipationStatus::ManualReview
                    ? 'manual_review'
                    : 'calculated';
                $this->assertNotHidden(
                    $personStatus,
                    $relationshipStatus,
                    "Osoba {$person->personId}",
                );
                $relationships[] = [
                    'employment_id' => $employmentId,
                    'input_snapshot' => $relationshipInput,
                    'result_snapshot' => $relationship->jsonSerialize(),
                    'result_status' => $relationshipStatus,
                ];
            }
            $this->assertCompleteRelationships(
                $frozen['relationships'],
                $relationshipSeen,
                $person->personId,
                'sociální',
            );
            $this->assertNotHidden(
                $rootStatus,
                $personStatus,
                'Souhrn sociálního pojištění',
            );
            $personResult = $person->jsonSerialize();
            unset($personResult['relationships']);
            $people[] = [
                'employee_id' => $employeeId,
                'input_snapshot' => $frozen['input'],
                'relationships' => $relationships,
                'result_snapshot' => $personResult,
                'result_status' => $personStatus,
            ];
        }
        $this->assertCompletePeople(
            $snapshot['people'],
            $seen,
            'sociálního pojištění',
        );
        $this->assertIssuesDoNotHideStatus(
            $rootStatus,
            $result->issues,
            'souhrn sociálního pojištění',
        );
        unset($serialized['people']);

        return $this->set(
            'payroll-social-result.v1',
            $rootStatus,
            $result->rulesetId,
            $result->rulesetHash,
            $inputSnapshot,
            $serialized,
            $people,
        );
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array{
     *     people:array<int,array{
     *         input:array<string,mixed>,
     *         relationships:array<int,array<string,mixed>>
     *     }>,
     *     manifest:array<string,string>,
     *     statutory_period:array<string,string>
     * } $snapshot
     * @return array<string,mixed>
     */
    private function healthSet(
        array $inputSnapshot,
        array $snapshot,
        HealthInsuranceMonthResult $result,
    ): array {
        $this->assertDate(
            $result->calculationDate,
            $snapshot['statutory_period']['health_calculation_date'],
            'zdravotního pojištění',
        );
        $this->assertRuleset(
            $snapshot['manifest'],
            $result->rulesetId,
            $result->rulesetHash,
            'zdravotního pojištění',
        );
        $rootStatus = $this->status($result->status);
        $serialized = $result->jsonSerialize();
        $people = [];
        $seen = [];
        foreach ($result->people as $person) {
            $employeeId = $this->referenceId(
                $person->personId,
                'employee',
                'osoby zdravotního pojištění',
            );
            $frozen = $this->frozenPerson(
                $snapshot,
                $employeeId,
                $person->personId,
            );
            if (isset($seen[$employeeId])) {
                throw new \DomainException(
                    "Zdravotní výsledek obsahuje {$person->personId} vícekrát.",
                );
            }
            $seen[$employeeId] = true;
            $personStatus = $this->status($person->status);
            $this->assertIssuesDoNotHideStatus(
                $personStatus,
                $person->issues,
                $person->personId,
            );
            $relationships = [];
            $relationshipSeen = [];
            foreach ($person->relationships as $relationship) {
                $employmentId = $this->referenceId(
                    $relationship->relationshipId,
                    'employment',
                    'vztahu zdravotního pojištění',
                );
                $relationshipInput = $this->frozenRelationship(
                    $frozen,
                    $employmentId,
                    $relationship->relationshipId,
                );
                if (isset($relationshipSeen[$employmentId])) {
                    throw new \DomainException(
                        "Zdravotní výsledek obsahuje {$relationship->relationshipId} vícekrát.",
                    );
                }
                $relationshipSeen[$employmentId] = true;
                $relationshipStatus =
                    $relationship->participation->status
                        === HealthParticipationStatus::ManualReview
                    ? 'manual_review'
                    : 'calculated';
                $this->assertNotHidden(
                    $personStatus,
                    $relationshipStatus,
                    "Osoba {$person->personId}",
                );
                $relationships[] = [
                    'employment_id' => $employmentId,
                    'input_snapshot' => $relationshipInput,
                    'result_snapshot' => $relationship->jsonSerialize(),
                    'result_status' => $relationshipStatus,
                ];
            }
            $this->assertCompleteRelationships(
                $frozen['relationships'],
                $relationshipSeen,
                $person->personId,
                'zdravotní',
            );
            $this->assertNotHidden(
                $rootStatus,
                $personStatus,
                'Souhrn zdravotního pojištění',
            );
            $personResult = $person->jsonSerialize();
            unset($personResult['relationships']);
            $people[] = [
                'employee_id' => $employeeId,
                'input_snapshot' => $frozen['input'],
                'relationships' => $relationships,
                'result_snapshot' => $personResult,
                'result_status' => $personStatus,
            ];
        }
        $this->assertCompletePeople(
            $snapshot['people'],
            $seen,
            'zdravotního pojištění',
        );
        $this->assertIssuesDoNotHideStatus(
            $rootStatus,
            $result->issues,
            'souhrn zdravotního pojištění',
        );
        unset($serialized['people']);

        return $this->set(
            'payroll-health-result.v1',
            $rootStatus,
            $result->rulesetId,
            $result->rulesetHash,
            $inputSnapshot,
            $serialized,
            $people,
        );
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array{
     *     people:array<int,array{
     *         input:array<string,mixed>,
     *         relationships:array<int,array<string,mixed>>
     *     }>,
     *     manifest:array<string,string>,
     *     statutory_period:array<string,string>
     * } $snapshot
     * @param array<int,MonthlyEmploymentIncomeTaxResult|PayrollStatutoryBlockedPerson> $results
     * @return array<string,mixed>
     */
    private function taxSet(
        array $inputSnapshot,
        array $snapshot,
        array $results,
    ): array {
        $this->assertCompleteMap($snapshot['people'], $results, 'daně z příjmů');
        $canonicalPolicyHash = EmploymentIncomeTaxPolicy2026::contractHash();
        $rulesetId = null;
        $rulesetHash = null;
        $policyId = null;
        $policyHash = null;
        $rootStatus = 'calculated';
        $people = [];
        $personReferences = [];
        $advanceTax = 0;
        $withholdingTax = 0;
        $taxBonus = 0;
        foreach ($results as $employeeId => $result) {
            $employeeId = $this->positiveInt($employeeId, 'income_tax.employee_id');
            $frozen = $snapshot['people'][$employeeId];
            if ($result instanceof PayrollStatutoryBlockedPerson) {
                if ($result->rulesetId === null) {
                    throw new \DomainException(
                        'Blokovaný daňový výsledek musí nést identitu pravidel a politiky.',
                    );
                }
                $personReference = $result->personReference;
                $entryRulesetId = $result->rulesetId;
                $entryRulesetHash = (string) $result->rulesetHash;
                $entryPolicyId = (string) $result->policyId;
                $entryPolicyHash = (string) $result->policyHash;
                $personStatus = $result->status;
                $personResult = $result->jsonSerialize();
                $relationships = $this->blockedRelationships(
                    $frozen['relationships'],
                    $result,
                );
            } elseif ($result instanceof MonthlyEmploymentIncomeTaxResult) {
                $personReference = $result->employeeReference;
                $entryRulesetId = $result->rulesetId;
                $entryRulesetHash = $result->rulesetHash;
                $entryPolicyId = $result->policyId;
                $entryPolicyHash = $result->policyHash;
                $personStatus = $this->status($result->status);
                $this->assertDate(
                    $result->calculationDate,
                    $snapshot['statutory_period']['tax_calculation_date'],
                    "daně osoby {$personReference}",
                );
                $this->assertIssuesDoNotHideStatus(
                    $personStatus,
                    $result->issues,
                    $personReference,
                );
                if ($result->payerReference !== "supplier:{$inputSnapshot['supplier_id']}") {
                    throw new \DomainException(
                        "Daňový výsledek {$personReference} patří jinému plátci.",
                    );
                }
                if ($result->advanceTax !== null
                    && ($result->advanceTax->rulesetId !== $entryRulesetId
                        || $result->advanceTax->rulesetHash !== $entryRulesetHash)
                ) {
                    throw new \DomainException(
                        "Daňový výsledek {$personReference} míchá různé sady pravidel.",
                    );
                }
                if ($personStatus === 'calculated' && $result->advanceTax === null) {
                    throw new \DomainException(
                        "Vypočtený daňový výsledek {$personReference} nemá výpočet zálohy.",
                    );
                }
                $relationships = [];
                $relationshipSeen = [];
                foreach ($result->relationships as $relationship) {
                    $employmentId = $this->referenceId(
                        $relationship->relationshipReference,
                        'employment',
                        'daňového vztahu',
                    );
                    $relationshipInput = $this->frozenRelationship(
                        $frozen,
                        $employmentId,
                        $relationship->relationshipReference,
                    );
                    if (isset($relationshipSeen[$employmentId])) {
                        throw new \DomainException(
                            "Daňový výsledek obsahuje {$relationship->relationshipReference} vícekrát.",
                        );
                    }
                    $relationshipSeen[$employmentId] = true;
                    $relationshipStatus =
                        $relationship->regime === TaxRegime::ManualReview
                        ? 'manual_review'
                        : 'calculated';
                    $this->assertNotHidden(
                        $personStatus,
                        $relationshipStatus,
                        "Daň osoby {$personReference}",
                    );
                    $relationships[] = [
                        'employment_id' => $employmentId,
                        'input_snapshot' => $relationshipInput,
                        'result_snapshot' => $relationship->jsonSerialize(),
                        'result_status' => $relationshipStatus,
                    ];
                }
                $this->assertCompleteRelationships(
                    $frozen['relationships'],
                    $relationshipSeen,
                    $personReference,
                    'daňový',
                );
                $personResult = $result->jsonSerialize();
                unset($personResult['relationships']);
                if ($personStatus === 'calculated') {
                    $advanceResult = $result->advanceTax;
                    $advanceTax = $this->addMinorUnits(
                        $advanceTax,
                        $advanceResult->taxAfterCreditsMinorUnits,
                    );
                    $withholdingTax = $this->addMinorUnits(
                        $withholdingTax,
                        $result->withholdingTaxMinorUnits,
                    );
                    $taxBonus = $this->addMinorUnits(
                        $taxBonus,
                        $advanceResult->taxBonusMinorUnits,
                    );
                }
            } else {
                throw new \InvalidArgumentException(
                    "Daňový výsledek employee:{$employeeId} má nepodporovaný typ.",
                );
            }
            $actualEmployeeId = $this->referenceId(
                $personReference,
                'employee',
                'osoby daně z příjmů',
            );
            if ($actualEmployeeId !== $employeeId) {
                throw new \DomainException(
                    "Daňový výsledek {$personReference} neodpovídá klíči employee:{$employeeId}.",
                );
            }
            $this->assertRuleset(
                $snapshot['manifest'],
                $entryRulesetId,
                $entryRulesetHash,
                "daně osoby {$personReference}",
            );
            if ($entryPolicyId !== EmploymentIncomeTaxPolicy2026::ID
                || $entryPolicyHash !== $canonicalPolicyHash
            ) {
                throw new \DomainException(
                    "Výsledek {$personReference} nemá kanonickou identitu daňové politiky.",
                );
            }
            $this->assertSharedIdentity(
                $rulesetId,
                $rulesetHash,
                $policyId,
                $policyHash,
                $entryRulesetId,
                $entryRulesetHash,
                $entryPolicyId,
                $entryPolicyHash,
                'daňové',
            );
            $rootStatus = $this->worseStatus($rootStatus, $personStatus);
            $personReferences[] = $personReference;
            $people[] = [
                'employee_id' => $employeeId,
                'input_snapshot' => $frozen['input'],
                'relationships' => $relationships,
                'result_snapshot' => $personResult,
                'result_status' => $personStatus,
            ];
        }
        $rulesetId = $this->requiredIdentity($rulesetId, 'daňových pravidel');
        $rulesetHash = $this->requiredIdentity($rulesetHash, 'otisku daňových pravidel');
        $policyId = $this->requiredIdentity($policyId, 'daňové politiky');
        $policyHash = $this->requiredIdentity($policyHash, 'otisku daňové politiky');
        sort($personReferences, SORT_STRING);

        return $this->set(
            'payroll-income-tax-result.v1',
            $rootStatus,
            $rulesetId,
            $rulesetHash,
            $inputSnapshot,
            [
                'advance_tax_minor_units' =>
                    $rootStatus === 'calculated' ? $advanceTax : null,
                'people' => $personReferences,
                'policy_hash' => $policyHash,
                'policy_id' => $policyId,
                'ruleset_hash' => $rulesetHash,
                'ruleset_id' => $rulesetId,
                'status' => $rootStatus,
                'tax_bonus_minor_units' =>
                    $rootStatus === 'calculated' ? $taxBonus : null,
                'withholding_tax_minor_units' =>
                    $rootStatus === 'calculated' ? $withholdingTax : null,
            ],
            $people,
        );
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array{
     *     people:array<int,array{
     *         input:array<string,mixed>,
     *         relationships:array<int,array<string,mixed>>
     *     }>,
     *     manifest:array<string,string>,
     *     statutory_period:array<string,string>
     * } $snapshot
     * @param array<int,PayrollNetResult|PayrollStatutoryBlockedPerson> $results
     * @return array<string,mixed>
     */
    private function netSet(
        array $inputSnapshot,
        array $snapshot,
        array $results,
    ): array {
        $this->assertCompleteMap($snapshot['people'], $results, 'čisté mzdy');
        $policy = PayrollNetPolicyV1::create();
        $rootStatus = 'calculated';
        $netPayable = 0;
        $people = [];
        $personReferences = [];
        foreach ($results as $employeeId => $result) {
            $employeeId = $this->positiveInt($employeeId, 'net.employee_id');
            $frozen = $snapshot['people'][$employeeId];
            if ($result instanceof PayrollStatutoryBlockedPerson) {
                if ($result->rulesetId !== null) {
                    throw new \DomainException(
                        'Blokace čisté mzdy nesmí podvrhnout vlastní identitu pravidel.',
                    );
                }
                $personReference = $result->personReference;
                $personStatus = $result->status;
                $personResult = $result->jsonSerialize();
                $relationships = $this->blockedRelationships(
                    $frozen['relationships'],
                    $result,
                );
            } elseif ($result instanceof PayrollNetResult) {
                $personReference = $result->personReference;
                $personStatus = 'calculated';
                $this->assertNetInvariants($result);
                $relationships = [];
                $relationshipSeen = [];
                foreach ($result->relationships as $relationship) {
                    $employmentId = $this->referenceId(
                        $relationship->relationshipReference,
                        'employment',
                        'vztahu čisté mzdy',
                    );
                    $relationshipInput = $this->frozenRelationship(
                        $frozen,
                        $employmentId,
                        $relationship->relationshipReference,
                    );
                    if (isset($relationshipSeen[$employmentId])) {
                        throw new \DomainException(
                            "Čistá mzda obsahuje {$relationship->relationshipReference} vícekrát.",
                        );
                    }
                    $relationshipSeen[$employmentId] = true;
                    $relationships[] = [
                        'employment_id' => $employmentId,
                        'input_snapshot' => $relationshipInput,
                        'result_snapshot' => $relationship->jsonSerialize(),
                        'result_status' => 'calculated',
                    ];
                }
                $this->assertCompleteRelationships(
                    $frozen['relationships'],
                    $relationshipSeen,
                    $personReference,
                    'čisté mzdy',
                );
                $personResult = $result->jsonSerialize();
                unset($personResult['relationships']);
                $netPayable = $this->addMinorUnits(
                    $netPayable,
                    $result->netPayableMinorUnits,
                );
            } else {
                throw new \InvalidArgumentException(
                    "Výsledek čisté mzdy employee:{$employeeId} má nepodporovaný typ.",
                );
            }
            $actualEmployeeId = $this->referenceId(
                $personReference,
                'employee',
                'osoby čisté mzdy',
            );
            if ($actualEmployeeId !== $employeeId) {
                throw new \DomainException(
                    "Čistá mzda {$personReference} neodpovídá klíči employee:{$employeeId}.",
                );
            }
            $rootStatus = $this->worseStatus($rootStatus, $personStatus);
            $personReferences[] = $personReference;
            $people[] = [
                'employee_id' => $employeeId,
                'input_snapshot' => $frozen['input'],
                'relationships' => $relationships,
                'result_snapshot' => $personResult,
                'result_status' => $personStatus,
            ];
        }
        sort($personReferences, SORT_STRING);

        return $this->set(
            'payroll-net-result.v1',
            $rootStatus,
            $policy->id,
            $policy->canonicalHash,
            $inputSnapshot,
            [
                'net_payable_minor_units' =>
                    $rootStatus === 'calculated' ? $netPayable : null,
                'people' => $personReferences,
                'policy_hash' => $policy->canonicalHash,
                'policy_id' => $policy->id,
                'status' => $rootStatus,
            ],
            $people,
        );
    }

    /** @param array<string,mixed> $inputSnapshot */
    private function assertRevisionSnapshot(
        int $supplierId,
        int $revisionId,
        array $inputSnapshot,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT schema_version, input_snapshot_json, input_snapshot_hash
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $revisionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException(
                'Mzdová revize neexistuje nebo patří jiné firmě.',
            );
        }
        $schemaVersion = $row['schema_version'] ?? null;
        $storedJson = $row['input_snapshot_json'] ?? null;
        $storedHash = $row['input_snapshot_hash'] ?? null;
        if (!is_string($schemaVersion)
            || !is_string($storedJson)
            || !is_string($storedHash)
            || preg_match('/^[0-9a-f]{64}$/D', $storedHash) !== 1
        ) {
            throw new \DomainException(
                'Mzdová revize nemá platný zmrazený vstup.',
            );
        }
        $inputJson = CanonicalJson::encode($inputSnapshot);
        $inputHash = hash('sha256', $inputJson);
        if ($schemaVersion !== ($inputSnapshot['schema_version'] ?? null)
            || !hash_equals($storedHash, $inputHash)
            || !hash_equals($storedHash, hash('sha256', $storedJson))
        ) {
            throw new \DomainException(
                'Předaný zmrazený vstup neodpovídá mzdové revizi.',
            );
        }
    }

    private function assertNetInvariants(PayrollNetResult $result): void
    {
        $cash = 0;
        $nonCash = 0;
        foreach ($result->relationships as $relationship) {
            $cash = $this->addMinorUnits(
                $cash,
                $relationship->cashIncomeMinorUnits,
            );
            $nonCash = $this->addMinorUnits(
                $nonCash,
                $relationship->nonCashIncomeMinorUnits,
            );
        }
        if ($cash !== $result->cashIncomeMinorUnits
            || $nonCash !== $result->nonCashIncomeMinorUnits
        ) {
            throw new \DomainException(
                "Čistá mzda {$result->personReference} nesouhlasí s rozpadem vztahů.",
            );
        }

        $netBeforeDeductions = $this->addMinorUnits(
            $cash,
            $result->correctionMinorUnits,
        );
        foreach ([
            $result->employeeSocialMinorUnits,
            $result->employeeHealthMinorUnits,
            $result->advanceTaxMinorUnits,
            $result->withholdingTaxMinorUnits,
        ] as $deduction) {
            $netBeforeDeductions = $this->subtractMinorUnits(
                $netBeforeDeductions,
                $deduction,
            );
        }
        $netBeforeDeductions = $this->addMinorUnits(
            $netBeforeDeductions,
            $result->taxBonusMinorUnits,
        );
        if ($netBeforeDeductions !== $result->netBeforeDeductionsMinorUnits) {
            throw new \DomainException(
                "Čistá mzda {$result->personReference} neodpovídá politice výpočtu.",
            );
        }

        $deducted = 0;
        foreach ($result->deductions as $deduction) {
            $deducted = $this->addMinorUnits(
                $deducted,
                $deduction->appliedMinorUnits,
            );
        }
        if ($deducted !== $result->deductedMinorUnits
            || $this->addMinorUnits(
                $this->subtractMinorUnits($netBeforeDeductions, $deducted),
                $result->annualSettlementMinorUnits,
            ) !== $result->netPayableMinorUnits
        ) {
            throw new \DomainException(
                "Čistá mzda {$result->personReference} nesouhlasí se srážkami.",
            );
        }
    }

    private function addMinorUnits(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException('Součet mzdových částek přetekl.');
        }

        return $left + $right;
    }

    private function subtractMinorUnits(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException('Rozdíl mzdových částek přetekl.');
        }

        return $left - $right;
    }

    /**
     * @param array<int,array<string,mixed>> $relationships
     * @return list<array<string,mixed>>
     */
    private function blockedRelationships(
        array $relationships,
        PayrollStatutoryBlockedPerson $blocked,
    ): array {
        $result = [];
        foreach ($relationships as $employmentId => $input) {
            $result[] = [
                'employment_id' => $employmentId,
                'input_snapshot' => $input,
                'result_snapshot' => [
                    'blocked_by_person' => true,
                    'issues' => $blocked->issues,
                    'person_reference' => $blocked->personReference,
                    'status' => $blocked->status,
                ],
                'result_status' => $blocked->status,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,string> $manifest
     */
    private function assertRuleset(
        array $manifest,
        string $rulesetId,
        string $rulesetHash,
        string $context,
    ): void {
        if (($manifest[$rulesetId] ?? null) !== $rulesetHash) {
            throw new \DomainException(
                "Výsledek {$context} neodpovídá zmrazenému manifestu pravidel.",
            );
        }
    }

    private function assertDate(
        string $actual,
        string $expected,
        string $context,
    ): void {
        if ($actual !== $expected) {
            throw new \DomainException(
                "Výsledek {$context} má jiné zákonné datum než zmrazený vstup.",
            );
        }
    }

    /**
     * @param array{
     *     people:array<int,array{
     *         input:array<string,mixed>,
     *         relationships:array<int,array<string,mixed>>
     *     }>,
     *     manifest:array<string,string>,
     *     statutory_period:array<string,string>
     * } $snapshot
     * @return array{
     *     input:array<string,mixed>,
     *     relationships:array<int,array<string,mixed>>
     * }
     */
    private function frozenPerson(
        array $snapshot,
        int $employeeId,
        string $reference,
    ): array {
        $person = $snapshot['people'][$employeeId] ?? null;
        if ($person === null) {
            throw new \DomainException(
                "Výsledek obsahuje cizí osobu {$reference}.",
            );
        }

        return $person;
    }

    /**
     * @param array{
     *     input:array<string,mixed>,
     *     relationships:array<int,array<string,mixed>>
     * } $person
     * @return array<string,mixed>
     */
    private function frozenRelationship(
        array $person,
        int $employmentId,
        string $reference,
    ): array {
        $relationship = $person['relationships'][$employmentId] ?? null;
        if ($relationship === null) {
            throw new \DomainException(
                "Výsledek obsahuje cizí vztah {$reference}.",
            );
        }

        return $relationship;
    }

    /**
     * @param array<int,array<string,mixed>> $expected
     * @param array<int,bool> $actual
     */
    private function assertCompleteRelationships(
        array $expected,
        array $actual,
        string $personReference,
        string $context,
    ): void {
        $expectedIds = array_keys($expected);
        $actualIds = array_keys($actual);
        sort($expectedIds, SORT_NUMERIC);
        sort($actualIds, SORT_NUMERIC);
        if ($expectedIds !== $actualIds) {
            throw new \DomainException(
                "{$context} výsledek {$personReference} nepokrývá přesně zmrazené vztahy.",
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $expected
     * @param array<int,bool> $actual
     */
    private function assertCompletePeople(
        array $expected,
        array $actual,
        string $context,
    ): void {
        $expectedIds = array_keys($expected);
        $actualIds = array_keys($actual);
        sort($expectedIds, SORT_NUMERIC);
        sort($actualIds, SORT_NUMERIC);
        if ($expectedIds !== $actualIds) {
            throw new \DomainException(
                "Výsledek {$context} nepokrývá přesně zmrazené osoby.",
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $expected
     * @param array<int,mixed> $actual
     */
    private function assertCompleteMap(
        array $expected,
        array $actual,
        string $context,
    ): void {
        $actualKeys = [];
        foreach ($actual as $employeeId => $_result) {
            $actualKeys[] = $this->positiveInt(
                $employeeId,
                "{$context}.employee_id",
            );
        }
        sort($actualKeys, SORT_NUMERIC);
        if (array_keys($expected) !== $actualKeys) {
            throw new \DomainException(
                "Mapa {$context} nepokrývá přesně zmrazené osoby.",
            );
        }
    }

    /**
     * @param-out string $rulesetId
     * @param-out string $rulesetHash
     * @param-out string $policyId
     * @param-out string $policyHash
     */
    private function assertSharedIdentity(
        ?string &$rulesetId,
        ?string &$rulesetHash,
        ?string &$policyId,
        ?string &$policyHash,
        string $entryRulesetId,
        string $entryRulesetHash,
        string $entryPolicyId,
        string $entryPolicyHash,
        string $context,
    ): void {
        if ($rulesetId === null) {
            $rulesetId = $entryRulesetId;
            $rulesetHash = $entryRulesetHash;
            $policyId = $entryPolicyId;
            $policyHash = $entryPolicyHash;

            return;
        }
        if ($rulesetId !== $entryRulesetId
            || $rulesetHash !== $entryRulesetHash
            || $policyId !== $entryPolicyId
            || $policyHash !== $entryPolicyHash
        ) {
            throw new \DomainException(
                "Výsledky {$context} míchají různé sady pravidel nebo politiky.",
            );
        }
    }

    private function requiredIdentity(?string $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new \DomainException(
                "Výsledek nemá platnou identitu {$field}.",
            );
        }

        return $value;
    }

    /**
     * @param SocialCalculationStatus|HealthCalculationStatus|TaxCalculationStatus $status
     */
    private function status(
        SocialCalculationStatus|HealthCalculationStatus|TaxCalculationStatus $status,
    ): string {
        return $status->value === 'calculated'
            ? 'calculated'
            : 'manual_review';
    }

    /** @param list<string> $issues */
    private function assertIssuesDoNotHideStatus(
        string $status,
        array $issues,
        string $context,
    ): void {
        if ($issues !== [] && $status === 'calculated') {
            throw new \DomainException(
                "Výsledek {$context} skrývá závažnější stav vyžadující ruční kontrolu.",
            );
        }
    }

    private function assertNotHidden(
        string $parentStatus,
        string $childStatus,
        string $context,
    ): void {
        if ($this->statusRank($childStatus) > $this->statusRank($parentStatus)) {
            throw new \DomainException(
                "{$context} nesmí skrýt závažnější stav podřízeného výsledku.",
            );
        }
    }

    private function worseStatus(string $left, string $right): string
    {
        return $this->statusRank($right) > $this->statusRank($left)
            ? $right
            : $left;
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            'calculated' => 0,
            'manual_review' => 1,
            'error' => 2,
            default => throw new \InvalidArgumentException(
                'Nepodporovaný stav zákonného výsledku.',
            ),
        };
    }

    /**
     * @return array<string,string>
     */
    private function statutoryPeriod(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException(
                'Zmrazený vstup nemá zákonné období.',
            );
        }
        $result = [];
        foreach ([
            'period_start',
            'period_end',
            'payment_date',
            'tax_calculation_date',
            'social_calculation_date',
            'health_calculation_date',
        ] as $field) {
            $date = $value[$field] ?? null;
            $parsed = is_string($date)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date)
                : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new \DomainException(
                    "Zmrazené zákonné období nemá platné pole {$field}.",
                );
            }
            $result[$field] = $date;
        }

        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function manifest(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new \DomainException(
                'Zmrazený vstup nemá manifest pravidel.',
            );
        }
        $manifest = [];
        foreach ($value as $index => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new \DomainException(
                    "Položka manifestu {$index} není objekt.",
                );
            }
            $id = $entry['id'] ?? null;
            $hash = $entry['sha256'] ?? null;
            if (!is_string($id) || $id === ''
                || !is_string($hash)
                || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
            ) {
                throw new \DomainException(
                    "Položka manifestu {$index} nemá platnou identitu.",
                );
            }
            if (isset($manifest[$id])) {
                throw new \DomainException(
                    "Manifest obsahuje sadu {$id} vícekrát.",
                );
            }
            $manifest[$id] = $hash;
        }

        return $manifest;
    }

    private function referenceId(
        string $reference,
        string $prefix,
        string $context,
    ): int {
        if (preg_match(
            '/^' . preg_quote($prefix, '/') . ':([1-9][0-9]*)$/D',
            $reference,
            $matches,
        ) !== 1) {
            throw new \DomainException(
                "Identifikátor {$context} není kanonický {$prefix}:{id}.",
            );
        }

        return $this->positiveInt($matches[1], $context);
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && (string) (int) $value === $value
        ) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException(
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array<string,mixed> $resultSnapshot
     * @param list<array<string,mixed>> $people
     * @return array<string,mixed>
     */
    private function set(
        string $schemaVersion,
        string $resultStatus,
        string $rulesetId,
        string $rulesetHash,
        array $inputSnapshot,
        array $resultSnapshot,
        array $people,
    ): array {
        return [
            'schema_version' => $schemaVersion,
            'result_status' => $resultStatus,
            'ruleset_id' => $rulesetId,
            'ruleset_hash' => $rulesetHash,
            'input_snapshot' => $inputSnapshot,
            'result_snapshot' => $resultSnapshot,
            'people' => $people,
        ];
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
