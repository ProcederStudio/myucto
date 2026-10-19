<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use PDO;

final class TaxSubmissionTransportCloneCapture
{
    private const PAGE = 500;

    private bool $dropped = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
        private readonly string $token,
        private readonly string $receiptTable,
        private readonly string $leafTable,
    ) {}

    public static function capture(
        PDO $pdo,
        string $submissionLinkTable,
        string $submissionLinkToken,
    ): self {
        if ($pdo->inTransaction() || preg_match('/^[a-zA-Z0-9_]+$/D', $submissionLinkTable) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $submissionLinkToken) !== 1) {
            throw new \LogicException('Capture transportu podání vyžaduje platný staging mimo transakci.');
        }
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!AnonymizationOptions::isSafeDatabaseName($database)) {
            throw new \LogicException('Capture transportu podání vyžaduje vybranou databázi.');
        }
        $suffix = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));
        $capture = new self(
            $pdo,
            $database,
            $token,
            '__myucto_transport_receipt_' . $suffix,
            '__myucto_transport_leaf_' . $suffix,
        );
        $capture->createTables();
        try {
            $capture->captureRows($submissionLinkTable, $submissionLinkToken);
            return $capture;
        } catch (\Throwable $e) {
            $capture->drop();
            throw $e;
        }
    }

    public function count(): int
    {
        $this->assertActive();
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($this->receiptTable)
            . ' WHERE run_token=? AND marker=0');
        $query->execute([$this->token]);
        return (int) $query->fetchColumn();
    }

    public function assertCurrentProvenance(): void
    {
        $this->assertActive();
        $this->verifyKind(1, 'tax_submission_attempts', 'a', 'a.supplier_id=r.supplier_id AND a.tax_submission_id=r.tax_submission_id', self::attemptLeaf('a'));
        $this->verifyKind(2, 'tax_submission_status_events', 'e', 'e.supplier_id=r.supplier_id AND e.tax_submission_id=r.tax_submission_id', self::statusEventLeaf('e'));
        $this->verifyKind(3, 'tax_submission_artifacts', 'a', 'a.supplier_id=r.supplier_id AND a.tax_submission_id=r.tax_submission_id', self::artifactLeaf('a'));
        $this->verifyKind(4, 'submission_outbox', 'o', "o.supplier_id=r.supplier_id AND o.artifact_kind='tax_submission' AND o.artifact_id=r.tax_submission_id", self::outboxLeaf('o'));
        $this->verifyOutboxAttempts();
    }

    /** @return iterable<array<string,mixed>> */
    public function receiptPages(int $pageSize = self::PAGE): iterable
    {
        $this->assertActive();
        if ($pageSize < 1 || $pageSize > self::PAGE) {
            throw new \InvalidArgumentException('Dávka transportních receiptů musí obsahovat 1 až 500 řádků.');
        }
        $last = 0;
        do {
            $statement = $this->pdo->prepare('SELECT tax_submission_id,supplier_id,source_xml_sha256,transport_receipt_root,transport_class,'
                . 'attempt_count,status_event_count,artifact_count,outbox_count,outbox_attempt_count FROM '
                . DatabaseCloner::quote($this->receiptTable)
                . ' WHERE run_token=? AND marker=0 AND tax_submission_id>? ORDER BY tax_submission_id LIMIT ' . $pageSize);
            $statement->execute([$this->token, $last]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $last = (int) $row['tax_submission_id'];
                yield $row;
            }
        } while (count($rows) === $pageSize);
    }

    public function drop(): void
    {
        if ($this->dropped) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Capture transportu podání nelze odstranit uvnitř transakce.');
        }
        $this->assertDatabase();
        $receipt = DatabaseCloner::quote($this->receiptTable);
        $leaf = DatabaseCloner::quote($this->leafTable);
        $receiptProof = $this->pdo->prepare("SELECT COUNT(*) FROM {$receipt} WHERE run_token=? AND marker=1 AND tax_submission_id=0");
        $receiptProof->execute([$this->token]);
        $leafProof = $this->pdo->prepare("SELECT COUNT(*) FROM {$leaf} WHERE run_token=? AND marker=1 AND tax_submission_id=0 AND kind_order=0 AND row_id=0");
        $leafProof->execute([$this->token]);
        if ((int) $receiptProof->fetchColumn() !== 1 || (int) $leafProof->fetchColumn() !== 1) {
            throw new \LogicException('Nelze doložit vlastnictví capture transportu podání.');
        }
        $this->pdo->exec("DROP TABLE {$leaf}");
        $this->pdo->exec("DROP TABLE {$receipt}");
        $this->dropped = true;
    }

    private function createTables(): void
    {
        $receipt = DatabaseCloner::quote($this->receiptTable);
        $leaf = DatabaseCloner::quote($this->leafTable);
        $receiptCreated = false;
        $leafCreated = false;
        try {
            $this->pdo->exec("CREATE TABLE {$receipt} ("
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                . 'tax_submission_id BIGINT UNSIGNED NOT NULL,supplier_id INT UNSIGNED NULL,'
                . 'source_xml_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
                . 'transport_receipt_root CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
                . "transport_class VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
                . 'attempt_count INT UNSIGNED NOT NULL DEFAULT 0,status_event_count INT UNSIGNED NOT NULL DEFAULT 0,'
                . 'artifact_count INT UNSIGNED NOT NULL DEFAULT 0,outbox_count INT UNSIGNED NOT NULL DEFAULT 0,'
                . 'outbox_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,marker TINYINT UNSIGNED NOT NULL,'
                . 'PRIMARY KEY(run_token,tax_submission_id),KEY ix_receipt_supplier(run_token,supplier_id,tax_submission_id)'
                . ') ENGINE=InnoDB');
            $receiptCreated = true;
            $this->pdo->prepare("INSERT INTO {$receipt}(run_token,tax_submission_id,marker) VALUES(?,0,1)")->execute([$this->token]);
            $this->pdo->exec("CREATE TABLE {$leaf} ("
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                . 'tax_submission_id BIGINT UNSIGNED NOT NULL,kind_order TINYINT UNSIGNED NOT NULL,row_id BIGINT UNSIGNED NOT NULL,'
                . 'leaf_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,class_rank TINYINT UNSIGNED NOT NULL,'
                . 'marker TINYINT UNSIGNED NOT NULL,'
                . 'PRIMARY KEY(run_token,tax_submission_id,kind_order,row_id),KEY ix_leaf_kind(run_token,kind_order,row_id)'
                . ') ENGINE=InnoDB');
            $leafCreated = true;
            $this->pdo->prepare("INSERT INTO {$leaf}(run_token,tax_submission_id,kind_order,row_id,class_rank,marker) VALUES(?,0,0,0,0,1)")
                ->execute([$this->token]);
        } catch (\Throwable $e) {
            if ($leafCreated) {
                $this->pdo->exec("DROP TABLE {$leaf}");
            }
            if ($receiptCreated) {
                $this->pdo->exec("DROP TABLE {$receipt}");
            }
            $this->dropped = true;
            throw $e;
        }
    }

    private function captureRows(string $submissionLinkTable, string $submissionLinkToken): void
    {
        $receipt = DatabaseCloner::quote($this->receiptTable);
        $leaf = DatabaseCloner::quote($this->leafTable);
        $links = DatabaseCloner::quote($submissionLinkTable);
        $invalid = $this->pdo->prepare("SELECT tax_submission_id FROM {$links} WHERE run_token=? "
            . "AND (tax_submission_id<=0 OR supplier_id<=0 OR BINARY source_xml_sha256 NOT REGEXP '^[a-f0-9]{64}$') LIMIT 1");
        $invalid->execute([$submissionLinkToken]);
        if ($invalid->fetchColumn() !== false) {
            throw new \LogicException('Transportní vazba podání má neplatnou identitu nebo XML hash.');
        }
        $ambiguous = $this->pdo->prepare("SELECT tax_submission_id FROM {$links} WHERE run_token=? GROUP BY tax_submission_id "
            . 'HAVING COUNT(DISTINCT supplier_id)<>1 OR COUNT(DISTINCT BINARY source_xml_sha256)<>1 LIMIT 1');
        $ambiguous->execute([$submissionLinkToken]);
        if ($ambiguous->fetchColumn() !== false) {
            throw new \LogicException('Transportní vazba podání je nejednoznačná mezi archivy.');
        }
        $insertReceipts = $this->pdo->prepare("INSERT INTO {$receipt}"
            . '(run_token,tax_submission_id,supplier_id,source_xml_sha256,transport_receipt_root,transport_class,marker) '
            . "SELECT ?,l.tax_submission_id,MIN(l.supplier_id),MIN(l.source_xml_sha256),"
            . "SHA2(CONCAT('transport-v1:',l.tax_submission_id,':',MIN(l.source_xml_sha256)),256),'no_transport',0 "
            . "FROM {$links} l WHERE l.run_token=? GROUP BY l.tax_submission_id");
        $insertReceipts->execute([$this->token, $submissionLinkToken]);

        $this->insertAttemptLeaves($receipt, $leaf);
        $this->insertStatusEventLeaves($receipt, $leaf);
        $this->insertArtifactLeaves($receipt, $leaf);
        $this->insertOutboxLeaves($receipt, $leaf);
        $this->insertOutboxAttemptLeaves($receipt, $leaf);
        $this->buildRoots($receipt, $leaf);
        $this->pdo->prepare("UPDATE {$receipt} r LEFT JOIN ("
            . 'SELECT run_token,tax_submission_id,MAX(class_rank) class_rank,'
            . 'SUM(kind_order=1) attempt_count,SUM(kind_order=2) status_event_count,SUM(kind_order=3) artifact_count,'
            . 'SUM(kind_order=4) outbox_count,SUM(kind_order=5) outbox_attempt_count '
            . "FROM {$leaf} WHERE run_token=? AND marker=0 GROUP BY run_token,tax_submission_id"
            . ') a ON a.run_token=r.run_token AND a.tax_submission_id=r.tax_submission_id '
            . "SET r.attempt_count=COALESCE(a.attempt_count,0),r.status_event_count=COALESCE(a.status_event_count,0),"
            . 'r.artifact_count=COALESCE(a.artifact_count,0),r.outbox_count=COALESCE(a.outbox_count,0),'
            . 'r.outbox_attempt_count=COALESCE(a.outbox_attempt_count,0),'
            . "r.transport_class=CASE COALESCE(a.class_rank,0) WHEN 0 THEN 'no_transport' WHEN 1 THEN 'cancelled_before_dispatch' ELSE 'source_provenance' END "
            . 'WHERE r.run_token=? AND r.marker=0')->execute([$this->token, $this->token]);
    }

    private function insertAttemptLeaves(string $receipt, string $leaf): void
    {
        $sql = "INSERT INTO {$leaf}(run_token,tax_submission_id,kind_order,row_id,leaf_sha256,class_rank,marker) "
            . 'SELECT r.run_token,a.tax_submission_id,1,a.id,' . self::attemptLeaf('a') . ','
            . "CASE WHEN a.status IN ('submitting','processing','submitted','confirmed','rejected','uncertain') THEN 2 ELSE 1 END,0 "
            . "FROM {$receipt} r JOIN tax_submission_attempts a ON a.supplier_id=r.supplier_id AND a.tax_submission_id=r.tax_submission_id "
            . 'WHERE r.run_token=? AND r.marker=0';
        $this->pdo->prepare($sql)->execute([$this->token]);
    }

    private function insertStatusEventLeaves(string $receipt, string $leaf): void
    {
        $sql = "INSERT INTO {$leaf}(run_token,tax_submission_id,kind_order,row_id,leaf_sha256,class_rank,marker) "
            . 'SELECT r.run_token,e.tax_submission_id,2,e.id,' . self::statusEventLeaf('e') . ',2,0 '
            . "FROM {$receipt} r JOIN tax_submission_status_events e ON e.supplier_id=r.supplier_id AND e.tax_submission_id=r.tax_submission_id "
            . 'WHERE r.run_token=? AND r.marker=0';
        $this->pdo->prepare($sql)->execute([$this->token]);
    }

    private function insertArtifactLeaves(string $receipt, string $leaf): void
    {
        $sql = "INSERT INTO {$leaf}(run_token,tax_submission_id,kind_order,row_id,leaf_sha256,class_rank,marker) "
            . 'SELECT r.run_token,a.tax_submission_id,3,a.id,' . self::artifactLeaf('a') . ','
            . "CASE WHEN a.artifact_kind='source_xml' THEN 1 ELSE 2 END,0 "
            . "FROM {$receipt} r JOIN tax_submission_artifacts a ON a.supplier_id=r.supplier_id AND a.tax_submission_id=r.tax_submission_id "
            . 'WHERE r.run_token=? AND r.marker=0';
        $this->pdo->prepare($sql)->execute([$this->token]);
    }

    private function insertOutboxLeaves(string $receipt, string $leaf): void
    {
        $sql = "INSERT INTO {$leaf}(run_token,tax_submission_id,kind_order,row_id,leaf_sha256,class_rank,marker) "
            . 'SELECT r.run_token,o.artifact_id,4,o.id,' . self::outboxLeaf('o') . ','
            . "CASE WHEN o.dispatch_state IN ('ready','failed','cancelled') AND o.acceptance_state='unknown' AND o.external_message_id IS NULL THEN 1 ELSE 2 END,0 "
            . "FROM {$receipt} r JOIN submission_outbox o ON o.supplier_id=r.supplier_id AND o.artifact_kind='tax_submission' AND o.artifact_id=r.tax_submission_id "
            . 'WHERE r.run_token=? AND r.marker=0';
        $this->pdo->prepare($sql)->execute([$this->token]);
    }

    private function insertOutboxAttemptLeaves(string $receipt, string $leaf): void
    {
        $sql = "INSERT INTO {$leaf}(run_token,tax_submission_id,kind_order,row_id,leaf_sha256,class_rank,marker) "
            . 'SELECT r.run_token,o.artifact_id,5,a.id,' . self::outboxAttemptLeaf('a') . ',2,0 '
            . "FROM {$receipt} r JOIN submission_outbox o ON o.supplier_id=r.supplier_id AND o.artifact_kind='tax_submission' AND o.artifact_id=r.tax_submission_id "
            . 'JOIN submission_outbox_attempts a ON a.supplier_id=o.supplier_id AND a.outbox_id=o.id '
            . 'WHERE r.run_token=? AND r.marker=0';
        $this->pdo->prepare($sql)->execute([$this->token]);
    }

    private function buildRoots(string $receipt, string $leaf): void
    {
        $lastSubmission = 0;
        $lastKind = 0;
        $lastRow = 0;
        $currentSubmission = null;
        $currentRoot = null;
        $updates = [];
        do {
            $statement = $this->pdo->prepare("SELECT l.tax_submission_id,l.kind_order,l.row_id,l.leaf_sha256,r.transport_receipt_root seed_root "
                . "FROM {$leaf} l JOIN {$receipt} r ON r.run_token=l.run_token AND r.tax_submission_id=l.tax_submission_id "
                . 'WHERE l.run_token=? AND l.marker=0 AND (l.tax_submission_id>? OR (l.tax_submission_id=? AND '
                . '(l.kind_order>? OR (l.kind_order=? AND l.row_id>?)))) '
                . 'ORDER BY l.tax_submission_id,l.kind_order,l.row_id LIMIT ' . self::PAGE);
            $statement->execute([$this->token, $lastSubmission, $lastSubmission, $lastKind, $lastKind, $lastRow]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $submissionId = (int) $row['tax_submission_id'];
                if ($currentSubmission !== $submissionId) {
                    if ($currentSubmission !== null) {
                        $updates[$currentSubmission] = $currentRoot;
                    }
                    $currentSubmission = $submissionId;
                    $currentRoot = (string) $row['seed_root'];
                }
                $currentRoot = hash('sha256', $currentRoot . "\0" . $row['kind_order'] . "\0" . $row['row_id'] . "\0" . $row['leaf_sha256']);
                $lastSubmission = $submissionId;
                $lastKind = (int) $row['kind_order'];
                $lastRow = (int) $row['row_id'];
            }
            if (count($updates) >= self::PAGE) {
                $this->updateRoots($receipt, $updates);
                $updates = [];
            }
        } while (count($rows) === self::PAGE);
        if ($currentSubmission !== null) {
            $updates[$currentSubmission] = $currentRoot;
        }
        $this->updateRoots($receipt, $updates);
    }

    private function verifyKind(int $kind, string $table, string $alias, string $ownerJoin, string $leafExpression): void
    {
        $receipt = DatabaseCloner::quote($this->receiptTable);
        $leaf = DatabaseCloner::quote($this->leafTable);
        $quotedTable = DatabaseCloner::quote($table);
        $last = 0;
        do {
            $statement = $this->pdo->prepare("SELECT {$alias}.id row_id,l.leaf_sha256 expected_leaf,{$leafExpression} actual_leaf "
                . "FROM {$receipt} r JOIN {$quotedTable} {$alias} ON {$ownerJoin} "
                . "LEFT JOIN {$leaf} l ON l.run_token=r.run_token AND l.tax_submission_id=r.tax_submission_id "
                . "AND l.kind_order={$kind} AND l.row_id={$alias}.id AND l.marker=0 "
                . "WHERE r.run_token=? AND r.marker=0 AND {$alias}.id>? ORDER BY {$alias}.id LIMIT " . self::PAGE);
            $statement->execute([$this->token, $last]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (!is_string($row['expected_leaf']) || !is_string($row['actual_leaf'])
                    || !hash_equals($row['expected_leaf'], $row['actual_leaf'])) {
                    throw new \LogicException('Transportní provenance se po capture změnila.');
                }
                $last = (int) $row['row_id'];
            }
        } while (count($rows) === self::PAGE);

        $missing = $this->pdo->prepare("SELECT l.row_id FROM {$leaf} l JOIN {$receipt} r "
            . 'ON r.run_token=l.run_token AND r.tax_submission_id=l.tax_submission_id '
            . "LEFT JOIN {$quotedTable} {$alias} ON {$alias}.id=l.row_id AND {$ownerJoin} "
            . "WHERE l.run_token=? AND l.marker=0 AND l.kind_order={$kind} AND {$alias}.id IS NULL LIMIT 1");
        $missing->execute([$this->token]);
        if ($missing->fetchColumn() !== false) {
            throw new \LogicException('Transportní provenance se po capture změnila.');
        }
    }

    private function verifyOutboxAttempts(): void
    {
        $receipt = DatabaseCloner::quote($this->receiptTable);
        $leaf = DatabaseCloner::quote($this->leafTable);
        $last = 0;
        do {
            $statement = $this->pdo->prepare('SELECT a.id row_id,l.leaf_sha256 expected_leaf,' . self::outboxAttemptLeaf('a') . " actual_leaf "
                . "FROM {$receipt} r JOIN submission_outbox o ON o.supplier_id=r.supplier_id "
                . "AND o.artifact_kind='tax_submission' AND o.artifact_id=r.tax_submission_id "
                . 'JOIN submission_outbox_attempts a ON a.supplier_id=o.supplier_id AND a.outbox_id=o.id '
                . "LEFT JOIN {$leaf} l ON l.run_token=r.run_token AND l.tax_submission_id=r.tax_submission_id "
                . 'AND l.kind_order=5 AND l.row_id=a.id AND l.marker=0 '
                . 'WHERE r.run_token=? AND r.marker=0 AND a.id>? ORDER BY a.id LIMIT ' . self::PAGE);
            $statement->execute([$this->token, $last]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (!is_string($row['expected_leaf']) || !is_string($row['actual_leaf'])
                    || !hash_equals($row['expected_leaf'], $row['actual_leaf'])) {
                    throw new \LogicException('Transportní provenance se po capture změnila.');
                }
                $last = (int) $row['row_id'];
            }
        } while (count($rows) === self::PAGE);
        $missing = $this->pdo->prepare("SELECT l.row_id FROM {$leaf} l JOIN {$receipt} r "
            . 'ON r.run_token=l.run_token AND r.tax_submission_id=l.tax_submission_id '
            . 'LEFT JOIN submission_outbox_attempts a ON a.id=l.row_id '
            . 'LEFT JOIN submission_outbox o ON o.id=a.outbox_id AND o.supplier_id=r.supplier_id '
            . "AND o.artifact_kind='tax_submission' AND o.artifact_id=r.tax_submission_id "
            . 'WHERE l.run_token=? AND l.marker=0 AND l.kind_order=5 AND (a.id IS NULL OR o.id IS NULL) LIMIT 1');
        $missing->execute([$this->token]);
        if ($missing->fetchColumn() !== false) {
            throw new \LogicException('Transportní provenance se po capture změnila.');
        }
    }

    private static function attemptLeaf(string $alias): string
    {
        return "SHA2(CAST(JSON_ARRAY('attempt',{$alias}.id,{$alias}.supplier_id,{$alias}.tax_submission_id,"
            . "{$alias}.channel,{$alias}.epo_environment,{$alias}.status,{$alias}.idempotency_key,{$alias}.request_sha256,"
            . "{$alias}.signing_fingerprint,{$alias}.response_http_status,{$alias}.remote_submission_ref,"
            . "{$alias}.offline_transfer_id,{$alias}.requested_at,{$alias}.submitted_at) AS BINARY),256)";
    }

    private static function statusEventLeaf(string $alias): string
    {
        return "SHA2(CAST(JSON_ARRAY('status_event',{$alias}.id,{$alias}.supplier_id,{$alias}.tax_submission_id,"
            . "{$alias}.attempt_id,{$alias}.epo_environment,{$alias}.event_type,{$alias}.status,{$alias}.http_status,"
            . "{$alias}.created_at) AS BINARY),256)";
    }

    private static function artifactLeaf(string $alias): string
    {
        return "SHA2(CAST(JSON_ARRAY('artifact',{$alias}.id,{$alias}.supplier_id,{$alias}.tax_submission_id,"
            . "{$alias}.attempt_id,{$alias}.document_id,{$alias}.artifact_kind,{$alias}.sha256,"
            . "{$alias}.verification_status,{$alias}.created_at) AS BINARY),256)";
    }

    private static function outboxLeaf(string $alias): string
    {
        return "SHA2(CAST(JSON_ARRAY('outbox',{$alias}.id,{$alias}.supplier_id,{$alias}.environment,{$alias}.channel,"
            . "{$alias}.dispatch_mode,{$alias}.agenda_code,{$alias}.artifact_kind,{$alias}.artifact_id,"
            . "{$alias}.artifact_sha256,{$alias}.dispatch_state,{$alias}.acceptance_state,{$alias}.correlation_reference,"
            . "{$alias}.external_message_id,{$alias}.receipt_document_id,{$alias}.receipt_signature_status,"
            . "{$alias}.receipt_matched_by,{$alias}.receipt_inbox_message_id,{$alias}.receipt_attached_at,"
            . "{$alias}.created_at) AS BINARY),256)";
    }

    private static function outboxAttemptLeaf(string $alias): string
    {
        return "SHA2(CAST(JSON_ARRAY('outbox_attempt',{$alias}.id,{$alias}.supplier_id,{$alias}.outbox_id,"
            . "{$alias}.channel,{$alias}.attempt_no,{$alias}.outcome,{$alias}.request_sha256,"
            . "{$alias}.correlation_reference,{$alias}.external_message_id,{$alias}.started_at,{$alias}.finished_at) AS BINARY),256)";
    }

    /** @param array<int,string> $roots */
    private function updateRoots(string $receipt, array $roots): void
    {
        foreach (array_chunk($roots, self::PAGE, true) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $case = [];
            $parameters = [];
            foreach ($chunk as $submissionId => $root) {
                $case[] = 'WHEN ? THEN ?';
                array_push($parameters, $submissionId, $root);
            }
            $ids = array_keys($chunk);
            $parameters[] = $this->token;
            array_push($parameters, ...$ids);
            $this->pdo->prepare("UPDATE {$receipt} SET transport_receipt_root=CASE tax_submission_id "
                . implode(' ', $case) . ' ELSE transport_receipt_root END WHERE run_token=? AND tax_submission_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($parameters);
        }
    }

    private function assertActive(): void
    {
        if ($this->dropped) {
            throw new \LogicException('Capture transportu podání už byl odstraněn.');
        }
        $this->assertDatabase();
    }

    private function assertDatabase(): void
    {
        if (!hash_equals($this->database, (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn())) {
            throw new \LogicException('Capture transportu podání patří jiné databázi.');
        }
    }
}
