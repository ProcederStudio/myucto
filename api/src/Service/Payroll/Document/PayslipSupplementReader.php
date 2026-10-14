<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Doplňky výplatních pásek schválené revize ({@see PayslipSupplement}).
 *
 * Zdrojem je zmrazený vstup téže revize (docházka, složky, průměr, účet
 * výplaty) a kniha dovolené k poslednímu dni období. Chybí-li vstup osoby,
 * doplněk pro ni nevznikne a páska vyjde bez něj.
 */
final class PayslipSupplementReader
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<int,array<string,mixed>> $resultPeople výsledky osob podle employee_id
     * @return array<int,PayslipSupplement>
     */
    public function forRevision(
        int $supplierId,
        int $revisionId,
        string $periodStart,
        array $resultPeople,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT input_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $revisionId]);
        $json = $statement->fetchColumn();
        if (!is_string($json) || $json === '') {
            return [];
        }
        try {
            $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($input) || !is_array($input['people'] ?? null)) {
            return [];
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $year = (int) substr($periodStart, 0, 4);

        $supplements = [];
        foreach ($input['people'] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $employeeId = is_array($person['employee'] ?? null) ? ($person['employee']['id'] ?? null) : null;
            if (!is_int($employeeId) || !isset($resultPeople[$employeeId])) {
                continue;
            }
            $employmentIds = [];
            foreach (is_array($person['employments'] ?? null) ? $person['employments'] : [] as $row) {
                $id = is_array($row) && is_array($row['employment'] ?? null) ? ($row['employment']['id'] ?? null) : null;
                if (is_int($id)) {
                    $employmentIds[] = $id;
                }
            }
            $supplements[$employeeId] = PayslipSupplement::fromFrozen(
                $person,
                $resultPeople[$employeeId],
                $this->remainingVacation($supplierId, $employmentIds, $year, $periodEnd),
            );
        }

        return $supplements;
    }

    /**
     * Zůstatek dovolené v minutách k poslednímu dni období, nebo `null`, když
     * vztah knihu dovolené nevede (žádný zápis za rok).
     *
     * @param list<int> $employmentIds
     */
    private function remainingVacation(int $supplierId, array $employmentIds, int $year, string $asOf): ?int
    {
        if ($employmentIds === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS entries, COALESCE(SUM(minutes_delta), 0) AS balance
               FROM payroll_leave_ledger
              WHERE supplier_id = ?
                AND leave_year = ?
                AND effective_date <= ?
                AND employment_id IN (' . $placeholders . ')'
        );
        $statement->execute([$supplierId, $year, $asOf, ...$employmentIds]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['entries'] === 0) {
            return null;
        }

        return (int) $row['balance'];
    }
}
