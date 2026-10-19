<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use PDO;

final class FinancialSavingsCloneCapture
{
    private const PAGE = 500;

    private bool $dropped = false;

    private bool $captured = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
        private readonly string $runToken,
        private readonly string $archiveTable,
        private readonly string $archiveRunToken,
        private readonly string $sourceTable,
        private readonly string $profileTable,
        private readonly string $commandTable,
        private readonly string $eventTable,
        private readonly string $bindingTable,
        private readonly CloneProtectedRows $protectedRows,
    ) {}

    public static function create(
        PDO $pdo,
        string $archiveTable,
        string $archiveRunToken,
        CloneProtectedRows $protectedRows,
    ): self {
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || !AnonymizationOptions::isSafeDatabaseName($database)
            || preg_match('/^__myucto_archive_capture_[a-f0-9]{16}$/D', $archiveTable) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $archiveRunToken) !== 1) {
            throw new \RuntimeException('Capture daňových úspor vyžaduje přesný vlastněný archivní staging.');
        }
        $suffix = bin2hex(random_bytes(8));
        $source = '__myucto_saving_sources_' . $suffix;
        $profile = '__myucto_legal_profiles_' . $suffix;
        $command = '__myucto_saving_commands_' . $suffix;
        $event = '__myucto_saving_events_' . $suffix;
        $binding = '__myucto_saving_bindings_' . $suffix;
        $token = bin2hex(random_bytes(32));
        $created = [];
        try {
            $pdo->exec('CREATE TABLE ' . DatabaseCloner::quote($source) . ' ('
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'source_id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NULL, period_id BIGINT UNSIGNED NULL, '
                . 'snapshot_id BIGINT UNSIGNED NULL, legal_profile_id BIGINT UNSIGNED NULL, reduction_kc BIGINT UNSIGNED NULL, '
                . 'saving_cents BIGINT UNSIGNED NULL, basis_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'basis_bytes BIGINT UNSIGNED NULL, record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'created_by BIGINT UNSIGNED NULL, '
                . 'PRIMARY KEY (run_token,source_id), KEY idx_saving_source_owner (run_token,supplier_id,source_id)'
                . ') ENGINE=InnoDB');
            $created[] = $source;
            $pdo->prepare('INSERT INTO ' . DatabaseCloner::quote($source)
                . ' (run_token,source_id) VALUES (?,0)')->execute([$token]);
            $pdo->exec('CREATE TABLE ' . DatabaseCloner::quote($profile) . ' ('
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'profile_id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL, period_id BIGINT UNSIGNED NOT NULL, '
                . 'revision_no INT UNSIGNED NOT NULL, previous_id BIGINT UNSIGNED NULL, '
                . 'profile_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, profile_bytes BIGINT UNSIGNED NOT NULL, '
                . 'module_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, module_bytes BIGINT UNSIGNED NOT NULL, '
                . 'reason_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'idempotency_key_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_by BIGINT UNSIGNED NOT NULL, '
                . 'PRIMARY KEY (run_token,profile_id), KEY idx_profile_owner (run_token,supplier_id,period_id,revision_no)'
                . ') ENGINE=InnoDB');
            $created[] = $profile;
            $pdo->exec('CREATE TABLE ' . DatabaseCloner::quote($command) . ' ('
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'command_id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL, '
                . 'request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, request_bytes BIGINT UNSIGNED NOT NULL, '
                . 'request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'idempotency_key_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_by BIGINT UNSIGNED NOT NULL, '
                . 'PRIMARY KEY (run_token,command_id), KEY idx_saving_command_owner (run_token,supplier_id,command_id)'
                . ') ENGINE=InnoDB');
            $created[] = $command;
            $pdo->exec('CREATE TABLE ' . DatabaseCloner::quote($event) . ' ('
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'event_id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL, '
                . 'command_id BIGINT UNSIGNED NOT NULL, previous_id BIGINT UNSIGNED NULL, original_id BIGINT UNSIGNED NULL, '
                . "operation ENUM('use','reverse_use','void','restore') NOT NULL, period_id BIGINT UNSIGNED NULL, "
                . 'cost_line_id BIGINT UNSIGNED NULL, amount_cents BIGINT UNSIGNED NOT NULL, '
                . 'parent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'proof_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, proof_bytes BIGINT UNSIGNED NOT NULL, '
                . 'receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, receipt_bytes BIGINT UNSIGNED NOT NULL, '
                . 'record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'PRIMARY KEY (run_token,event_id), KEY idx_saving_event_owner (run_token,supplier_id,source_id,event_id)'
                . ') ENGINE=InnoDB');
            $created[] = $event;
            $pdo->exec('CREATE TABLE ' . DatabaseCloner::quote($binding) . ' ('
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'supplier_id INT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL, '
                . 'event_id BIGINT UNSIGNED NOT NULL, cost_line_id BIGINT UNSIGNED NOT NULL, '
                . 'PRIMARY KEY (run_token,supplier_id,event_id)'
                . ') ENGINE=InnoDB');
            $created[] = $binding;
        } catch (\Throwable $e) {
            if (self::hasOwnerMarker($pdo, $source, $token)) {
                foreach (array_reverse($created) as $table) {
                    $pdo->exec('DROP TABLE ' . DatabaseCloner::quote($table));
                }
            }
            throw $e;
        }

        return new self(
            $pdo,
            $database,
            $token,
            $archiveTable,
            $archiveRunToken,
            $source,
            $profile,
            $command,
            $event,
            $binding,
            $protectedRows,
        );
    }

    public function capture(): void
    {
        $this->assertActive();
        if ($this->captured) {
            throw new \LogicException('Capture daňových úspor už proběhl.');
        }
        $this->captureProfiles();
        $this->captureSources();
        $this->captureCommands();
        $this->captureEvents();
        $this->captureBindings();
        $this->verifyGraph();
        $this->captured = true;
    }

    public function sourceCount(): int
    {
        return $this->count($this->sourceTable, 'source_id>0');
    }

    public function profileCount(): int
    {
        return $this->count($this->profileTable, '1=1');
    }

    public function commandCount(): int
    {
        return $this->count($this->commandTable, '1=1');
    }

    public function eventCount(): int
    {
        return $this->count($this->eventTable, '1=1');
    }

    public function bindingCount(): int
    {
        return $this->count($this->bindingTable, '1=1');
    }

    public function drop(): void
    {
        $this->assertActive();
        if (!self::hasOwnerMarker($this->pdo, $this->sourceTable, $this->runToken)) {
            throw new \RuntimeException('Nelze doložit vlastnictví stagingu daňových úspor.');
        }
        foreach ([$this->bindingTable, $this->eventTable, $this->commandTable, $this->profileTable, $this->sourceTable] as $table) {
            $this->pdo->exec('DROP TABLE ' . DatabaseCloner::quote($table));
        }
        $this->dropped = true;
    }

    private function captureProfiles(): void
    {
        $select = 'SELECT id,supplier_id,period_id,revision_no,previous_id,request_hash,record_hash,created_by,'
            . 'OCTET_LENGTH(profile_json) profile_bytes,LOWER(SHA2(CAST(profile_json AS BINARY),256)) profile_sha256,JSON_VALID(profile_json) profile_valid,'
            . 'OCTET_LENGTH(module_proof_json) module_bytes,LOWER(SHA2(CAST(module_proof_json AS BINARY),256)) module_sha256,JSON_VALID(module_proof_json) module_valid,'
            . 'LOWER(SHA2(CAST(reason AS BINARY),256)) reason_sha256,LOWER(SHA2(CAST(idempotency_key AS BINARY),256)) idempotency_key_sha256 '
            . 'FROM nonprofit_legal_profiles FORCE INDEX (uq_nlp_revision) ';
        $lastSupplier = 0;
        $lastPeriod = 0;
        $lastRevision = 0;
        $headSupplier = 0;
        $headPeriod = 0;
        $headRevision = 0;
        $headId = null;
        do {
            $current = $this->pdo->prepare($select
                . 'WHERE supplier_id=? AND (period_id>? OR (period_id=? AND revision_no>?)) '
                . 'ORDER BY period_id,revision_no LIMIT ' . self::PAGE);
            $current->execute([$lastSupplier, $lastPeriod, $lastPeriod, $lastRevision]);
            $page = $current->fetchAll(PDO::FETCH_ASSOC);
            $remaining = self::PAGE - count($page);
            if ($remaining > 0) {
                $next = $this->pdo->prepare($select
                    . 'WHERE supplier_id>? ORDER BY supplier_id,period_id,revision_no LIMIT ' . $remaining);
                $next->execute([$lastSupplier]);
                $page = [...$page, ...$next->fetchAll(PDO::FETCH_ASSOC)];
            }
            if ($page === []) {
                break;
            }
            $parameters = [];
            $ids = [];
            foreach ($page as $row) {
                if ((int) $row['profile_valid'] !== 1 || (int) $row['module_valid'] !== 1) {
                    throw new \LogicException('Právní profil DPPO 504 nemá platný JSON důkaz.');
                }
                foreach (['profile_sha256', 'module_sha256', 'reason_sha256', 'idempotency_key_sha256', 'request_hash', 'record_hash'] as $field) {
                    $this->assertHash($row[$field]);
                }
                $supplier = (int) $row['supplier_id'];
                $period = (int) $row['period_id'];
                if ($supplier !== $headSupplier || $period !== $headPeriod) {
                    $headSupplier = $supplier;
                    $headPeriod = $period;
                    $headRevision = 0;
                    $headId = null;
                }
                $revision = (int) $row['revision_no'];
                $previous = $row['previous_id'] === null ? null : (int) $row['previous_id'];
                if ($revision !== $headRevision + 1 || $previous !== $headId) {
                    throw new \LogicException('Historie právního profilu DPPO 504 není souvislá.');
                }
                $profileId = (int) $row['id'];
                array_push($parameters, $this->runToken, $profileId, $supplier, $period, $revision, $previous,
                    $row['profile_sha256'], (int) $row['profile_bytes'], $row['module_sha256'], (int) $row['module_bytes'],
                    $row['reason_sha256'], $row['idempotency_key_sha256'], $row['request_hash'], $row['record_hash'],
                    (int) $row['created_by']);
                $ids[] = $profileId;
                $headRevision = $revision;
                $headId = $profileId;
            }
            $values = implode(',', array_fill(0, count($page), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $this->pdo->prepare('INSERT INTO ' . DatabaseCloner::quote($this->profileTable)
                . " (run_token,profile_id,supplier_id,period_id,revision_no,previous_id,profile_sha256,profile_bytes,module_sha256,module_bytes,reason_sha256,idempotency_key_sha256,request_hash,record_hash,created_by) VALUES {$values}")
                ->execute($parameters);
            $this->protectedRows->protectMany(FinancialArchiveProtectedTable::LEGAL_PROFILE, $ids);
            $last = $page[array_key_last($page)];
            $lastSupplier = (int) $last['supplier_id'];
            $lastPeriod = (int) $last['period_id'];
            $lastRevision = (int) $last['revision_no'];
        } while (count($page) === self::PAGE);
    }

    private function captureSources(): void
    {
        $select = 'SELECT id,supplier_id,period_id,snapshot_id,legal_profile_id,reduction_kc,saving_cents,record_hash,created_by,'
            . 'OCTET_LENGTH(basis_json) basis_bytes,LOWER(SHA2(CAST(basis_json AS BINARY),256)) basis_sha256,'
            . "JSON_VALID(basis_json) basis_valid,JSON_UNQUOTE(JSON_EXTRACT(basis_json,'$.snapshot_id')) basis_snapshot_id "
            . 'FROM nonprofit_tax_saving_sources FORCE INDEX (uq_ntss_tenant) ';
        $lastSupplier = 0;
        $lastId = 0;
        do {
            $page = $this->tenantPage($select, $lastSupplier, $lastId);
            if ($page === []) {
                break;
            }
            $parameters = [];
            $ids = [];
            foreach ($page as $row) {
                if ((int) $row['basis_valid'] !== 1 || (int) $row['basis_snapshot_id'] !== (int) $row['snapshot_id']) {
                    throw new \LogicException('Zdroj daňové úspory nemá shodný snapshot v basis důkazu.');
                }
                $this->assertHash($row['basis_sha256']);
                $this->assertHash($row['record_hash']);
                array_push($parameters, $this->runToken, (int) $row['id'], (int) $row['supplier_id'],
                    (int) $row['period_id'], (int) $row['snapshot_id'], (int) $row['legal_profile_id'],
                    (int) $row['reduction_kc'], (int) $row['saving_cents'], $row['basis_sha256'],
                    (int) $row['basis_bytes'], $row['record_hash'], (int) $row['created_by']);
                $ids[] = (int) $row['id'];
            }
            $values = implode(',', array_fill(0, count($page), '(?,?,?,?,?,?,?,?,?,?,?,?)'));
            $this->pdo->prepare('INSERT INTO ' . DatabaseCloner::quote($this->sourceTable)
                . " (run_token,source_id,supplier_id,period_id,snapshot_id,legal_profile_id,reduction_kc,saving_cents,basis_sha256,basis_bytes,record_hash,created_by) VALUES {$values}")
                ->execute($parameters);
            $this->protectedRows->protectMany(FinancialArchiveProtectedTable::SAVING_SOURCE, $ids);
            $lastSupplier = (int) $page[array_key_last($page)]['supplier_id'];
            $lastId = (int) $page[array_key_last($page)]['id'];
        } while (count($page) === self::PAGE);
    }

    private function captureCommands(): void
    {
        $select = 'SELECT id,supplier_id,request_hash,created_by,LOWER(SHA2(CAST(idempotency_key AS BINARY),256)) idempotency_key_sha256,OCTET_LENGTH(request_json) request_bytes,'
            . 'LOWER(SHA2(CAST(request_json AS BINARY),256)) request_sha256,JSON_VALID(request_json) request_valid '
            . 'FROM nonprofit_tax_saving_commands FORCE INDEX (uq_ntsc_tenant) ';
        $lastSupplier = 0;
        $lastId = 0;
        do {
            $page = $this->tenantPage($select, $lastSupplier, $lastId);
            if ($page === []) {
                break;
            }
            $parameters = [];
            $ids = [];
            foreach ($page as $row) {
                if ((int) $row['request_valid'] !== 1) {
                    throw new \LogicException('Příkaz daňové úspory nemá platný JSON důkaz.');
                }
                $this->assertHash($row['request_sha256']);
                $this->assertHash($row['request_hash']);
                $this->assertHash($row['idempotency_key_sha256']);
                array_push($parameters, $this->runToken, (int) $row['id'], (int) $row['supplier_id'],
                    $row['request_sha256'], (int) $row['request_bytes'], $row['request_hash'],
                    $row['idempotency_key_sha256'], (int) $row['created_by']);
                $ids[] = (int) $row['id'];
            }
            $values = implode(',', array_fill(0, count($page), '(?,?,?,?,?,?,?,?)'));
            $this->pdo->prepare('INSERT INTO ' . DatabaseCloner::quote($this->commandTable)
                . " (run_token,command_id,supplier_id,request_sha256,request_bytes,request_hash,idempotency_key_sha256,created_by) VALUES {$values}")
                ->execute($parameters);
            $this->protectedRows->protectMany(FinancialArchiveProtectedTable::SAVING_COMMAND, $ids);
            $lastSupplier = (int) $page[array_key_last($page)]['supplier_id'];
            $lastId = (int) $page[array_key_last($page)]['id'];
        } while (count($page) === self::PAGE);
    }

    private function captureEvents(): void
    {
        $select = 'SELECT e.id,e.supplier_id,e.source_id,e.command_id,e.previous_id,e.original_id,e.operation,e.period_id,e.cost_line_id,e.amount_cents,e.parent_hash,e.record_hash,'
            . 'OCTET_LENGTH(e.proof_json) proof_bytes,LOWER(SHA2(CAST(e.proof_json AS BINARY),256)) proof_sha256,JSON_VALID(e.proof_json) proof_valid,'
            . 'OCTET_LENGTH(e.receipt_json) receipt_bytes,LOWER(SHA2(CAST(e.receipt_json AS BINARY),256)) receipt_sha256,JSON_VALID(e.receipt_json) receipt_valid,'
            . 's.record_hash source_record_hash FROM nonprofit_tax_saving_events e FORCE INDEX (uq_ntse_source) '
            . 'JOIN nonprofit_tax_saving_sources s ON s.supplier_id=e.supplier_id AND s.id=e.source_id ';
        $lastSupplier = 0;
        $lastSource = 0;
        $lastId = 0;
        $headSupplier = 0;
        $headSource = 0;
        $headId = null;
        $headHash = null;
        do {
            $current = $this->pdo->prepare($select
                . 'WHERE e.supplier_id=? AND (e.source_id>? OR (e.source_id=? AND e.id>?)) ORDER BY e.source_id,e.id LIMIT ' . self::PAGE);
            $current->execute([$lastSupplier, $lastSource, $lastSource, $lastId]);
            $page = $current->fetchAll(PDO::FETCH_ASSOC);
            $remaining = self::PAGE - count($page);
            if ($remaining > 0) {
                $next = $this->pdo->prepare($select
                    . 'WHERE e.supplier_id>? ORDER BY e.supplier_id,e.source_id,e.id LIMIT ' . $remaining);
                $next->execute([$lastSupplier]);
                $page = [...$page, ...$next->fetchAll(PDO::FETCH_ASSOC)];
            }
            if ($page === []) {
                break;
            }
            $parameters = [];
            $ids = [];
            foreach ($page as $row) {
                if ((int) $row['proof_valid'] !== 1 || (int) $row['receipt_valid'] !== 1) {
                    throw new \LogicException('Událost daňové úspory nemá platný JSON důkaz.');
                }
                foreach (['parent_hash', 'proof_sha256', 'receipt_sha256', 'record_hash'] as $field) {
                    $this->assertHash($row[$field]);
                }
                $supplier = (int) $row['supplier_id'];
                $source = (int) $row['source_id'];
                if ($supplier !== $headSupplier || $source !== $headSource) {
                    $headSupplier = $supplier;
                    $headSource = $source;
                    $headId = null;
                    $this->assertHash($row['source_record_hash']);
                    $headHash = $row['source_record_hash'];
                }
                $previous = $row['previous_id'] === null ? null : (int) $row['previous_id'];
                if ($previous !== $headId || !hash_equals((string) $headHash, $row['parent_hash'])) {
                    throw new \LogicException('Řetězec událostí daňové úspory není souvislý.');
                }
                $this->assertEventShape($row);
                $headId = (int) $row['id'];
                $headHash = $row['record_hash'];
                array_push($parameters, $this->runToken, $headId, $supplier, $source, (int) $row['command_id'],
                    $previous, $row['original_id'] === null ? null : (int) $row['original_id'], $row['operation'],
                    $row['period_id'] === null ? null : (int) $row['period_id'],
                    $row['cost_line_id'] === null ? null : (int) $row['cost_line_id'], (int) $row['amount_cents'],
                    $row['parent_hash'], $row['proof_sha256'], (int) $row['proof_bytes'],
                    $row['receipt_sha256'], (int) $row['receipt_bytes'], $row['record_hash']);
                $ids[] = $headId;
            }
            $values = implode(',', array_fill(0, count($page), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $this->pdo->prepare('INSERT INTO ' . DatabaseCloner::quote($this->eventTable)
                . " (run_token,event_id,supplier_id,source_id,command_id,previous_id,original_id,operation,period_id,cost_line_id,amount_cents,parent_hash,proof_sha256,proof_bytes,receipt_sha256,receipt_bytes,record_hash) VALUES {$values}")
                ->execute($parameters);
            $this->protectedRows->protectMany(FinancialArchiveProtectedTable::SAVING_EVENT, $ids);
            $last = $page[array_key_last($page)];
            $lastSupplier = (int) $last['supplier_id'];
            $lastSource = (int) $last['source_id'];
            $lastId = (int) $last['id'];
        } while (count($page) === self::PAGE);
    }

    private function captureBindings(): void
    {
        $lastSupplier = 0;
        $lastEvent = 0;
        do {
            $current = $this->pdo->prepare('SELECT supplier_id,source_id,event_id,cost_line_id FROM nonprofit_tax_saving_cost_bindings '
                . 'WHERE supplier_id=? AND event_id>? ORDER BY event_id LIMIT ' . self::PAGE);
            $current->execute([$lastSupplier, $lastEvent]);
            $page = $current->fetchAll(PDO::FETCH_ASSOC);
            $remaining = self::PAGE - count($page);
            if ($remaining > 0) {
                $next = $this->pdo->prepare('SELECT supplier_id,source_id,event_id,cost_line_id FROM nonprofit_tax_saving_cost_bindings '
                    . 'WHERE supplier_id>? ORDER BY supplier_id,event_id LIMIT ' . $remaining);
                $next->execute([$lastSupplier]);
                $page = [...$page, ...$next->fetchAll(PDO::FETCH_ASSOC)];
            }
            if ($page === []) {
                break;
            }
            $parameters = [];
            foreach ($page as $row) {
                array_push($parameters, $this->runToken, (int) $row['supplier_id'], (int) $row['source_id'],
                    (int) $row['event_id'], (int) $row['cost_line_id']);
            }
            $values = implode(',', array_fill(0, count($page), '(?,?,?,?,?)'));
            $this->pdo->prepare('INSERT INTO ' . DatabaseCloner::quote($this->bindingTable)
                . " (run_token,supplier_id,source_id,event_id,cost_line_id) VALUES {$values}")
                ->execute($parameters);
            $last = $page[array_key_last($page)];
            $lastSupplier = (int) $last['supplier_id'];
            $lastEvent = (int) $last['event_id'];
        } while (count($page) === self::PAGE);
    }

    private function verifyGraph(): void
    {
        $sources = DatabaseCloner::quote($this->sourceTable);
        $profiles = DatabaseCloner::quote($this->profileTable);
        $commands = DatabaseCloner::quote($this->commandTable);
        $events = DatabaseCloner::quote($this->eventTable);
        $bindings = DatabaseCloner::quote($this->bindingTable);
        $archives = DatabaseCloner::quote($this->archiveTable);
        $checks = [
            "SELECT s.source_id FROM {$sources} s LEFT JOIN {$archives} a ON a.run_token=? AND a.archive_id=s.snapshot_id AND a.supplier_id=s.supplier_id LEFT JOIN {$profiles} p ON p.run_token=s.run_token AND p.profile_id=s.legal_profile_id AND p.supplier_id=s.supplier_id WHERE s.run_token=? AND s.source_id>0 AND (a.archive_id IS NULL OR p.profile_id IS NULL) LIMIT 1",
            "SELECT e.event_id FROM {$events} e LEFT JOIN {$sources} s ON s.run_token=? AND s.source_id=e.source_id AND s.supplier_id=e.supplier_id LEFT JOIN {$commands} c ON c.run_token=? AND c.command_id=e.command_id AND c.supplier_id=e.supplier_id WHERE e.run_token=? AND (s.source_id IS NULL OR c.command_id IS NULL) LIMIT 1",
            "SELECT e.event_id FROM {$events} e LEFT JOIN {$events} o ON o.run_token=e.run_token AND o.event_id=e.original_id AND o.supplier_id=e.supplier_id AND o.source_id=e.source_id WHERE e.run_token=? AND e.operation='reverse_use' AND (o.event_id IS NULL OR o.operation<>'use' OR o.amount_cents<>e.amount_cents OR o.period_id<>e.period_id OR o.cost_line_id<>e.cost_line_id) LIMIT 1",
            "SELECT e.event_id FROM {$events} e LEFT JOIN {$events} r ON r.run_token=e.run_token AND r.supplier_id=e.supplier_id AND r.source_id=e.source_id AND r.original_id=e.event_id AND r.operation='reverse_use' LEFT JOIN {$bindings} b ON b.run_token=e.run_token AND b.supplier_id=e.supplier_id AND b.event_id=e.event_id AND b.source_id=e.source_id AND b.cost_line_id=e.cost_line_id WHERE e.run_token=? AND e.operation='use' AND ((r.event_id IS NULL AND b.event_id IS NULL) OR (r.event_id IS NOT NULL AND b.event_id IS NOT NULL)) LIMIT 1",
            "SELECT b.event_id FROM {$bindings} b LEFT JOIN {$events} e ON e.run_token=b.run_token AND e.event_id=b.event_id AND e.supplier_id=b.supplier_id AND e.source_id=b.source_id AND e.cost_line_id=b.cost_line_id WHERE b.run_token=? AND (e.event_id IS NULL OR e.operation<>'use') LIMIT 1",
        ];
        foreach ($checks as $index => $sql) {
            $statement = $this->pdo->prepare($sql);
            $parameters = $index === 0
                ? [$this->archiveRunToken, $this->runToken]
                : ($index === 1 ? [$this->runToken, $this->runToken, $this->runToken] : [$this->runToken]);
            $statement->execute($parameters);
            if ($statement->fetchColumn() !== false) {
                throw new \LogicException('Capture daňových úspor neodpovídá úplnému navázanému řetězci.');
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function tenantPage(string $select, int $lastSupplier, int $lastId): array
    {
        $current = $this->pdo->prepare($select
            . 'WHERE supplier_id=? AND id>? ORDER BY id LIMIT ' . self::PAGE);
        $current->execute([$lastSupplier, $lastId]);
        $rows = $current->fetchAll(PDO::FETCH_ASSOC);
        $remaining = self::PAGE - count($rows);
        if ($remaining > 0) {
            $next = $this->pdo->prepare($select
                . 'WHERE supplier_id>? ORDER BY supplier_id,id LIMIT ' . $remaining);
            $next->execute([$lastSupplier]);
            $rows = [...$rows, ...$next->fetchAll(PDO::FETCH_ASSOC)];
        }
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function assertEventShape(array $row): void
    {
        $amount = (int) $row['amount_cents'];
        $period = $row['period_id'];
        $cost = $row['cost_line_id'];
        $original = $row['original_id'];
        $valid = match ($row['operation']) {
            'use' => $original === null && $period !== null && $cost !== null && $amount > 0,
            'reverse_use' => $original !== null && $period !== null && $cost !== null && $amount > 0,
            'void', 'restore' => $original === null && $period === null && $cost === null && $amount === 0,
            default => false,
        };
        if (!$valid) {
            throw new \LogicException('Událost daňové úspory má neplatný typový tvar.');
        }
    }

    private function count(string $table, string $predicate): int
    {
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($table)
            . " WHERE run_token=? AND {$predicate}");
        $statement->execute([$this->runToken]);
        return (int) $statement->fetchColumn();
    }

    private function assertActive(): void
    {
        if ($this->dropped || $this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->database) {
            throw new \RuntimeException('Staging daňových úspor není aktivní ve své databázi.');
        }
    }

    private function assertHash(mixed $hash): void
    {
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \LogicException('Důkaz daňové úspory nemá platný SHA-256 otisk.');
        }
    }

    private static function hasOwnerMarker(PDO $pdo, string $table, string $token): bool
    {
        try {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($table)
                . ' WHERE run_token=? AND source_id=0');
            $statement->execute([$token]);
            return (int) $statement->fetchColumn() === 1;
        } catch (\PDOException) {
            return false;
        }
    }
}
