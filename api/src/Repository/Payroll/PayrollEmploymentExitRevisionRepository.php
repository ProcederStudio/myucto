<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\EmploymentExitReadinessException;
use PDO;
use PDOException;

final class PayrollEmploymentExitRevisionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   employment:array<string,mixed>,
     *   employee:array<string,mixed>,
     *   identity:array<string,mixed>,
     *   address:array<string,mixed>,
     *   terms:array<string,mixed>
     * }
     */
    public function lockCertificateSources(
        int $supplierId,
        int $employmentId,
    ): array {
        $this->requireTransaction();
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.supplier_id,
                    employment.employee_id, employment.relation_type,
                    employment.status, employment.start_date,
                    employment.actual_start_date, employment.end_date,
                    employment.is_legacy_projection, employment.row_version,
                    employee.birth_date, DATE(NOW()) AS database_today,
                    (
                        SELECT lifecycle.id
                          FROM payroll_employment_events lifecycle
                         WHERE lifecycle.supplier_id = employment.supplier_id
                           AND lifecycle.employment_id = employment.id
                           AND lifecycle.event_type = "status_changed"
                           AND lifecycle.to_status = "ended"
                           AND lifecycle.effective_on = employment.end_date
                         ORDER BY lifecycle.id DESC
                         LIMIT 1
                    ) AS ended_lifecycle_event_id
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employmentId]);
        $fetched = $statement->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            throw new EmploymentExitReadinessException(
                'employment_not_found',
                'Ukončený pracovní vztah nebyl nalezen.',
            );
        }
        $employment = self::row($fetched, 'pracovního vztahu');
        $status = $employment['status'] ?? null;
        $endedLifecycleEventId =
            $employment['ended_lifecycle_event_id'] ?? null;
        $archivedAfterEnded = $status === 'archived'
            && $endedLifecycleEventId !== null;
        if (($status !== 'ended' && !$archivedAfterEnded)
            || !is_string($employment['end_date'] ?? null)
        ) {
            throw new EmploymentExitReadinessException(
                'employment_not_ended',
                'Potvrzení lze vytvořit pouze pro ukončený pracovní vztah.',
            );
        }
        if ($archivedAfterEnded) {
            self::positiveInt($employment, 'ended_lifecycle_event_id');
        }
        $start = $employment['actual_start_date'] ?? null;
        if (!is_string($start) || $start === '') {
            throw new EmploymentExitReadinessException(
                'actual_start_date_missing',
                'Pracovní vztah nemá doložené skutečné datum nástupu.',
            );
        }
        $end = (string) $employment['end_date'];
        if (!is_string($employment['database_today'] ?? null)
            || $end > $employment['database_today']
        ) {
            throw new EmploymentExitReadinessException(
                'employment_end_in_future',
                'Potvrzení nelze vydat před skutečným skončením vztahu.',
            );
        }
        if ($start > $end) {
            throw new EmploymentExitReadinessException(
                'employment_interval_invalid',
                'Doložený interval pracovního vztahu není platný.',
            );
        }
        if (!is_string($employment['birth_date'] ?? null)) {
            throw new EmploymentExitReadinessException(
                'birth_date_missing',
                'Pro potvrzení chybí datum narození zaměstnance.',
            );
        }

        $employeeId = self::positiveInt($employment, 'employee_id');
        $identity = $this->exactEffectiveRow(
            'SELECT id, full_name, effective_from, effective_to, row_version
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              FOR UPDATE',
            [$supplierId, $employeeId, $end, $end],
            'identity_at_employment_end_missing',
            'Ke dni skončení chybí jednoznačná historie jména zaměstnance.',
        );
        $address = $this->exactEffectiveRow(
            'SELECT id, street_line, city, postal_code, country_code,
                    effective_from, effective_to, row_version
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
                AND address_type = "residence"
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              FOR UPDATE',
            [$supplierId, $employeeId, $end, $end],
            'residence_at_employment_end_missing',
            'Ke dni skončení chybí jednoznačná adresa bydliště zaměstnance.',
        );
        $terms = $this->exactEffectiveRow(
            'SELECT id, effective_from, effective_to, actual_start_on,
                    weekly_hours, cz_isco_code, activity_code,
                    jmhz_relationship_detail_code, risky_work,
                    row_version
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              FOR UPDATE',
            [$supplierId, $employmentId, $end, $end],
            'employment_terms_at_end_missing',
            'Ke dni skončení chybí jednoznačné smluvní podmínky.',
        );

        $employmentSource = $employment;
        unset(
            $employmentSource['birth_date'],
            $employmentSource['database_today'],
        );

        return [
            'employment' => $employmentSource,
            'employee' => [
                'id' => $employeeId,
                'birth_date' => (string) $employment['birth_date'],
            ],
            'identity' => $identity,
            'address' => $address,
            'terms' => $terms,
        ];
    }

    /**
     * Pracovní vztahy skončené v období — podklad pro kontrolu dokumentační
     * úplnosti mzdového měsíce. Bez tohohle seznamu vypadá prázdný archiv
     * stejně jako měsíc, ve kterém nikdo neodešel.
     *
     * @return list<array{
     *   id:int,
     *   employee_id:int,
     *   end_date:string,
     *   relation_type:string,
     *   employee_name:?string
     * }>
     */
    public function endedEmploymentsInPeriod(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id,
                    employment.end_date, employment.relation_type,
                    (
                        SELECT identity.full_name
                          FROM payroll_person_identity_history identity
                         WHERE identity.supplier_id = employment.supplier_id
                           AND identity.employee_id = employment.employee_id
                           AND identity.effective_from <= employment.end_date
                           AND (
                               identity.effective_to IS NULL
                               OR identity.effective_to >= employment.end_date
                           )
                         ORDER BY identity.effective_from DESC, identity.id DESC
                         LIMIT 1
                    ) AS employee_name
               FROM payroll_employments employment
              WHERE employment.supplier_id = ?
                AND employment.end_date IS NOT NULL
                AND employment.end_date BETWEEN ? AND ?
                AND employment.status IN ("ended", "archived")
              ORDER BY employment.end_date, employment.id',
        );
        $statement->execute([$supplierId, $from, $to]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = self::row($fetched, 'skončeného vztahu');
            $name = $row['employee_name'] ?? null;
            $result[] = [
                'id' => self::positiveInt($row, 'id'),
                'employee_id' => self::positiveInt($row, 'employee_id'),
                'end_date' => self::text($row, 'end_date'),
                'relation_type' => self::text($row, 'relation_type'),
                'employee_name' => is_string($name) && $name !== ''
                    ? $name
                    : null,
            ];
        }

        return $result;
    }

    /**
     * Smluvní podmínky platné kdykoli v rozhodném období průměrného výdělku.
     * § 356 odst. 2 zákoníku práce počítá měsíční průměr z týdenní pracovní
     * doby UPLATNĚNÉ v rozhodném období, takže jeden řádek ke dni skončení
     * nestačí — při změně úvazku se váží kalendářními dny.
     *
     * @return list<array{
     *   id:int,
     *   effective_from:string,
     *   effective_to:?string,
     *   weekly_hours:?string,
     *   tax_regime:string,
     *   row_version:int
     * }>
     */
    public function lockDecisivePeriodTerms(
        int $supplierId,
        int $employmentId,
        string $decisiveFrom,
        string $decisiveTo,
    ): array {
        $this->requireTransaction();

        return $this->decisivePeriodTermRows($supplierId, $employmentId, $decisiveFrom, $decisiveTo, true);
    }

    /**
     * Stejné podmínky jako {@see lockDecisivePeriodTerms()} bez zámku — pro
     * čtecí přehled (panel Skončení vztahu), který nic nezapisuje a transakci
     * nemá. Zápisové cesty dál zamykají.
     *
     * @return list<array{
     *   id:int,
     *   effective_from:string,
     *   effective_to:?string,
     *   weekly_hours:?string,
     *   tax_regime:string,
     *   row_version:int
     * }>
     */
    public function decisivePeriodTerms(
        int $supplierId,
        int $employmentId,
        string $decisiveFrom,
        string $decisiveTo,
    ): array {
        return $this->decisivePeriodTermRows($supplierId, $employmentId, $decisiveFrom, $decisiveTo, false);
    }

    /**
     * @return list<array{
     *   id:int,
     *   effective_from:string,
     *   effective_to:?string,
     *   weekly_hours:?string,
     *   tax_regime:string,
     *   row_version:int
     * }>
     */
    private function decisivePeriodTermRows(
        int $supplierId,
        int $employmentId,
        string $decisiveFrom,
        string $decisiveTo,
        bool $forUpdate,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, effective_from, effective_to, weekly_hours,
                    tax_regime, row_version
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id'
            . ($forUpdate ? ' FOR UPDATE' : ''),
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $decisiveTo,
            $decisiveFrom,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = self::row($fetched, 'smluvních podmínek');
            $effectiveTo = $row['effective_to'] ?? null;
            $weeklyHours = $row['weekly_hours'] ?? null;
            $result[] = [
                'id' => self::positiveInt($row, 'id'),
                'effective_from' => self::text($row, 'effective_from'),
                'effective_to' => is_string($effectiveTo) && $effectiveTo !== ''
                    ? $effectiveTo
                    : null,
                'weekly_hours' => $weeklyHours === null
                    ? null
                    : (string) $weeklyHours,
                'tax_regime' => self::text($row, 'tax_regime'),
                'row_version' => self::positiveInt($row, 'row_version'),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function lockContinuingDeductionClaims(
        int $supplierId,
        int $employeeId,
        string $employmentEndDate,
    ): array {
        $this->requireTransaction();
        $statement = $this->db->pdo()->prepare(
            'SELECT claim.id, claim.case_id, claim.legal_basis,
                    claim.outstanding_minor_units, claim.priority_date,
                    claim.row_version, enforcement_case.status AS case_status,
                    claim.legal_title_verified,
                    claim.order_or_notice_delivered,
                    claim.priority_classification_verified,
                    claim.agreement_verified,
                    claim.due_monetary_claim_verified,
                    enforcement_case.evidence_complete,
                    enforcement_case.recipient_verified
               FROM payroll_enforcement_claims claim
               JOIN payroll_enforcement_cases enforcement_case
                 ON enforcement_case.supplier_id = claim.supplier_id
                AND enforcement_case.id = claim.case_id
              WHERE claim.supplier_id = ?
                AND enforcement_case.employee_id = ?
                AND claim.is_active = 1
                AND enforcement_case.status IN (
                    "received", "withhold_and_hold", "remit",
                    "deferred_no_withholding", "deferred_hold"
                )
                AND enforcement_case.effective_from <= ?
                AND (
                    enforcement_case.effective_to IS NULL
                    OR enforcement_case.effective_to >= ?
                )
              ORDER BY claim.priority_date, claim.id
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $employmentEndDate,
            $employmentEndDate,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $claim = self::row($fetched, 'pokračující srážky');
            $ledger = $this->withheldForClaimAt(
                $supplierId,
                self::positiveInt($claim, 'id'),
                $employmentEndDate,
            );
            $withheld = $ledger['withheld_minor_units'];
            $claimAmount = self::nonNegativeInt(
                $claim,
                'outstanding_minor_units',
            );
            if ($withheld < 0 || $withheld > $claimAmount) {
                throw new EmploymentExitReadinessException(
                    'deduction_ledger_inconsistent',
                    'Ledger pokračující srážky není pro výstupní potvrzení konzistentní.',
                );
            }
            if ($claimAmount - $withheld === 0) {
                continue;
            }
            // Doručená, ale dosud neověřená exekuce (`received`) do zápočtového
            // listu patří taky — nový plátce mzdy v ní musí pokračovat (§ 294
            // odst. 1 o. s. ř.) a o tom, že neověřená je, rozhoduje tento
            // plátce, ne ten další. Ověření právního podkladu se proto vyžaduje
            // jen u exekucí, podle kterých se už srazilo.
            $received = self::text($claim, 'case_status') === 'received';
            if (!$received) {
                $this->assertClaimEvidence($claim);
            }
            $priorityDate = $claim['priority_date'] ?? null;
            if (!is_string($priorityDate) || $priorityDate === '') {
                if (!$received) {
                    throw new EmploymentExitReadinessException(
                        'deduction_priority_missing',
                        'Pokračující srážka nemá ověřené datum pořadí.',
                    );
                }
                $priorityDate = null;
            }
            $result[] = [
                'id' => self::positiveInt($claim, 'id'),
                'claim_amount_minor_units' => $claimAmount,
                'withheld_amount_minor_units' => $withheld,
                'priority_date' => $priorityDate,
                'row_version' => self::positiveInt($claim, 'row_version'),
                'ledger_sources' => $ledger['sources'],
            ];
        }

        return $result;
    }

    /**
     * Dohody o srážkách ze mzdy, podle kterých se ke dni skončení sráží
     * a dluh ještě není splacený (§ 313 odst. 1 písm. e) ZP: „zda ze
     * zaměstnancovy mzdy jsou prováděny srážky … v čí prospěch, jak vysoká je
     * pohledávka … jaká je výše dosud provedených srážek a jaké je pořadí").
     *
     * Srážky ze zákona podle § 147 odst. 1 písm. c) až e) ZP sem nepatří:
     * jsou to pohledávky tohoto zaměstnavatele, které další plátce mzdy
     * srážet nesmí.
     *
     * @return list<array{id:int,agreement_reference:string,title:string,total_limit_minor:?int,withheld_total_minor:int,taken_over_withheld_minor:int,delivered_on:?string,recipient_reference:?string,row_version:int}>
     */
    public function lockContinuingDeductionAgreements(
        int $supplierId,
        int $employeeId,
        string $employmentEndDate,
    ): array {
        $this->requireTransaction();
        $statement = $this->db->pdo()->prepare(
            'SELECT id, agreement_reference, title, total_limit_minor,
                    withheld_total_minor, taken_over_withheld_minor, delivered_on,
                    recipient_reference, row_version
               FROM payroll_deduction_agreements
              WHERE supplier_id = ? AND employee_id = ?
                AND legal_basis = "agreement"
                AND status IN ("active", "paused")
                AND valid_from <= ?
                AND (total_limit_minor IS NULL
                     OR total_limit_minor > withheld_total_minor + taken_over_withheld_minor)
              ORDER BY COALESCE(delivered_on, "9999-12-31"), priority_no, id
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employeeId, $employmentEndDate]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = self::row($fetched, 'dohody o srážkách');
            $limit = $row['total_limit_minor'] ?? null;
            $delivered = $row['delivered_on'] ?? null;
            $recipient = $row['recipient_reference'] ?? null;
            $result[] = [
                'id' => self::positiveInt($row, 'id'),
                'agreement_reference' => self::text($row, 'agreement_reference'),
                'title' => self::text($row, 'title'),
                'total_limit_minor' => $limit === null ? null : self::databaseInt($limit, 'total_limit_minor'),
                'withheld_total_minor' => self::nonNegativeInt($row, 'withheld_total_minor'),
                'taken_over_withheld_minor' => self::nonNegativeInt($row, 'taken_over_withheld_minor'),
                'delivered_on' => is_string($delivered) && $delivered !== '' ? $delivered : null,
                'recipient_reference' => is_string($recipient) && $recipient !== '' ? $recipient : null,
                'row_version' => self::positiveInt($row, 'row_version'),
            ];
        }

        return $result;
    }

    /**
     * Insolvence evidovaná za měsíc skončení (zahájené řízení nebo oddlužení).
     * Srážky ve prospěch insolvenčního správce, které plátci mzdy ukládá
     * rozhodnutí o schválení oddlužení (§ 406 odst. 3 písm. d) insolvenčního
     * zákona), dlužníka provázejí i k dalšímu plátci mzdy, takže patří do
     * zápočtového listu jako „srážky, které se ze mzdy provádějí". Výše „pohledávky" se u oddlužení neurčuje
     * částkou — sráží se podle rozhodnutí soudu; dosud provedené srážky jsou
     * součet srážek odvedených správci.
     *
     * @return array{id:int,insolvency_mode:string,row_version:int,withheld_minor_units:int}|null
     */
    public function lockContinuingInsolvency(
        int $supplierId,
        int $employeeId,
        string $employmentEndDate,
    ): ?array {
        $this->requireTransaction();
        $statement = $this->db->pdo()->prepare(
            'SELECT id, insolvency_mode, row_version
               FROM payroll_enforcement_person_month_evidence
              WHERE supplier_id = ? AND employee_id = ?
                AND period_start = STR_TO_DATE(DATE_FORMAT(?, "%Y-%m-01"), "%Y-%m-%d")
                AND insolvency_mode <> "none"
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employeeId, $employmentEndDate]);
        $fetched = $statement->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            return null;
        }
        $row = self::row($fetched, 'insolvence');
        $withheld = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(allocation.total_minor_units), 0)
               FROM payroll_enforcement_allocations allocation
               JOIN payroll_enforcement_month_results result
                 ON result.supplier_id = allocation.supplier_id
                AND result.id = allocation.month_result_id
          LEFT JOIN payroll_run_revisions result_revision
                 ON result_revision.supplier_id = result.supplier_id
                AND result_revision.id = result.revision_id
              WHERE allocation.supplier_id = ? AND result.employee_id = ?
                AND allocation.allocation_key = "insolvency-administrator"
                AND result.result_status = "supported"
                AND result.period_start <=
                    STR_TO_DATE(DATE_FORMAT(?, "%Y-%m-01"), "%Y-%m-%d")
                AND (
                    result.revision_id IS NULL
                    OR result.revision_id = (
                        SELECT approved_revision.id
                          FROM payroll_run_revisions approved_revision
                         WHERE approved_revision.supplier_id = result.supplier_id
                           AND approved_revision.run_id = result_revision.run_id
                           AND approved_revision.status = "approved"
                         ORDER BY approved_revision.revision_no DESC
                         LIMIT 1
                    )
                )',
        );
        $withheld->execute([$supplierId, $employeeId, $employmentEndDate]);

        return [
            'id' => self::positiveInt($row, 'id'),
            'insolvency_mode' => self::text($row, 'insolvency_mode'),
            'row_version' => self::positiveInt($row, 'row_version'),
            'withheld_minor_units' => self::databaseInt($withheld->fetchColumn(), 'withheld_minor_units'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function findBySourceManifest(
        int $supplierId,
        int $employmentId,
        string $purpose,
        string $sourceManifestHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_exit_revisions
              WHERE supplier_id = ? AND employment_id = ?
                AND purpose = ? AND source_manifest_hash = ?
              LIMIT 1',
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $purpose,
            $sourceManifestHash,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::cast(self::row($row, 'výstupní revize'));
    }

    /** @return array<string,mixed>|null */
    public function latest(
        int $supplierId,
        int $employmentId,
        string $purpose,
    ): ?array {
        $this->requireTransaction();
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_exit_revisions
              WHERE supplier_id = ? AND employment_id = ? AND purpose = ?
              ORDER BY revision_no DESC, id DESC
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employmentId, $purpose]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::cast(self::row($row, 'výstupní revize'));
    }

    /** @param array<string,mixed> $record
     *  @return array<string,mixed>
     */
    public function insertApproved(array $record): array
    {
        $this->requireTransaction();
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_exit_revisions
                (supplier_id, employee_id, employment_id, purpose,
                 employment_end_date, revision_no, previous_revision_id,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        );
        try {
            $statement->execute([
                $record['supplier_id'],
                $record['employee_id'],
                $record['employment_id'],
                $record['purpose'],
                $record['employment_end_date'],
                $record['revision_no'],
                $record['previous_revision_id'],
                $record['snapshot_ciphertext'],
                $record['snapshot_hash'],
                $record['source_manifest_json'],
                $record['source_manifest_hash'],
                $record['approved_by'],
            ]);
        } catch (PDOException $exception) {
            if (self::errorNumber($exception) !== 1062) {
                throw $exception;
            }
            $existing = $this->findBySourceManifest(
                self::positiveInt($record, 'supplier_id'),
                self::positiveInt($record, 'employment_id'),
                self::text($record, 'purpose'),
                self::text($record, 'source_manifest_hash'),
            );
            if ($existing === null
                || !hash_equals(
                    self::text($existing, 'snapshot_hash'),
                    self::text($record, 'snapshot_hash'),
                )
            ) {
                throw new \RuntimeException(
                    'Výstupní snapshot koliduje s jinou revizí.',
                    previous: $exception,
                );
            }

            return $existing;
        }
        $id = (int) $this->db->pdo()->lastInsertId();

        return $this->find(self::positiveInt($record, 'supplier_id'), $id)
            ?? throw new \RuntimeException(
                'Schválený výstupní snapshot nelze načíst.',
            );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_employment_exit_revisions
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::cast(self::row($row, 'výstupní revize'));
    }

    /** @param list<mixed> $params
     *  @return array<string,mixed>
     */
    private function exactEffectiveRow(
        string $sql,
        array $params,
        string $readinessCode,
        string $message,
    ): array {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new EmploymentExitReadinessException(
                $readinessCode,
                $message,
            );
        }

        return self::row($rows[0], $message);
    }

    /** @param array<string,mixed> $claim */
    private function assertClaimEvidence(array $claim): void
    {
        $common = self::flag($claim, 'evidence_complete')
            && self::flag($claim, 'recipient_verified')
            && self::flag($claim, 'due_monetary_claim_verified');
        $legalBasis = $claim['legal_basis'] ?? null;
        $verified = match ($legalBasis) {
            'statutory' => $common
                && self::flag($claim, 'legal_title_verified')
                && self::flag($claim, 'order_or_notice_delivered')
                && self::flag($claim, 'priority_classification_verified'),
            'voluntary_agreement' => $common
                && self::flag($claim, 'agreement_verified'),
            default => false,
        };
        if (!$verified) {
            throw new EmploymentExitReadinessException(
                'deduction_legal_evidence_incomplete',
                'Pokračující srážka nemá úplný ověřený právní podklad.',
            );
        }
    }

    /**
     * @return array{
     *   withheld_minor_units:int,
     *   sources:list<array{
     *     ledger_id:int,
     *     month_result_id:int,
     *     result_snapshot_hash:string,
     *     entry_kind:string
     *   }>
     * }
     */
    private function withheldForClaimAt(
        int $supplierId,
        int $claimId,
        string $employmentEndDate,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT ledger.id AS ledger_id,
                    ledger.month_result_id,
                    ledger.entry_kind,
                    ledger.amount_minor_units,
                    result.result_snapshot_hash,
                    result.result_status
               FROM payroll_enforcement_ledger ledger
               JOIN payroll_enforcement_month_results result
                 ON result.supplier_id = ledger.supplier_id
                AND result.id = ledger.month_result_id
          LEFT JOIN payroll_run_revisions result_revision
                 ON result_revision.supplier_id = result.supplier_id
                AND result_revision.id = result.revision_id
              WHERE ledger.supplier_id = ? AND ledger.claim_id = ?
                AND result.period_start <=
                    STR_TO_DATE(DATE_FORMAT(?, "%Y-%m-01"), "%Y-%m-%d")
                AND (
                    result.revision_id IS NULL
                    OR result.revision_id = (
                        SELECT approved_revision.id
                          FROM payroll_run_revisions approved_revision
                         WHERE approved_revision.supplier_id =
                               result.supplier_id
                           AND approved_revision.run_id =
                               result_revision.run_id
                           AND approved_revision.status = "approved"
                         ORDER BY approved_revision.revision_no DESC
                         LIMIT 1
                    )
                )
                AND ledger.entry_kind IN (
                    "withheld", "released_to_employee", "adjustment",
                    "released_to_administrator"
                )
              ORDER BY result.period_start, ledger.id
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $claimId,
            $employmentEndDate,
        ]);

        $withheld = 0;
        $sources = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = self::row($fetched, 'ledgeru pokračující srážky');
            $amount = self::databaseInt(
                $row['amount_minor_units'] ?? null,
                'amount_minor_units',
            );
            $kind = self::text($row, 'entry_kind');
            if (self::text($row, 'result_status') !== 'supported') {
                throw new EmploymentExitReadinessException(
                    'deduction_result_not_supported',
                    'Ledger pokračující srážky obsahuje výsledek vyžadující '
                        . 'ruční posouzení.',
                );
            }
            $withheld += match ($kind) {
                'withheld', 'adjustment' => $amount,
                // Vydané insolvenčnímu správci oprávněnému nepřišlo, takže
                // mezi „dosud provedené srážky" na jeho pohledávku nepatří.
                'released_to_employee', 'released_to_administrator' => -$amount,
                default => throw new \UnexpectedValueException(
                    'Ledger obsahuje nepodporovaný druh položky.',
                ),
            };
            $sources[] = [
                'ledger_id' => self::positiveInt($row, 'ledger_id'),
                'month_result_id' =>
                    self::positiveInt($row, 'month_result_id'),
                'result_snapshot_hash' =>
                    self::hashText($row, 'result_snapshot_hash'),
                'entry_kind' => $kind,
            ];
        }

        return [
            'withheld_minor_units' => $withheld,
            'sources' => $sources,
        ];
    }

    private function requireTransaction(): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'Výstupní snapshot pracovního vztahu vyžaduje aktivní transakci.',
            );
        }
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'employee_id',
            'employment_id',
            'revision_no',
            'previous_revision_id',
            'approved_by',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = self::databaseInt($row[$key], $key);
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = self::databaseInt($row[$field] ?? null, $field);
        if ($value <= 0) {
            throw new \UnexpectedValueException(
                "Pole {$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $field): int
    {
        $value = self::databaseInt($row[$field] ?? null, $field);
        if ($value < 0) {
            throw new \UnexpectedValueException(
                "Pole {$field} nesmí být záporné.",
            );
        }

        return $value;
    }

    private static function databaseInt(mixed $value, string $field): int
    {
        if (!is_int($value)
            && !(is_string($value)
                && preg_match('/^-?[0-9]+$/D', $value) === 1)
        ) {
            throw new \UnexpectedValueException(
                "Pole {$field} není celé číslo.",
            );
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Pole {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function hashText(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Pole {$field} není platný otisk.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function flag(array $row, string $field): bool
    {
        $value = self::databaseInt($row[$field] ?? null, $field);
        if (!in_array($value, [0, 1], true)) {
            throw new \UnexpectedValueException(
                "Pole {$field} není databázový boolean.",
            );
        }

        return $value === 1;
    }

    private static function errorNumber(PDOException $exception): int
    {
        return self::databaseInt($exception->errorInfo[1] ?? 0, 'error_code');
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                "Databázový řádek {$label} není objekt.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databázový řádek {$label} má neplatný klíč.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
