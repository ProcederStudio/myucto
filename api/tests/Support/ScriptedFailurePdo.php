<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * SQLite v paměti se skutečnými transakcemi, kterému lze předepsat, že příkaz
 * obsahující daný text napoprvé selže zadanou výjimkou (typicky odvolanou
 * transakcí 1020/1213). Funkce NOW() je doplněná kvůli SQL psanému pro MariaDB.
 */
final class ScriptedFailurePdo extends \Pdo\Sqlite
{
    /** @var list<array{needle:string, failures:list<PDOException>}> */
    private array $script = [];

    /** @var array<string,int> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->createFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
    }

    public function failOn(string $needle, PDOException ...$failures): void
    {
        $this->script[] = ['needle' => $needle, 'failures' => array_values($failures)];
        $this->calls[$needle] = 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->fail($query);

        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->fail($statement);

        return parent::exec($statement);
    }

    private function fail(string $sql): void
    {
        foreach ($this->script as $i => $step) {
            if (!str_contains($sql, $step['needle'])) {
                continue;
            }
            $this->calls[$step['needle']]++;
            if ($step['failures'] !== []) {
                throw array_shift($this->script[$i]['failures']);
            }
        }
    }
}
