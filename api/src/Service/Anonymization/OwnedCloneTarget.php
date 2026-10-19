<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use PDO;

final class OwnedCloneTarget
{
    public const OWNERSHIP_TABLE = '__myucto_clone_run_owner';

    private ?string $runToken = null;

    private bool $released = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $target,
        private readonly string $lockName,
        private readonly int $connectionId,
    ) {}

    public static function acquire(PDO $pdo, string $target): self
    {
        if (!AnonymizationOptions::isSafeDatabaseName($target)) {
            throw new \InvalidArgumentException('Neplatný název cílové databáze.');
        }
        $lockName = 'myucto.clone.' . substr(hash('sha256', strtolower($target)), 0, 48);
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lockName]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException("Pro cílovou databázi {$target} už běží jiná operace.");
        }

        return new self($pdo, $target, $lockName, (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
    }

    public function name(): string
    {
        return $this->target;
    }

    public function create(string $charset, string $collation): void
    {
        $this->assertActiveLock();
        if ($this->runToken !== null) {
            throw new \LogicException('Cílová databáze už byla tímto během vytvořena.');
        }
        foreach ([$charset, $collation] as $identifier) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
                throw new \InvalidArgumentException('Neplatná znaková sada nebo řazení cílové databáze.');
            }
        }
        $this->pdo->exec('CREATE DATABASE ' . DatabaseCloner::quote($this->target)
            . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation);
        $token = bin2hex(random_bytes(32));
        try {
            $table = DatabaseCloner::quote($this->target) . '.' . DatabaseCloner::quote(self::OWNERSHIP_TABLE);
            $this->pdo->exec("CREATE TABLE {$table} ("
                . 'singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY, '
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL) ENGINE=InnoDB');
            $statement = $this->pdo->prepare("INSERT INTO {$table} (singleton, run_token, created_at) VALUES (1, ?, UTC_TIMESTAMP(6))");
            $statement->execute([$token]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Cílová databáze {$this->target} byla vytvořena, ale vlastnictví běhu nelze doložit; databáze zůstala zachována.",
                0,
                $e,
            );
        }
        $this->runToken = $token;
    }

    public function assertOwned(): void
    {
        $this->assertActiveLock();
        if ($this->runToken === null) {
            throw new \RuntimeException('Aktuální běh nevlastní vytvořenou cílovou databázi.');
        }
        $table = DatabaseCloner::quote($this->target) . '.' . DatabaseCloner::quote(self::OWNERSHIP_TABLE);
        try {
            $token = $this->pdo->query("SELECT run_token FROM {$table} WHERE singleton = 1")->fetchColumn();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Vlastnictví cílové databáze nelze ověřit.', 0, $e);
        }
        if (!is_string($token) || !hash_equals($this->runToken, $token)) {
            throw new \RuntimeException('Cílová databáze má jiné vlastnictví běhu.');
        }
    }

    public function dropIfOwned(): bool
    {
        if ($this->runToken === null) {
            return false;
        }
        $this->assertOwned();
        $this->pdo->exec('DROP DATABASE ' . DatabaseCloner::quote($this->target));
        $this->runToken = null;

        return true;
    }

    public function complete(): void
    {
        $this->assertOwned();
        $this->pdo->exec('DROP TABLE ' . DatabaseCloner::quote($this->target) . '.' . DatabaseCloner::quote(self::OWNERSHIP_TABLE));
        $this->runToken = null;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$this->lockName]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Zámek cílové databáze nelze uvolnit.');
        }
        $this->released = true;
    }

    private function assertActiveLock(): void
    {
        if ($this->released) {
            throw new \LogicException('Zámek cílové databáze už byl uvolněn.');
        }
        $statement = $this->pdo->prepare('SELECT IS_USED_LOCK(?)');
        $statement->execute([$this->lockName]);
        if ((int) $statement->fetchColumn() !== $this->connectionId) {
            throw new \RuntimeException('Aktuální spojení nevlastní zámek cílové databáze.');
        }
    }
}
