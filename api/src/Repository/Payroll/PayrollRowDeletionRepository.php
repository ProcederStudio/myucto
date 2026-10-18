<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Společné lešení bezpečného mazání jednořádkových mzdových záznamů.
 *
 * ── Vodicí princip ────────────────────────────────────────────────────────────
 * Blokovat smí VÝHRADNĚ důkaz pohybu: vnější úkon vůči úřadu, schválený výpočet,
 * nebo peníze. Nic jiného. Rozepsané údaje, poznámky ani „bylo by to složité
 * uklidit" důvodem nejsou — když u něčeho nejsou pohyby, není co chránit.
 *
 * ── Jedna definice, dvě použití ───────────────────────────────────────────────
 * Potomek popíše blokátory jako SQL logické výrazy nad aliasem svého řádku.
 * Ze stejné definice se staví jak rozhodnutí (`can_delete` + `delete_blocker`),
 * tak podmínka samotného DELETE — nemůžou se tedy rozejít. DELETE je proto
 * atomické přeověření: když mezi vykreslením seznamu a kliknutím vznikl pohyb,
 * smaže se 0 řádků a volající to ohlásí větou, ne syrovou chybou cizího klíče.
 *
 * ── Souběh ────────────────────────────────────────────────────────────────────
 * Řádek se před rozhodnutím zamkne (`FOR UPDATE`), rozhodnutí se pod zámkem
 * zopakuje a teprve pak se maže. Celé to běží ve vlastní transakci, nebo
 * v savepointu, pokud transakce už běží.
 */
