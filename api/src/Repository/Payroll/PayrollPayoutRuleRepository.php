<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleInput;
use PDO;
use PDOException;

/**
 * Zápis a čtení výplatních pravidel (`payroll_payout_rules`).
 *
 * Tabulka existuje od migrace 1250, ale do téhle třídy do ní neuměla zapsat
 * žádná cesta v `api/src` — jediné čtení bylo
 * PayrollRunSnapshotBatchLoader::payoutRules(), které pravidla zmrazí do
 * snapshotu běhu. Protože byla prázdná, PayoutAllocationService::allocate()
 * vyhodil výjimku a plný mzdový modul neuměl vyrobit závazek čisté mzdy.
 *
 * Dvě vlastnosti, na kterých stojí bezpečnost zápisu:
 *
 * 1. KAŽDÝ dotaz je scoped na `supplier_id` A `employee_id`. Osamocené `id` se
 *    nikde nepoužívá jako klíč — cizí tenant tak nemůže sáhnout na pravidlo ani
 *    omylem, ani podstrčeným id v URL.
 * 2. Pravidlo se nikdy nemaže, jen deaktivuje. Zmrazené alokace
 *    (`payroll_payout_allocations.payout_rule_id`) na řádky odkazují cizím
 *    klíčem a historická revize musí zůstat rekonstruovatelná.
 */
final class PayrollPayoutRuleRepository
{
    private const SAVEPOINT = 'payroll_payout_rule_repository';

    private const COLUMNS = 'id, supplier_id, employee_id, allocation_reference,
        destination_kind, destination_reference, allocation_kind, amount_minor,
        basis_points, priority_no, is_active, row_version, created_at, updated_at';

    /** Aktuální hloubka zanoření transaction() — viz komentář u metody. */
    private int $depth = 0;

    public function __construct(private readonly Connection $db) {}

    /**
     * Všechna pravidla zaměstnance včetně neaktivních.
     *
     * Neaktivní se záměrně nefiltrují: uživatel musí vidět, že pravidlo existuje
     * a je jen vypnuté, jinak by ho zakládal znovu a narazil na unikátní
     * referenci. Pořadí je totožné se snapshotem (priority_no, id).
     *
     * @return list<array<string,mixed>>
     */
    public function listForEmployee(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_payout_rules
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY priority_no, id'
        );
        $stmt->execute([$supplierId, $employeeId]);

