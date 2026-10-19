<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonProfile;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Anonymization\Streaming\ChunkedJsonColumnReader;
use MyInvoice\Service\Anonymization\Streaming\FinancialArchiveJsonColumn;
use MyInvoice\Service\Anonymization\Streaming\JsonScalarTransformer;
use MyInvoice\Service\Anonymization\Streaming\StreamingJsonTransform;
use PDO;

final readonly class ProtectedFinancialJsonRewriter
{
    public function __construct(private Connection $db) {}

    public function rewrite(
        FinancialArchiveJsonColumn $source,
        int $supplierId,
        int $id,
        string $expectedSourceSha256,
        JsonScalarTransformer $transformer,
    ): ProtectedFinancialJsonRewriteResult {
        if ($supplierId < 1 || $id < 1 || preg_match('/^[a-f0-9]{64}$/D', $expectedSourceSha256) !== 1) {
            throw new \InvalidArgumentException('Protected JSON rewrite vyžaduje přesnou identitu a fyzický otisk.');
        }
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Protected JSON rewrite vyžaduje transakci vlastníka clone grafu.');
        }
        $table = $source->table();
        $column = $source->column();
        $metadata = $pdo->prepare(
            "SELECT OCTET_LENGTH({$column}) bytes,LOWER(SHA2(CAST({$column} AS BINARY),256)) sha256 "
            . "FROM {$table} WHERE supplier_id=? AND id=? FOR UPDATE",
        );
        $metadata->execute([$supplierId, $id]);
        $row = $metadata->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_numeric($row['bytes'] ?? null) || !is_string($row['sha256'] ?? null)) {
            throw new \RuntimeException('Protected JSON řádek nebyl nalezen pro přesného tenanta.');
        }
        if (!hash_equals($expectedSourceSha256, strtolower($row['sha256']))) {
            throw new \RuntimeException('Protected JSON má jiný fyzický otisk než capture důkaz.');
        }

        $output = tmpfile();
        if (!is_resource($output)) {
            throw new \RuntimeException('Protected JSON spool nelze otevřít.');
        }
        try {
            $chunks = (new ChunkedJsonColumnReader($pdo))->read($source, $supplierId, $id);
            $transformed = (new StreamingJsonTransform())->transform(
                $chunks,
                $transformer,
                CanonicalJsonProfile::NONPROFIT,
            );
            $result = (new CanonicalJsonSpool())->canonicalize(
                $transformed,
                CanonicalJsonProfile::NONPROFIT,
                $output,
            );
            rewind($output);
            $update = $pdo->prepare(
                "UPDATE {$table} SET {$column}=? WHERE supplier_id=? AND id=? "
                . "AND LOWER(SHA2(CAST({$column} AS BINARY),256))=?",
            );
            $lob = $output;
            $update->bindParam(1, $lob, PDO::PARAM_LOB);
            $update->bindValue(2, $supplierId, PDO::PARAM_INT);
            $update->bindValue(3, $id, PDO::PARAM_INT);
            $update->bindValue(4, $expectedSourceSha256, PDO::PARAM_STR);
            $update->execute();

            $verify = $pdo->prepare(
                "SELECT LOWER(SHA2(CAST({$column} AS BINARY),256)) FROM {$table} WHERE supplier_id=? AND id=?",
            );
            $verify->execute([$supplierId, $id]);
            $actual = $verify->fetchColumn();
            if (!is_string($actual) || !hash_equals($result->sha256, strtolower($actual))) {
                throw new \RuntimeException('Protected JSON CAS zápis neodpovídá transformovanému obsahu.');
            }

            return new ProtectedFinancialJsonRewriteResult(
                $expectedSourceSha256,
                $result->sha256,
                (int) $row['bytes'],
                $result->bytes,
                $result->nodes,
                $result->members,
            );
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }
}