abstract class PayrollRowDeletionRepository
{
    public function __construct(
        protected readonly Connection $db,
        protected readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * Důkazy pohybu. Klíč = alias, `sql` = logický výraz nad aliasem řádku,
     * `code` + `message` = kód pro frontend a věta, podle které se dá jednat.
     *
     * @return array<string,array{code:string,message:string,sql:string}>
     */
    abstract protected static function blockers(): array;

    /**
     * Vlastní lešení, které zmizí spolu s řádkem. Klíč = alias, hodnota = SQL
     * výraz s počtem. Propisuje se do potvrzovacího dialogu i do auditu.
     *
     * @return array<string,string>
     */
    abstract protected static function cascade(): array;

    abstract protected static function table(): string;

    abstract protected static function rowAlias(): string;

    abstract protected static function notFoundMessage(): string;

    abstract protected static function auditAction(): string;

    abstract protected static function auditEntity(): string;

    /** @return list<string> */
    abstract protected static function lockedColumns(): array;

    /**
     * @param array<string,string|int|null> $row
     * @return array<string,mixed>
     */
    abstract protected static function auditPayload(array $row): array;

    /** Tabulky bez optimistického zámku vrací `null`. */
    protected static function rowVersionColumn(): ?string
    {
        return 'row_version';
    }

    /**
     * Vrací `null`, když řádek v tomto tenantu neexistuje — volající to překlopí
     * na 404, aby cizí tenant nezjistil ani to, jestli id existuje.
     */
    public function canDelete(int $supplierId, int $id): ?PayrollDeletionDecision
    {
        return $this->canDeleteMany($supplierId, [$id])[$id] ?? null;
    }

    /**
     * Rozhodnutí pro celou stránku seznamu jedním dotazem. Seznam počítá
     * `can_delete` pro každý řádek, takže dotaz na řádek by z něj udělal N+1.
     *
     * @param list<int> $ids
     * @return array<int,PayrollDeletionDecision>
     */
    public function canDeleteMany(int $supplierId, array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $unique[$id] = $id;
            }
        }
        if ($unique === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($unique), '?'));
        $stmt = $this->db->pdo()->prepare(static::decisionSql($placeholders));
        $stmt->execute(array_merge([$supplierId], array_values($unique)));

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[(int) ($row['decision_id'] ?? 0)] = static::decide($row);
        }

        return $result;
    }

    /**
     * Doplní `can_delete` a `delete_blocker` do řádků seznamu — jedním dotazem,
     * ne dotazem na řádek. Obojí musí být v SEZNAMU I V DETAILU, jinak by
     * frontend nabízel akci, která na kliknutí spadne, nebo naopak schoval akci,
     * která projde.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function decorate(int $supplierId, array $rows, string $idKey = 'id'): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) ($row[$idKey] ?? 0);
        }
        $decisions = $this->canDeleteMany($supplierId, $ids);

        $result = [];
        foreach ($rows as $row) {
            $decision = $decisions[(int) ($row[$idKey] ?? 0)] ?? null;
            $row['can_delete'] = $decision !== null && $decision->canDelete;
            $row['delete_blocker'] = $decision?->blockerPayload();
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function decorateOne(int $supplierId, array $row, string $idKey = 'id'): array
    {
        return $this->decorate($supplierId, [$row], $idKey)[0];
    }

    /**
     * Smaže řádek ve vlastní transakci (nebo savepointu, běží-li už transakce).
     *
     * @return array<string,int> počty smazaného lešení pro audit a potvrzení
     */
    public function delete(
        int $supplierId,
        int $id,
        ?int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_row_delete');
        }

        try {
            $result = $this->deleteLocked($supplierId, $id, $expectedVersion, $userId, $ip, $userAgent);
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_row_delete');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_row_delete');
                $pdo->exec('RELEASE SAVEPOINT payroll_row_delete');
            }
            throw $e;
        }
    }

    /**
     * Musí běžet uvnitř transakce volajícího.
     *
     * @return array<string,int>
     */
    protected function deleteLocked(
        int $supplierId,
        int $id,
        ?int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $row = $this->lock($supplierId, $id);
        if ($row === null) {
            throw new PayrollDeletionNotFoundException(static::notFoundMessage());
        }
        $versionColumn = static::rowVersionColumn();
        $rowVersion = $versionColumn === null ? null : (int) ($row[$versionColumn] ?? 0);
        if ($expectedVersion !== null && $rowVersion !== null && $rowVersion !== $expectedVersion) {
            throw new PayrollDeletionConflictException(
                $rowVersion,
                'Záznam se mezitím změnil. Načtěte ho prosím znovu a zkuste to ještě jednou.',
            );
        }

        // Přeověření POD ZÁMKEM — `can_delete` z vykresleného seznamu už mohlo zestárnout.
        $decision = $this->canDelete($supplierId, $id);
        if ($decision === null) {
            throw new PayrollDeletionNotFoundException(static::notFoundMessage());
        }
        if (!$decision->canDelete) {
            throw new PayrollDeletionException(
                (string) $decision->blockerCode,
                (string) $decision->blockerMessage,
            );
        }

        $this->beforeGuardedDelete($supplierId, $id, $row);

        $parameters = [$supplierId, $id];
        if ($rowVersion !== null) {
            $parameters[] = $rowVersion;
        }
        $guard = $this->db->pdo()->prepare(static::guardedDeleteSql());
        $guard->execute($parameters);
        if ($guard->rowCount() !== 1) {
            throw new PayrollDeletionException(
                'payroll_row_delete_conflict',
                'Záznam se mezitím změnil — někdo k němu mezitím přidal navázaný zápis. '
                . 'Načtěte stránku znovu a zkuste to prosím ještě jednou.',
            );
        }

        $this->afterGuardedDelete($supplierId, $id, $row);

        // Řádek zmizel, ale fakt, že existoval a kdo ho smazal, zůstat musí.
        $this->activityLogger->log(
            static::auditAction(),
            $userId,
            static::auditEntity(),
            $id,
            array_merge(static::auditPayload($row), ['cascade' => $decision->cascade]),
            $ip,
            $userAgent,
            $supplierId,
        );

        return $decision->cascade;
    }

    /**
     * Úklid lešení, které má FK RESTRICT a musí zmizet PŘED řádkem samotným.
     *
     * @param array<string,string|int|null> $row
     */
    protected function beforeGuardedDelete(int $supplierId, int $id, array $row): void
    {
    }

    /**
     * Úklid záznamů, které na mazaný řádek nedrží FK, ale mazaný řádek drží FK
     * na ně — musí tedy zmizet AŽ PO něm.
     *
     * @param array<string,string|int|null> $row
     */
    protected function afterGuardedDelete(int $supplierId, int $id, array $row): void
    {
    }

    /** @return array<string,string|int|null>|null */
    protected function lock(int $supplierId, int $id): ?array
    {
        $columns = implode(', ', static::lockedColumns());
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$columns}\n  FROM " . static::table()
            . "\n WHERE supplier_id = ? AND id = ?\n FOR UPDATE"
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || $value === null)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    protected static function decide(array $row): PayrollDeletionDecision
    {
        foreach (static::blockers() as $alias => $blocker) {
            if ((int) ($row["blocker_{$alias}"] ?? 0) > 0) {
                return PayrollDeletionDecision::blocked($blocker['code'], $blocker['message']);
            }
        }

        $cascade = [];
        foreach (array_keys(static::cascade()) as $alias) {
            $cascade[$alias] = (int) ($row["cascade_{$alias}"] ?? 0);
        }

        return PayrollDeletionDecision::allowed($cascade);
    }

    protected static function decisionSql(string $placeholders): string
    {
        $alias = static::rowAlias();
        $columns = ["{$alias}.id AS decision_id"];
        foreach (static::blockers() as $key => $blocker) {
            $columns[] = '(' . $blocker['sql'] . ") AS blocker_{$key}";
        }
        foreach (static::cascade() as $key => $expression) {
            $columns[] = '(' . $expression . ") AS cascade_{$key}";
        }

        return 'SELECT ' . implode(",\n       ", $columns)
            . "\n  FROM " . static::table() . " {$alias}"
            . "\n WHERE {$alias}.supplier_id = ?"
            . "\n   AND {$alias}.id IN ({$placeholders})";
    }

    /**
     * DELETE podmíněný VŠEMI blokátory najednou. Když mezi rozhodnutím a mazáním
     * vznikl pohyb, smaže se 0 řádků a volající to ohlásí jako souběh — místo
     * aby uživatel dostal syrovou chybu cizího klíče z databáze.
     */
    protected static function guardedDeleteSql(): string
    {
        $alias = static::rowAlias();
        $conditions = [];
        $version = static::rowVersionColumn();
        if ($version !== null) {
            $conditions[] = "   AND {$alias}.{$version} = ?";
        }
        foreach (static::blockers() as $blocker) {
            $conditions[] = '   AND NOT (' . $blocker['sql'] . ')';
        }

        return "DELETE {$alias}\n  FROM " . static::table() . " {$alias}"
            . "\n WHERE {$alias}.supplier_id = ?"
            . "\n   AND {$alias}.id = ?"
            . ($conditions === [] ? '' : "\n" . implode("\n", $conditions));
    }

    /**
     * Existuje pro dané období tenanta schválená revize mzdového běhu, která
     * zahrnuje tenhle pracovní vztah? Sdílený blokátor „schválený výpočet".
     */
    protected static function approvedRunForLeaveYearSql(string $alias): string
    {
        return "EXISTS (
                    SELECT 1
                      FROM payroll_run_employments run_employment
                      JOIN payroll_run_revisions revision
                        ON revision.supplier_id = run_employment.supplier_id
                       AND revision.id = run_employment.revision_id
                       AND revision.status = 'approved'
                      JOIN payroll_runs run
                        ON run.supplier_id = revision.supplier_id
                       AND run.id = revision.run_id
                     WHERE run_employment.supplier_id = {$alias}.supplier_id
                       AND run_employment.employment_id = {$alias}.employment_id
                       AND YEAR(run.period_start) = {$alias}.leave_year
                )";
    }
}