        return $this->decorate(
            $supplierId,
            $employeeId,
            array_values(array_map(
                self::present(...),
                $stmt->fetchAll(PDO::FETCH_ASSOC),
            )),
        );
    }

    /**
     * Táž množina se zámkem — volá se jako PRVNÍ krok každého zápisu.
     *
     * Zamyká se celá sada pravidel osoby, ne jen měněný řádek: podmínka „právě
     * jeden aktivní zbytek" je vlastnost celé sady, takže bez zámku nad ní by
     * dva souběžné zápisy prošly kontrolou v aplikaci a rozdíl by odchytil až
     * unikátní index chybou 1062 bez srozumitelné hlášky.
     *
     * Výsledek je INTERNÍ podklad pro validaci a ven z repozitáře nejde — proto
     * se ZÁMĚRNĚ neobohacuje o `destination_verified`. Ověření účtu není součástí
     * invariantu, který zámek chrání, a dotaz na `payroll_person_accounts` uvnitř
     * `FOR UPDATE` by rozšířil zámek na tabulku, kterou zápis pravidla nemění.
     *
     * @return list<array<string,mixed>>
     */
    public function lockForEmployee(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_payout_rules
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY priority_no, id
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId]);

        return array_values(array_map(
            self::present(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $employeeId, int $ruleId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_payout_rules
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employeeId, $ruleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return $this->decorate(
            $supplierId,
            $employeeId,
            [self::present($row)],
        )[0];
    }

    /**
     * Doplní k pravidlům stav ověření jejich platebního cíle.
     *
     * PROČ TO PATŘÍ K PRAVIDLU: zápis pravidla na NEOVĚŘENÝ účet je povolený
     * schválně — pravidlo musí jít připravit dřív, než účetní ověření stihne.
     * Jenže PayrollNetWageLiabilityMaterializer takový účet odmítne („Zmrazený
     * účet nemá úplné ověření"), a to až nad zmrazenou revizí, kde se s tím dá
     * dělat jen opravná revize. Bez tohohle příznaku se uživatel o problému
     * dozví právě až tam. Tady ho vidí hned u pravidla.
     *
     * `null` u hotovosti a zápočtu na účet společníka je významové: ověření tam
     * nedává smysl a `false` by se četlo jako vada.
     *
     * Dotaz je jeden pro celou sadu (WHERE id IN …), ne per pravidlo.
     *
     * @param list<array<string,mixed>> $rules
     * @return list<array<string,mixed>>
     */
    private function decorate(int $supplierId, int $employeeId, array $rules): array
    {
        $accountIds = [];
        foreach ($rules as $rule) {
            $accountId = self::bankAccountId($rule);
            if ($accountId !== null) {
                $accountIds[$accountId] = true;
            }
        }
        if ($accountIds === []) {
            return $rules;
        }

        $ids = array_keys($accountIds);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ?
                AND id IN (' . $placeholders . ')
                AND is_active = 1
                AND verification_source IS NOT NULL
                AND verified_on IS NOT NULL
                AND verified_by IS NOT NULL'
        );
        $stmt->execute([$supplierId, $employeeId, ...$ids]);
        $verified = array_flip(array_map(
            static fn (mixed $id): int => (int) $id,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        ));

        foreach ($rules as $index => $rule) {
            $accountId = self::bankAccountId($rule);
            if ($accountId !== null) {
                $rules[$index]['destination_verified'] =
                    isset($verified[$accountId]);
            }
        }

        return $rules;
    }

    /**
     * Id výplatního účtu z reference `account:<id>`, nebo NULL.
     *
     * Vzor je sdílený s PayrollPayoutRuleInput — je to týž tvar, který jediný
     * umí zpracovat PayrollNetWageLiabilityMaterializer::paymentTarget().
     *
     * @param array<string,mixed> $rule
     */
    private static function bankAccountId(array $rule): ?int
    {
        $reference = $rule['destination_reference'] ?? null;
        if (($rule['destination_kind'] ?? null) !== 'bank'
            || !is_string($reference)
            || preg_match(
                PayrollPayoutRuleInput::BANK_REFERENCE_PATTERN,
                $reference,
                $match,
            ) !== 1
        ) {
            return null;
        }

        return (int) $match[1];
    }

    /** @return array<string,mixed> */
    public function create(
        int $supplierId,
        int $employeeId,
        PayrollPayoutRuleInput $input,
        ?string $allocationReference = null,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employeeId,
            $input,
            $allocationReference,
        ): array {
            $this->assertEmployee($supplierId, $employeeId);
            $reference = $allocationReference ?? $input->generateReference();
            try {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO payroll_payout_rules
                        (supplier_id, employee_id, allocation_reference,
                         destination_kind, destination_reference, allocation_kind,
                         amount_minor, basis_points, priority_no, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $supplierId,
                    $employeeId,
                    $reference,
                    $input->destinationKind,
                    $input->destinationReference,
                    $input->allocationKind,
                    $input->amountMinor,
                    $input->basisPoints,
                    $input->priorityNo,
                    $input->isActive ? 1 : 0,
                ]);
            } catch (PDOException $e) {
                throw self::translate($e);
            }

            return $this->requireRule(
                $supplierId,
                $employeeId,
                (int) $this->db->pdo()->lastInsertId(),
            );
        });
    }

    /**
     * Úprava s optimistickým zámkem.
     *
     * `allocation_reference` se ZÁMĚRNĚ nemění — je to stabilní identita pravidla
     * vůči zmrazeným alokacím i vůči logickému odkazu závazku čisté mzdy, ze
     * kterého se skládá návaznost opravných revizí na dřívější platby.
     *
     * @return array<string,mixed>
     */
    public function update(
        int $supplierId,
        int $employeeId,
        int $ruleId,
        PayrollPayoutRuleInput $input,
        int $rowVersion,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employeeId,
            $ruleId,
            $input,
            $rowVersion,
        ): array {
            $current = $this->lock($supplierId, $employeeId, $ruleId);
            try {
                $stmt = $this->db->pdo()->prepare(
                    'UPDATE payroll_payout_rules
                        SET destination_kind = ?, destination_reference = ?,
                            allocation_kind = ?, amount_minor = ?,
                            basis_points = ?, priority_no = ?, is_active = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ? AND id = ?
                        AND row_version = ?'
                );
                $stmt->execute([
                    $input->destinationKind,
                    $input->destinationReference,
                    $input->allocationKind,
                    $input->amountMinor,
                    $input->basisPoints,
                    $input->priorityNo,
                    $input->isActive ? 1 : 0,
                    $supplierId,
                    $employeeId,
                    $ruleId,
                    $rowVersion,
                ]);
            } catch (PDOException $e) {
                throw self::translate($e);
            }
            if ($stmt->rowCount() !== 1) {
                throw new PayrollPayoutRuleConflictException(
                    (int) $current['row_version'],
                );
            }

            return $this->requireRule($supplierId, $employeeId, $ruleId);
        });
    }

    /**
     * Deaktivace místo smazání — viz poznámka u třídy.
     *
     * Opakované volání nad už neaktivním pravidlem je no-op, ale row_version se
     * i tak musí shodovat; jinak by uživatel „vypnul" pravidlo, které mezitím
     * někdo jiný přepsal na jiný cílový účet.
     *
     * @return array<string,mixed>
     */
    public function deactivate(
        int $supplierId,
        int $employeeId,
        int $ruleId,
        int $rowVersion,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employeeId,
            $ruleId,
            $rowVersion,
        ): array {
            $current = $this->lock($supplierId, $employeeId, $ruleId);
            if ((int) $current['row_version'] !== $rowVersion) {
                throw new PayrollPayoutRuleConflictException(
                    (int) $current['row_version'],
                );
            }
            if ((int) $current['is_active'] === 0) {
                return self::present($current);
            }
            $stmt = $this->db->pdo()->prepare(
                'UPDATE payroll_payout_rules
                    SET is_active = 0, row_version = row_version + 1
                  WHERE supplier_id = ? AND employee_id = ? AND id = ?
                    AND row_version = ?'
            );
            $stmt->execute([$supplierId, $employeeId, $ruleId, $rowVersion]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollPayoutRuleConflictException(
                    (int) $current['row_version'],
                );
            }

            return $this->requireRule($supplierId, $employeeId, $ruleId);
        });
    }

    /**
     * Transakce se savepointem, bezpečná i při vnořování do sebe sama.
     *
     * Savepoint nese POŘADÍ zanoření: PayrollPayoutRuleService i
     * PayrollPayoutRuleDefaultsService obalují zápis vlastní transakcí, takže
     * `create()` se běžně volá tři úrovně hluboko. MariaDB při `SAVEPOINT` se
     * stejným jménem starý savepoint ZAHODÍ — s konstantním jménem by vnitřní
     * RELEASE zrušil i ten vnější a následný rollback/release by spadl na
     * „savepoint does not exist". Číslovaná jména to vylučují.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        $savepoint = self::SAVEPOINT . '_' . (++$this->depth);
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($nested && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            --$this->depth;
        }
    }

    public function assertEmployee(int $supplierId, int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new \OutOfBoundsException('Zaměstnanec nebyl nalezen.');
        }
    }

    /** @return array<string,mixed> */
    private function lock(int $supplierId, int $employeeId, int $ruleId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_payout_rules
              WHERE supplier_id = ? AND employee_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId, $ruleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Výplatní pravidlo nebylo nalezeno.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function requireRule(int $supplierId, int $employeeId, int $ruleId): array
    {
        return $this->find($supplierId, $employeeId, $ruleId)
            ?? throw new \OutOfBoundsException('Výplatní pravidlo nebylo nalezeno.');
    }

    /**
     * Chyby unikátních indexů se překládají do domény, ne do 500.
     *
     * `uq_payroll_payout_rule_single_remainder` je poslední instance kontroly
     * „právě jeden aktivní zbytek" (migrace 1378) — aplikace ji sice kontroluje
     * dřív a s hezčí hláškou, ale při souběhu dvou zápisů dorazí sem.
     */
    private static function translate(PDOException $e): \Throwable
    {
        $driverCode = $e->errorInfo[1] ?? null;
        if ($driverCode !== 1062 && $driverCode !== '1062') {
            return $e;
        }
        $message = $e->getMessage();
        if (str_contains($message, 'uq_payroll_payout_rule_single_remainder')) {
            return new \DomainException(
                'Zaměstnanec už má aktivní pravidlo pro zbytek výplaty. '
                . 'Nejdřív ho deaktivujte nebo upravte.',
                previous: $e,
            );
        }

        return new \DomainException(
            'Výplatní pravidlo se stejným identifikátorem už existuje.',
            previous: $e,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'employee_id', 'priority_no', 'row_version',
        ] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['amount_minor', 'basis_points'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['is_active'] = (int) $row['is_active'] === 1;
        // NULL = ověření u tohohle cíle nedává smysl (hotovost, zápočet na účet
        // společníka). U banky ho na true/false přepíše decorate().
        $row['destination_verified'] = null;
        // Sloupec je jen klíčem unikátního indexu (migrace 1378); do API nepatří.
        unset($row['remainder_guard']);

        return $row;
    }
}
