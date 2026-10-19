<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class J6SqliteWorkingStore
{
    private const int CACHE_KIB = 8192;

    private string $base;
    private string $directory;
    private string $database;
    private ?PDO $pdo;
    private bool $closed = false;

    public function __construct(?string $base = null)
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('J6 stránkovaný výpočet vyžaduje rozšíření ext-pdo_sqlite.');
        }
        $base ??= RuntimePaths::storage('tmp/j6-calculation-work');
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('J6 working store base cannot be created.');
        }
        $resolved = realpath($base);
        if ($resolved === false) throw new \RuntimeException('J6 working store base cannot be resolved.');
        $this->base = rtrim($resolved, '/\\');
        $this->directory = $this->base . DIRECTORY_SEPARATOR . 'sqlite-' . bin2hex(random_bytes(32));
        $this->assertPath($this->directory, false, $this->base);
        if (!mkdir($this->directory, 0700)) throw new \RuntimeException('J6 working store directory cannot be created.');
        $this->database = $this->directory . DIRECTORY_SEPARATOR . 'working.sqlite';
        $this->assertPath($this->database, false, $this->directory);
        try {
            $this->pdo = new PDO('sqlite:' . $this->database, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->configure();
            $this->schema();
        } catch (\Throwable $failure) {
            $this->pdo = null;
            $this->cleanupFiles();
            throw $failure;
        }
    }

    public function connection(): PDO
    {
        if ($this->closed || $this->pdo === null) throw new \LogicException('J6 working store is closed.');
        return $this->pdo;
    }

    public function appendActivity(string $id, array $payload, string $recordHash): void
    {
        $this->hash($recordHash);
        $stmt = $this->connection()->prepare('INSERT INTO activities(activity_kind_id,payload_json,record_hash) VALUES(?,?,?)');
        $stmt->execute([$this->identifier($id), NonprofitTaxRules::canonicalJson($payload), $recordHash]);
    }

    public function activity(string $id): array
    {
        $stmt = $this->connection()->prepare('SELECT payload_json FROM activities WHERE activity_kind_id=?');
        $stmt->execute([$this->identifier($id)]);
        $json = $stmt->fetchColumn();
        if (!is_string($json)) throw new \UnexpectedValueException('J6 activity definition is missing.');
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function activities(): iterable
    {
        $statement = $this->connection()->query('SELECT activity_kind_id,payload_json,record_hash FROM activities ORDER BY activity_kind_id');
        while (($row = $statement->fetch()) !== false) {
            yield ['activity_kind_id' => $row['activity_kind_id'],
                'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR),
                'record_hash' => $row['record_hash']];
        }
    }

    public function appendSource(array $row, bool $current): void
    {
        $id = $this->identifier($row['id'] ?? null);
        $amount = $this->cents($row['amount_cents'] ?? null);
        $deductible = $this->cents($row['deductible_cents'] ?? 0);
        $activity = $this->identifier($row['activity_kind_id'] ?? null);
        $group = $this->identifier($row['group'] ?? null);
        $classification = $row['classification'] ?? null;
        if (!is_array($classification) || !is_string($classification['regime'] ?? null)
            || !is_string($classification['method'] ?? null)) {
            throw new \InvalidArgumentException('J6 classified source is invalid.');
        }
        $decision = $row['decision'] ?? null;
        if (!is_array($decision)) throw new \InvalidArgumentException('J6 classified decision is invalid.');
        $stmt = $this->connection()->prepare('INSERT INTO sources
            (source_id,current_row,row_json,kind,amount_cents,deductible_cents,activity_kind_id,group_id,cohort,regime,method,title,reference_current)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$id, $current ? 1 : 0, NonprofitTaxRules::canonicalJson($row), $row['kind'] ?? null,
            $amount, $deductible, $activity, $group, (string) ($decision['cohort'] ?? ''),
            $classification['regime'], $classification['method'],
            $decision['title'] ?? null, ($row['reference_current'] ?? $current) === true ? 1 : 0]);
    }

    public function appendPool(string $kind, string $anchor, array $payload, string $recordHash): void
    {
        $this->poolKind($kind);
        $this->hash($recordHash);
        $stmt = $this->connection()->prepare('INSERT INTO funding_pools(pool_kind,anchor,payload_json,record_hash) VALUES(?,?,?,?)');
        $stmt->execute([$kind, $this->identifier($anchor), NonprofitTaxRules::canonicalJson($payload), $recordHash]);
    }

    public function appendMember(string $kind, string $anchor, string $memberId): void
    {
        $this->poolKind($kind);
        $stmt = $this->connection()->prepare('INSERT OR IGNORE INTO funding_members(pool_kind,anchor,member_id) VALUES(?,?,?)');
        $stmt->execute([$kind, $this->identifier($anchor), $this->identifier($memberId)]);
        if ($stmt->rowCount() !== 1) NonprofitTaxRules::invalid('Člen fondu financování smí být uveden právě jednou.');
    }

    public function appendLink(string $sourceId, string $costId, array $payload, string $recordHash): void
    {
        $this->hash($recordHash);
        $stmt = $this->connection()->prepare('INSERT INTO funding_links(source_id,cost_id,payload_json,record_hash) VALUES(?,?,?,?)');
        $stmt->execute([$this->identifier($sourceId), $this->identifier($costId), NonprofitTaxRules::canonicalJson($payload), $recordHash]);
    }

    public function appendHistory(array $row): void
    {
        foreach (['assessment_id', 'period_id', 'net_source_cents', 'prior_drawdown_cents', 'drawdown_cents', 'remaining_cents'] as $field) {
            if (!is_int($row[$field] ?? null)) throw new \InvalidArgumentException('J6 funding history is invalid.');
        }
        $stmt = $this->connection()->prepare('INSERT INTO funding_history
            (assessment_id,period_id,status,current_proof,anchor,member_id,net_source_cents,prior_drawdown_cents,drawdown_cents,remaining_cents,record_hash,row_json)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $hash = (string) ($row['record_hash'] ?? NonprofitTaxRules::hash(['domain' => 'J6FUNDINGHISTORY2', 'row' => $row]));
        $this->hash($hash);
        $stmt->execute([$row['assessment_id'], $row['period_id'], $row['status'] ?? null,
            ($row['current'] ?? false) === true ? 1 : 0, $this->identifier($row['anchor'] ?? null),
            $this->identifier($row['member_id'] ?? null), $row['net_source_cents'], $row['prior_drawdown_cents'],
            $row['drawdown_cents'], $row['remaining_cents'], $hash, NonprofitTaxRules::canonicalJson($row)]);
    }

    public function fundingReferenceEvidencePages(int $limit = 500): iterable
    {
        $this->pageLimit($limit);
        $statement = $this->connection()->query('SELECT row_json FROM sources WHERE current_row=0 ORDER BY source_id');
        yield from $this->jsonPages($statement, $limit);
    }

    public function fundingHistoryEvidencePages(int $limit = 500): iterable
    {
        $this->pageLimit($limit);
        $statement = $this->connection()->query('SELECT row_json FROM funding_history ORDER BY assessment_id,anchor,member_id');
        yield from $this->jsonPages($statement, $limit);
    }

    public function fundingDecisionRecords(): iterable
    {
        $statement = $this->connection()->query('SELECT pool_kind,anchor,payload_json,record_hash FROM funding_pools ORDER BY pool_kind,anchor');
        while (($row = $statement->fetch()) !== false) yield ['record_kind' => 'funding_pool',
            'pool_kind' => $row['pool_kind'], 'anchor' => $row['anchor'],
            'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR),
            'decision_record_hash' => $row['record_hash']];
        $statement = $this->connection()->query('SELECT pool_kind,anchor,member_id FROM funding_members ORDER BY pool_kind,anchor,member_id');
        while (($row = $statement->fetch()) !== false) {
            $row = ['record_kind' => 'funding_member'] + $row;
            $row['decision_record_hash'] = NonprofitTaxRules::hash(['domain' => 'J6DECISION2.funding_member', 'row' => $row]);
            yield $row;
        }
        $statement = $this->connection()->query('SELECT source_id,cost_id,payload_json,record_hash FROM funding_links ORDER BY source_id,cost_id');
        while (($row = $statement->fetch()) !== false) yield ['record_kind' => 'funding_link',
            'source_id' => $row['source_id'], 'cost_id' => $row['cost_id'],
            'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR),
            'decision_record_hash' => $row['record_hash']];
    }

    public function closeAndDelete(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        $this->pdo = null;
        $this->cleanupFiles();
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try { $this->closeAndDelete(); } catch (\Throwable) {}
        }
    }

    private function configure(): void
    {
        $pdo = $this->connection();
        $pdo->exec('PRAGMA trusted_schema=OFF');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA journal_mode=DELETE');
        $pdo->exec('PRAGMA synchronous=OFF');
        $pdo->exec('PRAGMA temp_store=FILE');
        $pdo->exec('PRAGMA mmap_size=0');
        $pdo->exec('PRAGMA cache_size=-' . self::CACHE_KIB);
        $pdo->exec('PRAGMA journal_size_limit=0');
        if ((int) $pdo->query('PRAGMA cache_size')->fetchColumn() !== -self::CACHE_KIB
            || (int) $pdo->query('PRAGMA mmap_size')->fetchColumn() !== 0
            || (int) $pdo->query('PRAGMA temp_store')->fetchColumn() !== 1) {
            throw new \RuntimeException('J6 working store bounds were not applied.');
        }
    }

    private function schema(): void
    {
        $this->connection()->exec(<<<'SQL'
CREATE TABLE activities (
 activity_kind_id TEXT PRIMARY KEY,
 payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
 record_hash TEXT NOT NULL CHECK(length(record_hash)=64)
) WITHOUT ROWID;
CREATE TABLE sources (
 source_id TEXT PRIMARY KEY,
 current_row INTEGER NOT NULL CHECK(current_row IN (0,1)),
 row_json TEXT NOT NULL CHECK(json_valid(row_json)),
 kind TEXT NOT NULL CHECK(kind IN ('revenue','expense')),
 amount_cents INTEGER NOT NULL CHECK(typeof(amount_cents)='integer'),
 deductible_cents INTEGER NOT NULL CHECK(typeof(deductible_cents)='integer'),
 activity_kind_id TEXT NOT NULL,
 group_id TEXT NOT NULL,
 cohort TEXT NOT NULL,
 regime TEXT NOT NULL,
 method TEXT NOT NULL,
 title TEXT NOT NULL,
 reference_current INTEGER NOT NULL CHECK(reference_current IN (0,1))
) WITHOUT ROWID;
CREATE INDEX ix_j6_sources_activity ON sources(activity_kind_id,group_id,source_id);
CREATE INDEX ix_j6_sources_group ON sources(group_id,source_id);
CREATE TABLE funding_pools (
 pool_kind TEXT NOT NULL CHECK(pool_kind IN ('revenue','expense')),
 anchor TEXT NOT NULL,
 payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
 record_hash TEXT NOT NULL CHECK(length(record_hash)=64),
 PRIMARY KEY(pool_kind,anchor)
) WITHOUT ROWID;
CREATE TABLE funding_members (
 pool_kind TEXT NOT NULL CHECK(pool_kind IN ('revenue','expense')),
 anchor TEXT NOT NULL,
 member_id TEXT NOT NULL,
 PRIMARY KEY(pool_kind,anchor,member_id),
 UNIQUE(pool_kind,member_id),
 FOREIGN KEY(pool_kind,anchor) REFERENCES funding_pools(pool_kind,anchor)
) WITHOUT ROWID;
CREATE INDEX ix_j6_members_identity ON funding_members(member_id,pool_kind,anchor);
CREATE TABLE funding_links (
 source_id TEXT NOT NULL,
 cost_id TEXT NOT NULL,
 payload_json TEXT NOT NULL CHECK(json_valid(payload_json)),
 record_hash TEXT NOT NULL CHECK(length(record_hash)=64),
 PRIMARY KEY(source_id,cost_id)
) WITHOUT ROWID;
CREATE INDEX ix_j6_links_cost ON funding_links(cost_id,source_id);
CREATE TABLE funding_history (
 assessment_id INTEGER NOT NULL CHECK(typeof(assessment_id)='integer' AND assessment_id>0),
 period_id INTEGER NOT NULL CHECK(typeof(period_id)='integer' AND period_id>0),
 status TEXT NOT NULL,
 current_proof INTEGER NOT NULL CHECK(current_proof IN (0,1)),
 anchor TEXT NOT NULL,
 member_id TEXT NOT NULL,
 net_source_cents INTEGER NOT NULL CHECK(typeof(net_source_cents)='integer'),
 prior_drawdown_cents INTEGER NOT NULL CHECK(typeof(prior_drawdown_cents)='integer'),
 drawdown_cents INTEGER NOT NULL CHECK(typeof(drawdown_cents)='integer'),
 remaining_cents INTEGER NOT NULL CHECK(typeof(remaining_cents)='integer'),
 record_hash TEXT NOT NULL CHECK(length(record_hash)=64),
 row_json TEXT NOT NULL CHECK(json_valid(row_json)),
 PRIMARY KEY(assessment_id,anchor,member_id)
) WITHOUT ROWID;
CREATE INDEX ix_j6_history_member ON funding_history(member_id,anchor,assessment_id);
CREATE INDEX ix_j6_history_anchor ON funding_history(anchor,assessment_id,member_id);
SQL);
    }

    private function cleanupFiles(): void
    {
        if (!is_dir($this->directory)) return;
        $this->assertPath($this->directory, true, $this->base);
        $allowed = ['working.sqlite', 'working.sqlite-journal', 'working.sqlite-wal', 'working.sqlite-shm'];
        $iterator = new \DirectoryIterator($this->directory);
        foreach ($iterator as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isLink() || !$entry->isFile() || !in_array($entry->getFilename(), $allowed, true)) {
                throw new \RuntimeException('J6 working store contains an unsupported entry.');
            }
            $this->assertPath($entry->getPathname(), true, $this->directory);
            if (!unlink($entry->getPathname())) throw new \RuntimeException('J6 working store cleanup failed.');
        }
        unset($entry, $iterator);
        if (!rmdir($this->directory)) throw new \RuntimeException('J6 working store cleanup failed.');
    }

    private function assertPath(string $path, bool $mustExist, string $parent): void
    {
        $parentReal = realpath($parent);
        $resolved = $mustExist ? realpath($path) : $path;
        if ($parentReal === false || ($mustExist && $resolved === false)
            || strtolower(rtrim(dirname((string) $resolved), '/\\')) !== strtolower(rtrim($parentReal, '/\\'))) {
            throw new \RuntimeException('J6 working store path escaped its owned directory.');
        }
    }

    private function identifier(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException('J6 working identity is invalid.');
        $value = (string) $value;
        if ($value === '' || strlen($value) > 160 || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException('J6 working identity is invalid.');
        }
        return $value;
    }

    private function poolKind(string $kind): void
    {
        if (!in_array($kind, ['revenue', 'expense'], true)) throw new \InvalidArgumentException('J6 funding pool kind is invalid.');
    }

    private function jsonPages(\PDOStatement $statement, int $limit): iterable
    {
        $page = [];
        while (($json = $statement->fetchColumn()) !== false) {
            $page[] = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
            if (count($page) === $limit) {
                yield $page;
                $page = [];
            }
        }
        if ($page !== []) yield $page;
    }

    private function pageLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('J6 working page limit is invalid.');
    }

    private function hash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new \InvalidArgumentException('J6 working hash is invalid.');
    }

    private function cents(mixed $value): int
    {
        if (!is_int($value)) throw new \InvalidArgumentException('J6 working amount must be exact integer cents.');
        return $value;
    }
}
