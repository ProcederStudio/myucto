<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;

/**
 * Transparent PDO subclass — loguje každou PDOException přes Monolog
 * a chybu rethrowne. Aktivuje LoggingPdoStatement přes ATTR_STATEMENT_CLASS,
 * takže `$pdo->prepare(...)->execute(...)` se loguje automaticky bez úprav callerů.
 *
 * Loguje se i prepare() (native prepares posílají statement do MariaDB hned)
 * a one-shot exec()/query().
 */
final class LoggingPdo extends PDO
{
    private string $databaseName;
    public function __construct(
        string $dsn,
        string $username,
        string $password,
        array $options,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $schemaCachePathForDatabase = null,
        ?SlowQueryLog $slowLog = null,
    ) {
        parent::__construct($dsn, $username, $password, $options);
        preg_match('/(?:^|;)dbname=([^;]*)/', substr($dsn, strpos($dsn, ':') + 1), $match);
        $this->databaseName = $match[1] ?? '';
        $this->slowLog = $slowLog?->enabled() ? $slowLog : null;
        $weak = \WeakReference::create($this);
        $schemaChanged = static function (string $sql) use ($weak): void {
            $weak->get()?->noteSchemaStatement($sql);
        };
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggingPdoStatement::class, [$logger, $schemaChanged, $this->slowLog, $this->databaseName]]);
    }

    private readonly ?SlowQueryLog $slowLog;

    /** @var array<string,\Closure():void> */
    private array $afterCommit = [];

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    /**
     * Spustí krok po commitu probíhající transakce, mimo ni hned. Rollback ho zahodí.
     * Týž klíč se v jedné transakci zaregistruje jen jednou.
     * Chyba kroku se nevyhazuje: commit už proběhl a volající ho má hlásit jako úspěch.
     */
    public function afterCommit(string $key, \Closure $callback): void
    {
        if (!$this->inTransaction()) {
            $this->runAfterCommit([$callback]);
            return;
        }
        $this->afterCommit[$key] ??= $callback;
    }

    public function commit(): bool
    {
        $result = parent::commit();
        $callbacks = $this->afterCommit;
        $this->afterCommit = [];
        $this->runAfterCommit($callbacks);

        return $result;
    }

    public function rollBack(): bool
    {
        $this->afterCommit = [];

        return parent::rollBack();
    }

    /** @param array<array-key,\Closure():void> $callbacks */
    private function runAfterCommit(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->logger->error('Krok po commitu selhal: ' . $e->getMessage(), ['exception' => $e::class]);
            }
        }
    }

    private function noteSchemaStatement(string $sql): void
    {
        if (SchemaMetadataProvider::isSchemaStatement($sql)) {
            $previous = $this->databaseName;
            $this->databaseName = (string) parent::query('SELECT DATABASE()')->fetchColumn();
            if ($previous !== $this->databaseName) {
                $previousPath = $this->schemaCachePathForDatabase === null ? null : ($this->schemaCachePathForDatabase)($previous);
                SchemaCache::invalidate($previousPath);
            }
            $path = $this->schemaCachePathForDatabase === null ? null : ($this->schemaCachePathForDatabase)($this->databaseName);
            SchemaCache::invalidate($path);
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            return parent::prepare($query, $options);
        } catch (PDOException $e) {
            DbErrorLogger::log($this->logger, $e, $query, []);
            throw $e;
        }
    }

    public function exec(string $statement): int|false
    {
        $started = $this->slowLog !== null ? hrtime(true) : 0;
        try {
            $result = parent::exec($statement);
        } catch (PDOException $e) {
            DbErrorLogger::log($this->logger, $e, $statement, []);
            throw $e;
        }
        $this->slowLog?->record($statement, [], $started, $this->databaseName);
        // Až PO úspěšném zápisu — neúspěšný příkaz nemá co invalidovat.
        WriteWatcher::noteStatement($statement);
        $this->noteSchemaStatement($statement);

        return $result;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $started = $this->slowLog !== null ? hrtime(true) : 0;
        try {
            $result = parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $e) {
            DbErrorLogger::log($this->logger, $e, $query, []);
            throw $e;
        }
        $this->slowLog?->record($query, [], $started, $this->databaseName);
        WriteWatcher::noteStatement($query);
        $this->noteSchemaStatement($query);

        return $result;
    }
}
