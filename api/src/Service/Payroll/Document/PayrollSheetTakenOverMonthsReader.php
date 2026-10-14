<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Měsíce zpracované předchozím mzdovým programem po jednotlivých měsících.
 *
 * Zdrojem jsou převzaté úhrny mezd (`payroll_migration_reference_totals`),
 * které převod ukládá po osobě, vztahu a měsíci. Mzdový list a přehled mezd je
 * vypisují ve stejných sloupcích jako měsíce spočítané MyÚčtem; údaj, který
 * zdroj nenese, je `null` a doklad ho nechá prázdný.
 *
 * Více vztahů téže osoby v jednom měsíci se sčítá, stejně jako u vlastních
 * revizí.
 */
final class PayrollSheetTakenOverMonthsReader
{
    /** Peněžní a početní pole měsíčního řádku. */
    public const FIELDS = [
        'gross_minor',
        'net_minor',
        'deductions_minor',
        'net_payable_minor',
        'social_base_minor',
        'health_base_minor',
        'employee_social_minor',
        'employee_health_minor',
        'employer_social_minor',
        'employer_health_minor',
        'advance_tax_minor',
        'withholding_tax_minor',
        'tax_bonus_minor',
        'insurance_days',
        'worked_days_hundredths',
        'worked_minutes',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,int|string|null>> řádky s klíčem `month` (1–12) a `source`
     */
    public function read(int $supplierId, int $employeeId, int $taxYear): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT MONTH(period_start) AS month, source, '
            . implode(', ', array_map(
                static fn (string $field): string => 'SUM(' . $field . ') AS ' . $field,
                self::FIELDS,
            ))
            . ' FROM payroll_migration_reference_totals
               WHERE supplier_id = ?
                 AND employee_id = ?
                 AND period_start >= ?
                 AND period_start < ?
               GROUP BY MONTH(period_start), source
               ORDER BY MONTH(period_start), source'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            sprintf('%04d-01-01', $taxYear),
            sprintf('%04d-01-01', $taxYear + 1),
        ]);

        return self::normalize($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Převzaté měsíce celé firmy za jedno období, po osobách.
     *
     * @return list<array<string,int|string|null>> řádky s `employee_id`
     */
    public function readPeriod(int $supplierId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id, MONTH(period_start) AS month, source, '
            . implode(', ', array_map(
                static fn (string $field): string => 'SUM(' . $field . ') AS ' . $field,
                self::FIELDS,
            ))
            . ' FROM payroll_migration_reference_totals
               WHERE supplier_id = ?
                 AND period_start = ?
                 AND employee_id IS NOT NULL
               GROUP BY employee_id, MONTH(period_start), source
               ORDER BY employee_id'
        );
        $statement->execute([$supplierId, $periodStart]);
        $byEmployee = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byEmployee[(int) $row['employee_id']][] = $row;
        }
        $rows = [];
        foreach ($byEmployee as $employeeId => $employeeRows) {
            $normalized = self::normalize($employeeRows)[0] ?? null;
            if ($normalized !== null) {
                $normalized['employee_id'] = $employeeId;
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    /**
     * Ze snapshotu dokladu: ověří tvar, nic nepřepočítává.
     *
     * @return list<array<string,int|string|null>>
     */
    public static function fromSnapshot(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException('Převzaté měsíce ve snapshotu nejsou seznam.');
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row) || !is_int($row['month'] ?? null) || $row['month'] < 1 || $row['month'] > 12) {
                throw new \DomainException('Převzatý měsíc ve snapshotu není platný.');
            }
            $normalized = ['month' => $row['month'], 'source' => is_string($row['source'] ?? null) ? $row['source'] : ''];
            foreach (self::FIELDS as $field) {
                $item = $row[$field] ?? null;
                if ($item !== null && !is_int($item)) {
                    throw new \DomainException("Převzatý údaj {$field} není celé číslo.");
                }
                $normalized[$field] = $item;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,int|string|null>>
     */
    private static function normalize(array $rows): array
    {
        $byMonth = [];
        foreach ($rows as $row) {
            $month = (int) $row['month'];
            if ($month < 1 || $month > 12) {
                continue;
            }
            // Dva zdroje za týž měsíc (např. převod a následný import JMHZ)
            // se nesčítají — první zdroj v abecedním pořadí vyhrává, aby se
            // měsíc nezapočítal dvakrát.
            if (isset($byMonth[$month])) {
                continue;
            }
            $normalized = ['month' => $month, 'source' => (string) $row['source']];
            foreach (self::FIELDS as $field) {
                $normalized[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            $byMonth[$month] = $normalized;
        }
        ksort($byMonth, SORT_NUMERIC);

        return array_values($byMonth);
    }
}
