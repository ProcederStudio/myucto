<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

final class PayrollSubmissionArtifactDownloadGrantRepository
{
    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

    public function currentUtcDateTime(): \DateTimeImmutable
    {
        $statement = $this->db->pdo()->query('SELECT UTC_TIMESTAMP(6)');
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
            $savepoint = 'payroll_submission_artifact_grant_'
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

    /**
     * @return array{
     *   artifact_id:int,submission_id:int,environment:string,
     *   artifact_kind:string,mime_type:string,byte_size:int,
     *   artifact_sha256:string,agenda_code:string,period_start:string
     * }|null
     */
    public function lockArtifact(
        int $supplierId,
        int $submissionId,
        int $artifactId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT artifact.id AS artifact_id, artifact.submission_id,
                    artifact.environment, artifact.artifact_kind,
                    artifact.mime_type, artifact.byte_size,
                    artifact.artifact_sha256, obligation.agenda_code,
                    obligation.period_start
               FROM payroll_submission_artifacts artifact
               JOIN payroll_submissions submission
                 ON submission.supplier_id = artifact.supplier_id
                AND submission.environment = artifact.environment
                AND submission.id = artifact.submission_id
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE artifact.supplier_id = ?
                AND artifact.submission_id = ?
                AND artifact.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $submissionId, $artifactId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return self::artifactRow($row);
    }

    public function insert(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $artifactId,
        int $userId,
        string $tokenHash,
        string $createdAt,
        string $expiresAt,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_artifact_download_grants
                (supplier_id, environment, submission_id, artifact_id,
                 user_id, token_hash, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $artifactId,
            $userId,
            $tokenHash,
            $createdAt,
            $expiresAt,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array{
     *   grant_id:int,expires_at:string,used_at:?string,
     *   artifact_id:int,submission_id:int,environment:string,
     *   artifact_kind:string,mime_type:string,byte_size:int,
     *   artifact_sha256:string,agenda_code:string,period_start:string
     * }|null
     */
    public function lockForConsume(
        int $supplierId,
        int $submissionId,
        int $artifactId,
        int $userId,
        string $tokenHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT download_grant.id AS grant_id,
                    download_grant.expires_at, download_grant.used_at,
                    artifact.id AS artifact_id, artifact.submission_id,
                    artifact.environment, artifact.artifact_kind,
                    artifact.mime_type, artifact.byte_size,
                    artifact.artifact_sha256, obligation.agenda_code,
                    obligation.period_start
               FROM payroll_submission_artifact_download_grants download_grant
               JOIN payroll_submission_artifacts artifact
                 ON artifact.supplier_id = download_grant.supplier_id
                AND artifact.environment = download_grant.environment
                AND artifact.submission_id = download_grant.submission_id
                AND artifact.id = download_grant.artifact_id
               JOIN payroll_submissions submission
                 ON submission.supplier_id = artifact.supplier_id
                AND submission.environment = artifact.environment
                AND submission.id = artifact.submission_id
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE download_grant.supplier_id = ?
                AND download_grant.submission_id = ?
                AND download_grant.artifact_id = ?
                AND download_grant.user_id = ?
                AND download_grant.token_hash = ?
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $submissionId,
            $artifactId,
            $userId,
            $tokenHash,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row);
        $artifact = self::artifactRow($row);

        return [
            'grant_id' => self::integer($row, 'grant_id'),
            'expires_at' => self::string($row, 'expires_at'),
            'used_at' => self::nullableString($row, 'used_at'),
            ...$artifact,
        ];
    }

    public function consume(int $grantId, string $usedAt): bool
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_artifact_download_grants
                SET used_at = ?
              WHERE id = ? AND used_at IS NULL AND expires_at >= ?',
        );
        $statement->execute([$usedAt, $grantId, $usedAt]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return array{
     *   artifact_id:int,submission_id:int,environment:string,
     *   artifact_kind:string,mime_type:string,byte_size:int,
     *   artifact_sha256:string,agenda_code:string,period_start:string
     * }
     */
    private static function artifactRow(mixed $value): array
    {
        $row = self::associativeRow($value);

        return [
            'artifact_id' => self::integer($row, 'artifact_id'),
            'submission_id' => self::integer($row, 'submission_id'),
            'environment' => self::string($row, 'environment'),
            'artifact_kind' => self::string($row, 'artifact_kind'),
            'mime_type' => self::string($row, 'mime_type'),
            'byte_size' => self::integer($row, 'byte_size'),
            'artifact_sha256' => self::hash($row, 'artifact_sha256'),
            'agenda_code' => self::string($row, 'agenda_code'),
            'period_start' => self::string($row, 'period_start'),
        ];
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
        if (!is_int($normalized)) {
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
                'Databáze vrátila neplatný download grant artefaktu.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Download grant artefaktu nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
