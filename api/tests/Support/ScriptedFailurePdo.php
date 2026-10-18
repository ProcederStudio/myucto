<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;
use PDOException;
use PDOStatement;

/**
 * SQLite v paměti se skutečnými transakcemi, kterému lze předepsat, že příkaz
 * obsahující daný text napoprvé selže zadanou výjimkou (typicky odvolanou
 * transakcí 1020/1213). Funkce NOW() je doplněná kvůli SQL psanému pro MariaDB.
 *
 * Odvolání se chová jako v MariaDB: `inTransaction()` dál vrací true, savepointy ale
 * zmizely, takže `ROLLBACK TO SAVEPOINT` i `RELEASE SAVEPOINT` skončí chybou 1305.
 * Stav ukončí až `rollBack()`.
 */
final class ScriptedFailurePdo extends \Pdo\Sqlite
{
    /** @var list<array{needle:string, failures:list<PDOException>}> */
    private array $script = [];

    /** @var array<string,int> */
    public array $calls = [];

    private bool $serverAborted = false;

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

    /** Server odvolal transakci; vrátí výjimku, kterou volající vyhodí. */
    public function abortWith(PDOException $failure): PDOException
    {
        if (TransactionFailure::aborted($failure) && parent::inTransaction()) {
            $this->serverAborted = true;
        }

        return $failure;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->fail($query);

        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        if ($this->serverAborted && preg_match('/^\s*(ROLLBACK\s+TO|RELEASE)\s+SAVEPOINT\b/i', $statement) === 1) {
            $missing = new PDOException('SQLSTATE[42000]: SAVEPOINT does not exist');
            $missing->errorInfo = ['42000', 1305, 'SAVEPOINT does not exist'];
            throw $missing;
        }
        $this->fail($statement);

        return parent::exec($statement);
    }

    public function rollBack(): bool
    {
        $this->serverAborted = false;

        return parent::rollBack();
    }

    private function fail(string $sql): void
    {
        foreach ($this->script as $i => $step) {
            if (!str_contains($sql, $step['needle'])) {
                continue;
            }
            $this->calls[$step['needle']]++;
            if ($step['failures'] !== []) {
                throw $this->abortWith(array_shift($this->script[$i]['failures']));
            }
        }
    }
}
