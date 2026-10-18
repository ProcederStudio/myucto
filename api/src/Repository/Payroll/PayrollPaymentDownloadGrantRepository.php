<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

final class PayrollPaymentDownloadGrantRepository
{
    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

    public function hasActiveTransaction(): bool
    {
        return $this->db->pdo()->inTransaction();
    }

    public function currentUtcDateTime(): \DateTimeImmutable
    {
        $statement = $this->db->pdo()->query(
            'SELECT UTC_TIMESTAMP(6)',
        );
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException(
                'Databázový čas download grantu není dostupný.',
            );
        }
        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException(
                'Databázový čas download grantu není dostupný.',
            );
        }

        return new \DateTimeImmutable(
            $value,
            new \DateTimeZone('UTC'),
        );
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_export_grant_'
                . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if (TransactionFailure::rollbackIfAborted($pdo, $exception)) {
                throw $exception;
            }
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    public function insert(
        int $supplierId,
        int $exportId,
        int $userId,
        string $tokenHash,
        string $createdAt,
        string $expiresAt,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_export_download_grants
                (supplier_id, export_id, user_id, token_hash,
                 created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $exportId,
            $userId,
            $tokenHash,
            $createdAt,
            $expiresAt,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array{
     *   grant_id:int,
     *   export_id:int,
     *   expires_at:string,
     *   used_at:?string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   storage_key:string,
     *   suggested_filename:string
     * }|null
     */
    public function lockForConsume(
        int $supplierId,
        int $userId,
        string $tokenHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT payment_grant.id, payment_grant.export_id,
                    payment_grant.expires_at, payment_grant.used_at,
                    payment_export.file_sha256, payment_export.size_bytes,
                    payment_export.mime_type, payment_export.storage_key,
                    payment_export.suggested_filename
               FROM payroll_payment_export_download_grants payment_grant
               JOIN payroll_payment_exports payment_export
                 ON payment_export.supplier_id =
                    payment_grant.supplier_id
                AND payment_export.id = payment_grant.export_id
              WHERE payment_grant.supplier_id = ?
                AND payment_grant.user_id = ?
                AND payment_grant.token_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $userId, $tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row);

        return [
            'grant_id' => self::integer($row, 'id'),
            'export_id' => self::integer($row, 'export_id'),
            'expires_at' => self::string($row, 'expires_at'),
            'used_at' => self::nullableString($row, 'used_at'),
            'file_sha256' => self::hash($row, 'file_sha256'),
            'size_bytes' => self::integer($row, 'size_bytes'),
            'mime_type' => self::string($row, 'mime_type'),
            'storage_key' => self::hash($row, 'storage_key'),
            'suggested_filename' => self::string(
                $row,
                'suggested_filename',
            ),
        ];
    }

    public function consume(int $grantId, string $usedAt): bool
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_payment_export_download_grants
                SET used_at = ?
              WHERE id = ? AND used_at IS NULL AND expires_at >= ?',
        );
        $statement->execute([$usedAt, $grantId, $usedAt]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(
        array $row,
        string $field,
    ): ?string {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není text.",
            );
        }

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::string($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function associativeRow(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný download grant.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Download grant nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
