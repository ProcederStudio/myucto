<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use PDO;

final class FinancialArchiveCloneCapture
{
    private bool $dropped = false;

    private ?TaxSubmissionTransportCloneCapture $transport = null;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
        private readonly string $runToken,
        private readonly string $archiveTable,
        private readonly string $submissionTable,
        private readonly CloneProtectedRows $protectedRows,
        private readonly FinancialSavingsCloneCapture $savings,
    ) {}

    public static function create(PDO $pdo): self
    {
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || !AnonymizationOptions::isSafeDatabaseName($database)) {
            throw new \RuntimeException('Capture finančního archivu vyžaduje vybranou databázi.');
        }
        $suffix = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));
        $archive = '__myucto_archive_capture_' . $suffix;
        $submission = '__myucto_archive_links_' . $suffix;
        $archiveSql = DatabaseCloner::quote($archive);
        $submissionSql = DatabaseCloner::quote($submission);
        $protected = CloneProtectedRows::create($pdo);
        try {
            $pdo->exec("CREATE TABLE {$archiveSql} ("
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'archive_id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NULL, tax_return_id INT UNSIGNED NULL, '
                . 'period_year SMALLINT UNSIGNED NULL, record_version TINYINT UNSIGNED NULL, submission_count INT UNSIGNED NULL, '
                . 'final_snapshot TINYINT UNSIGNED NULL, computed_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'source_sha256 CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, '
                . 'xml_sha256 CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, '
                . 'snapshot_bytes BIGINT UNSIGNED NULL, manifest_bytes BIGINT UNSIGNED NULL, xml_bytes BIGINT UNSIGNED NULL, '
                . 'PRIMARY KEY (run_token, archive_id), KEY idx_archive_owner (run_token, supplier_id, archive_id)'
                . ') ENGINE=InnoDB');
            $pdo->prepare("INSERT INTO {$archiveSql} (run_token, archive_id) VALUES (?, 0)")->execute([$token]);
            $pdo->exec("CREATE TABLE {$submissionSql} ("
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'archive_id BIGINT UNSIGNED NOT NULL, tax_submission_id INT UNSIGNED NOT NULL, '
                . 'supplier_id INT UNSIGNED NOT NULL, source_xml_sha256 CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, '
                . 'PRIMARY KEY (run_token, archive_id, tax_submission_id), '
                . 'KEY idx_submission_owner (run_token, tax_submission_id, archive_id)'
                . ') ENGINE=InnoDB');
        } catch (\Throwable $e) {
            try {
                $owner = $pdo->prepare("SELECT COUNT(*) FROM {$archiveSql} WHERE run_token=? AND archive_id=0");
                $owner->execute([$token]);
                if ((int) $owner->fetchColumn() === 1) {
                    $pdo->exec("DROP TABLE {$archiveSql}");
                }
            } catch (\PDOException) {
            }
            $protected->drop();
            throw $e;
        }

        try {
            $savings = FinancialSavingsCloneCapture::create($pdo, $archive, $token, $protected);
        } catch (\Throwable $e) {
            try {
                $owner = $pdo->prepare("SELECT COUNT(*) FROM {$archiveSql} WHERE run_token=? AND archive_id=0");
                $owner->execute([$token]);
                if ((int) $owner->fetchColumn() === 1) {
                    $pdo->exec("DROP TABLE {$submissionSql}");
                    $pdo->exec("DROP TABLE {$archiveSql}");
                }
            } finally {
                $protected->drop();
            }
            throw $e;
        }

        return new self($pdo, $database, $token, $archive, $submission, $protected, $savings);
    }

    public function protectedRows(): CloneProtectedRows
    {
        return $this->protectedRows;
    }

    public function savings(): FinancialSavingsCloneCapture
    {
        return $this->savings;
    }

    public function captureSavings(): void
    {
        $this->assertActive();
        $this->savings->capture();
    }

    public function captureTransport(): void
    {
        $this->assertActive();
        if ($this->transport !== null) {
            throw new \LogicException('Transport podání už byl zachycen.');
        }
        $this->transport = TaxSubmissionTransportCloneCapture::capture(
            $this->pdo,
            $this->submissionTable,
            $this->runToken,
        );
    }

    public function transport(): TaxSubmissionTransportCloneCapture
    {
        $this->assertActive();
        return $this->transport ?? throw new \LogicException('Transport podání ještě nebyl zachycen.');
    }

    /**
     * @param list<array{archive_id:int,supplier_id:int,tax_return_id:int,period_year:int,record_version:?int,final_snapshot:bool,computed_sha256:?string,snapshot_sha256:string,source_sha256:string,xml_sha256:string,snapshot_bytes:int,manifest_bytes:int,xml_bytes:int}> $rows
     */
    public function addArchives(array $rows): void
    {
        $this->assertActive();
        if ($rows === [] || count($rows) > 500) {
            throw new \InvalidArgumentException('Dávka archivů musí obsahovat 1 až 500 řádků.');
        }
        $parameters = [];
        foreach ($rows as $row) {
            if ($row['archive_id'] <= 0 || $row['supplier_id'] <= 0 || $row['tax_return_id'] <= 0) {
                throw new \InvalidArgumentException('Archiv má neplatnou identitu.');
            }
            array_push(
                $parameters,
                $this->runToken,
                $row['archive_id'],
                $row['supplier_id'],
                $row['tax_return_id'],
                $row['period_year'],
                $row['record_version'],
                $row['final_snapshot'] ? 1 : 0,
                $row['computed_sha256'],
                $row['snapshot_sha256'],
                $row['source_sha256'],
                $row['xml_sha256'],
                $row['snapshot_bytes'],
                $row['manifest_bytes'],
                $row['xml_bytes'],
            );
        }
        $values = implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
        $this->pdo->prepare('INSERT INTO ' . DatabaseCloner::quote($this->archiveTable)
            . " (run_token,archive_id,supplier_id,tax_return_id,period_year,record_version,final_snapshot,computed_sha256,snapshot_sha256,source_sha256,xml_sha256,snapshot_bytes,manifest_bytes,xml_bytes) VALUES {$values}")
            ->execute($parameters);
    }

    public function linkSubmissions(): void
    {
        $this->assertActive();
        $archives = DatabaseCloner::quote($this->archiveTable);
        $links = DatabaseCloner::quote($this->submissionTable);
        $statement = $this->pdo->prepare(
            "INSERT INTO {$links} (run_token,archive_id,tax_submission_id,supplier_id,source_xml_sha256) "
            . "SELECT c.run_token,c.archive_id,s.id,s.supplier_id,c.xml_sha256 FROM {$archives} c "
            . "JOIN tax_submissions s FORCE INDEX (idx_tax_submission_archive_xml) ON s.supplier_id=c.supplier_id AND s.form_code='dppdp9' "
            . 'AND s.period_year=c.period_year AND s.xml_sha256=c.xml_sha256 '
            . 'AND SHA2(CAST(s.xml_content AS BINARY),256)=c.xml_sha256 '
            . 'WHERE c.run_token=? AND c.archive_id>0',
        );
        $statement->execute([$this->runToken]);
        $missing = $this->pdo->prepare(
            "SELECT c.archive_id FROM {$archives} c LEFT JOIN {$links} l ON l.run_token=c.run_token AND l.archive_id=c.archive_id "
            . 'WHERE c.run_token=? AND c.archive_id>0 AND l.tax_submission_id IS NULL LIMIT 1',
        );
        $missing->execute([$this->runToken]);
        if ($missing->fetchColumn() !== false) {
            throw new \LogicException('DPPO 504 archiv nemá shodnou historii tax_submissions.');
        }
        $counts = $this->pdo->prepare(
            "UPDATE {$archives} c JOIN (SELECT run_token,archive_id,COUNT(*) submission_count FROM {$links} WHERE run_token=? GROUP BY run_token,archive_id) l "
            . 'ON l.run_token=c.run_token AND l.archive_id=c.archive_id SET c.submission_count=l.submission_count WHERE c.run_token=? AND c.archive_id>0',
        );
        $counts->execute([$this->runToken, $this->runToken]);
        $last = 0;
        do {
            $page = $this->pdo->prepare(
                "SELECT DISTINCT tax_submission_id FROM {$links} WHERE run_token=? AND tax_submission_id>? ORDER BY tax_submission_id LIMIT 500",
            );
            $page->execute([$this->runToken, $last]);
            $ids = array_map('intval', $page->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $this->protectedRows->protectMany(FinancialArchiveProtectedTable::TAX_SUBMISSION, $ids);
                $last = $ids[array_key_last($ids)];
            }
        } while (count($ids) === 500);
    }

    public function archiveCount(): int
    {
        return $this->countRows($this->archiveTable, 'archive_id > 0');
    }

    public function submissionCount(): int
    {
        return $this->countRows($this->submissionTable, '1 = 1');
    }

    /** @return iterable<CapturedFinancialArchive> */
    public function archivePages(int $pageSize = 500): iterable
    {
        $this->assertActive();
        if ($pageSize < 1 || $pageSize > 500) {
            throw new \InvalidArgumentException('Neplatná velikost bounded výběru archivů.');
        }
        $lastSupplier = 0;
        $lastArchive = 0;
        do {
            $statement = $this->pdo->prepare('SELECT archive_id,supplier_id,tax_return_id,period_year,submission_count,final_snapshot,computed_sha256,'
                . 'snapshot_sha256,source_sha256,xml_sha256 FROM ' . DatabaseCloner::quote($this->archiveTable)
                . ' WHERE run_token=? AND archive_id>0 AND (supplier_id>? OR (supplier_id=? AND archive_id>?))'
                . ' ORDER BY supplier_id,archive_id LIMIT ' . $pageSize);
            $statement->execute([$this->runToken, $lastSupplier, $lastSupplier, $lastArchive]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $lastSupplier = (int) $row['supplier_id'];
                $lastArchive = (int) $row['archive_id'];
                yield new CapturedFinancialArchive(
                    archiveId: $lastArchive,
                    supplierId: $lastSupplier,
                    taxReturnId: (int) $row['tax_return_id'],
                    periodYear: (int) $row['period_year'],
                    submissionCount: (int) $row['submission_count'],
                    snapshotSha256: (string) $row['snapshot_sha256'],
                    sourceSha256: (string) $row['source_sha256'],
                    xmlSha256: (string) $row['xml_sha256'],
                    finalSnapshot: (int) $row['final_snapshot'] === 1,
                    computedSha256: $row['computed_sha256'] === null ? null : (string) $row['computed_sha256'],
                );
            }
        } while (count($rows) === $pageSize);
    }

    /** @return iterable<array{tax_submission_id:string,supplier_id:string,source_xml_sha256:string}> */
    public function submissionPages(int $archiveId, int $pageSize = 500): iterable
    {
        $this->assertActive();
        if ($archiveId <= 0 || $pageSize < 1 || $pageSize > 500) {
            throw new \InvalidArgumentException('Neplatný bounded výběr vazeb podání.');
        }
        $last = 0;
        do {
            $statement = $this->pdo->prepare('SELECT tax_submission_id,supplier_id,source_xml_sha256 FROM '
                . DatabaseCloner::quote($this->submissionTable)
                . ' WHERE run_token=? AND archive_id=? AND tax_submission_id>? ORDER BY tax_submission_id LIMIT ' . $pageSize);
            $statement->execute([$this->runToken, $archiveId, $last]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $last = (int) $row['tax_submission_id'];
                yield $row;
            }
        } while (count($rows) === $pageSize);
    }

    public function assertComplete(): never
    {
        throw new \LogicException(
            'Anonymizace finančního archivu DPPO 504 a daňových úspor není podporována. '
            . 'Zdrojová databáze zůstala zachovaná. Financial archive clone complete verify is not available.',
        );
    }

    public function drop(): void
    {
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($this->archiveTable)
            . ' WHERE run_token=? AND archive_id=0');
        $statement->execute([$this->runToken]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Nelze doložit vlastnictví capture stagingu.');
        }
        $this->transport?->drop();
        $this->savings->drop();
        $this->pdo->exec('DROP TABLE ' . DatabaseCloner::quote($this->submissionTable));
        $this->pdo->exec('DROP TABLE ' . DatabaseCloner::quote($this->archiveTable));
        $this->protectedRows->drop();
        $this->dropped = true;
    }

    private function countRows(string $table, string $predicate): int
    {
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($table)
            . " WHERE run_token=? AND {$predicate}");
        $statement->execute([$this->runToken]);

        return (int) $statement->fetchColumn();
    }

    private function assertActive(): void
    {
        if ($this->dropped) {
            throw new \LogicException('Capture finančního archivu už byl odstraněn.');
        }
        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->database) {
            throw new \RuntimeException('Capture finančního archivu patří jiné databázi.');
        }
    }
}
