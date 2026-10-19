<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use PDO;

final class CloneProtectedRows
{
    private bool $dropped = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
        private readonly string $table,
        private readonly string $runToken,
    ) {}

    public static function create(PDO $pdo): self
    {
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || !AnonymizationOptions::isSafeDatabaseName($database)) {
            throw new \RuntimeException('Staging chráněných řádků vyžaduje vybranou databázi.');
        }
        $suffix = bin2hex(random_bytes(8));
        $table = '__myucto_clone_protected_' . $suffix;
        $token = bin2hex(random_bytes(32));
        $quoted = DatabaseCloner::quote($table);
        $pdo->exec("CREATE TABLE {$quoted} ("
            . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'table_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'row_id BIGINT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (run_token, table_name, row_id)) ENGINE=InnoDB');
        $pdo->prepare("INSERT INTO {$quoted} (run_token, table_name, row_id) VALUES (?, '__run', 0)")->execute([$token]);

        return new self($pdo, $database, $table, $token);
    }

    public function stageTable(): string
    {
        return $this->table;
    }

    public function protect(FinancialArchiveProtectedTable $table, int $rowId): void
    {
        $this->protectMany($table, [$rowId]);
    }

    /** @param list<int> $rowIds */
    public function protectMany(FinancialArchiveProtectedTable $table, array $rowIds): void
    {
        $this->assertActive();
        if ($rowIds === [] || count($rowIds) > 500) {
            throw new \InvalidArgumentException('Dávka chráněných primárních klíčů musí obsahovat 1 až 500 položek.');
        }
        $parameters = [];
        foreach ($rowIds as $rowId) {
            if ($rowId <= 0) {
                throw new \InvalidArgumentException('Chráněný primární klíč musí být kladný.');
            }
            array_push($parameters, $this->runToken, $table->value, $rowId);
        }
        $values = implode(',', array_fill(0, count($rowIds), '(?,?,?)'));
        $statement = $this->pdo->prepare('INSERT IGNORE INTO ' . DatabaseCloner::quote($this->table)
            . " (run_token, table_name, row_id) VALUES {$values}");
        $statement->execute($parameters);
    }

    public function count(FinancialArchiveProtectedTable $table): int
    {
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($this->table)
            . ' WHERE run_token = ? AND table_name = ?');
        $statement->execute([$this->runToken, $table->value]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param list<string> $columns
     * @return list<array<string,mixed>>
     */
    public function selectUnprotectedPage(
        FinancialArchiveProtectedTable $table,
        array $columns,
        ?int $afterId,
        int $limit,
    ): array {
        $this->assertActive();
        if ($columns === [] || $limit < 1 || $limit > 1000 || $afterId !== null && $afterId < 0) {
            throw new \InvalidArgumentException('Neplatný bounded výběr chráněných řádků.');
        }
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', $column) !== 1) {
                throw new \InvalidArgumentException('Neplatný sloupec bounded výběru.');
            }
        }
        $select = implode(', ', array_map(
            static fn (string $column): string => 't.' . DatabaseCloner::quote($column) . ' AS ' . DatabaseCloner::quote($column),
            $columns,
        ));
        $sql = "SELECT {$select} FROM " . DatabaseCloner::quote($table->value) . ' t'
            . ' WHERE ' . ($afterId === null ? '1 = 1' : 't.id > ?')
            . ' AND NOT EXISTS (SELECT 1 FROM ' . DatabaseCloner::quote($this->table) . ' p'
            . ' WHERE p.run_token = ? AND p.table_name = ? AND p.row_id = t.id)'
            . ' ORDER BY t.id LIMIT ' . $limit;
        $statement = $this->pdo->prepare($sql);
        $parameters = $afterId === null
            ? [$this->runToken, $table->value]
            : [$afterId, $this->runToken, $table->value];
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function drop(): void
    {
        $this->assertActive();
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($this->table)
            . " WHERE run_token = ? AND table_name = '__run' AND row_id = 0");
        $statement->execute([$this->runToken]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Nelze doložit vlastnictví staging tabulky chráněných řádků.');
        }
        $this->pdo->exec('DROP TABLE ' . DatabaseCloner::quote($this->table));
        $this->dropped = true;
    }

    private function assertActive(): void
    {
        if ($this->dropped) {
            throw new \LogicException('Staging tabulka chráněných řádků už byla odstraněna.');
        }
        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->database) {
            throw new \RuntimeException('Staging tabulka chráněných řádků patří jiné databázi.');
        }
    }
}
