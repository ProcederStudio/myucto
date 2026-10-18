<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

/**
 * Zrušení a smazání pracovní cesty.
 *
 * ── Dvě různé akce, dva různé případy ─────────────────────────────────────────
 * `Smazat` je pro cestu, která vůbec neměla vzniknout — rozpracovaný koncept bez
 * jediného důsledku. `Zrušit` je pro cestu, která vznikla správně, ale nakonec se
 * nekonala: stav `cancelled` v modelu existuje a UI ho barví, takže zrušení nechá
 * v evidenci stopu a nemusí řešit cizí klíče.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * Peníze: jakmile se cesta vyúčtuje (`settled`), vznikne z ní mzdový vstup
 * s náhradou (`payroll_travel_compensation_links` → `payroll_inputs`). Od té chvíle
 * nejde ani smazat, ani zrušit — opravu je nutné udělat stornem toho vstupu.
 * Schválený výpočet: schválená cesta nese neměnný protokol výpočtu, takže se
 * nemaže, jen ruší.
 *
 * Položky vyúčtování a bezplatná jídla jsou vlastní lešení cesty a mají
 * ON DELETE CASCADE — nejsou pohyb, zmizí spolu s ní.
 */
final class PayrollBusinessTripDeletionRepository extends PayrollRowDeletionRepository
{
    private const MATERIALIZED_SQL = "EXISTS (
                    SELECT 1
                      FROM payroll_travel_compensation_links link
                     WHERE link.supplier_id = trip.supplier_id
                       AND link.trip_id = trip.id
                )";

    private const MATERIALIZED_MESSAGE = 'Z pracovní cesty už vznikl mzdový vstup s cestovní '
        . 'náhradou. Jde o peníze, takže cestu smazat ani zrušit nelze — opravu udělejte '
        . 'stornem toho mzdového vstupu.';

    protected static function blockers(): array
    {
        return [
            'materialized' => [
                'code' => 'payroll_business_trip_materialized',
                'message' => self::MATERIALIZED_MESSAGE,
                'sql' => self::MATERIALIZED_SQL,
            ],
            'settled' => [
                'code' => 'payroll_business_trip_settled',
                'message' => 'Pracovní cesta je vyúčtovaná a promítnutá do mezd. '
                    . 'Smazat ji nelze.',
                'sql' => "trip.status = 'settled'",
            ],
            'approved' => [
                'code' => 'payroll_business_trip_approved',
                'message' => 'Pracovní cesta je schválená a nese neměnný protokol výpočtu '
                    . 'náhrad. Smazat ji nelze — pokud se nakonec nekonala, použijte '
                    . 'Zrušit cestu.',
                'sql' => "trip.status = 'approved'",
            ],
            'cancelled' => [
                'code' => 'payroll_business_trip_cancelled',
                'message' => 'Zrušená pracovní cesta zůstává v evidenci jako stopa po tom, '
                    . 'že se plánovala. Smazat ji už nelze.',
                'sql' => "trip.status = 'cancelled'",
            ],
        ];
    }

    protected static function cascade(): array
    {
        return [
            'items' => 'SELECT COUNT(*) FROM payroll_business_trip_items item
                         WHERE item.supplier_id = trip.supplier_id AND item.trip_id = trip.id',
            'free_meals' => 'SELECT COUNT(*) FROM payroll_business_trip_free_meals meal
                              WHERE meal.supplier_id = trip.supplier_id AND meal.trip_id = trip.id',
        ];
    }

    protected static function table(): string
    {
        return 'payroll_business_trips';
    }

    protected static function rowAlias(): string
    {
        return 'trip';
    }

    protected static function notFoundMessage(): string
    {
        return 'Pracovní cesta nebyla nalezena.';
    }

    protected static function auditAction(): string
    {
        return 'payroll.travel.deleted';
    }

    protected static function auditEntity(): string
    {
        return 'payroll_business_trip';
    }

    protected static function lockedColumns(): array
    {
        return [
            'id',
            'employee_id',
            'employment_id',
            'status',
            'settlement_period_start',
            'departure_at_utc',
            'timezone_name',
            'destination_place',
            'row_version',
        ];
    }

    protected static function auditPayload(array $row): array
    {
        return [
            'employee_id' => (int) ($row['employee_id'] ?? 0),
            'employment_id' => (int) ($row['employment_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'settlement_period_start' => $row['settlement_period_start'] ?? null,
            'departure_at_utc' => $row['departure_at_utc'] ?? null,
            'timezone_name' => $row['timezone_name'] ?? null,
            'destination_place' => $row['destination_place'] ?? null,
            'row_version' => (int) ($row['row_version'] ?? 0),
        ];
    }

    /**
     * Zruší cestu stavem. Rozpracovaná i schválená cesta jde zrušit, vyúčtovaná
     * ne — z té už jsou peníze. Opakované zrušení je idempotentní: vrátí `false`
     * a nic nemění.
     */
    public function cancel(
        int $supplierId,
        int $id,
        ?int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): bool {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_trip_cancel');
        }

        try {
            $changed = $this->cancelLocked($supplierId, $id, $expectedVersion, $userId, $ip, $userAgent);
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_trip_cancel');
            }

            return $changed;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_trip_cancel');
                $pdo->exec('RELEASE SAVEPOINT payroll_trip_cancel');
            }
            throw $e;
        }
    }

    private function cancelLocked(
        int $supplierId,
        int $id,
        ?int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): bool {
        $row = $this->lock($supplierId, $id);
        if ($row === null) {
            throw new PayrollDeletionNotFoundException(self::notFoundMessage());
        }
        $status = (string) ($row['status'] ?? '');
        $rowVersion = (int) ($row['row_version'] ?? 0);
        if ($status === 'cancelled') {
            return false;
        }
        if ($expectedVersion !== null && $rowVersion !== $expectedVersion) {
            throw new PayrollDeletionConflictException(
                $rowVersion,
                'Pracovní cesta se mezitím změnila. Načtěte ji prosím znovu '
                . 'a zkuste to ještě jednou.',
            );
        }
        if ($status === 'settled' || $this->isMaterialized($supplierId, $id)) {
            throw new PayrollDeletionException(
                'payroll_business_trip_materialized',
                self::MATERIALIZED_MESSAGE,
            );
        }

        $update = $this->db->pdo()->prepare(
            "UPDATE payroll_business_trips trip
                SET trip.status = 'cancelled', trip.row_version = trip.row_version + 1
              WHERE trip.supplier_id = ?
                AND trip.id = ?
                AND trip.row_version = ?
                AND trip.status IN ('draft', 'approved')
                AND NOT (" . self::MATERIALIZED_SQL . ')'
        );
        $update->execute([$supplierId, $id, $rowVersion]);
        if ($update->rowCount() !== 1) {
            throw new PayrollDeletionException(
                'payroll_business_trip_cancel_conflict',
                'Pracovní cesta se mezitím změnila — nejspíš ji někdo vyúčtoval. '
                . 'Načtěte stránku znovu a zkuste to prosím ještě jednou.',
            );
        }

        $this->activityLogger->log(
            'payroll.travel.cancelled',
            $userId,
            self::auditEntity(),
            $id,
            array_merge(self::auditPayload($row), ['previous_status' => $status]),
            $ip,
            $userAgent,
            $supplierId,
        );

        return true;
    }

    private function isMaterialized(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_travel_compensation_links link
              WHERE link.supplier_id = ? AND link.trip_id = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $id]);

        return $stmt->fetch(PDO::FETCH_NUM) !== false;
    }
}
