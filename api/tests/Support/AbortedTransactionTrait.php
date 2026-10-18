<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Dvojníci pro chyby, kterými MariaDB odvolá celou transakci (1020/1213).
 *
 * Skutečnou 1020 umí vyvolat dvě spojení (vzor AutoPostSnapshotConflictTest). Pro
 * ověření, že vlastník transakce chybu nespolkne a zopakuje ji, stačí kolaborant,
 * který ji napoprvé hodí, a spojení se skutečnou transakcí (SQLite v paměti).
 */
trait AbortedTransactionTrait
{
    protected static function abortedFailure(int $code = 1213): PDOException
    {
        $message = $code === 1020 ? 'Record has changed since last read' : 'Deadlock found when trying to get lock';
        $failure = new PDOException('SQLSTATE[40001]: ' . $message);
        $failure->errorInfo = ['40001', $code, $message];

        return $failure;
    }

    /**
     * Odpověď kolaboranta: prvních `$failures` volání hodí odvolanou transakci,
     * pak vrací `$then()`.
     *
     * @template T
     * @param \Closure(mixed...):T $then
     * @return \Closure(mixed...):T
     */
    protected static function abortingThen(\Closure $then, int $failures = 1, int $code = 1213): \Closure
    {
        return static function (mixed ...$args) use (&$failures, $then, $code): mixed {
            if ($failures > 0) {
                $failures--;
                throw self::abortedFailure($code);
            }

            return $then(...$args);
        };
    }

    /**
     * Instance služby bez konstruktoru, jen s kolaboranty, kterých se test dotkne.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string,mixed> $properties
     * @return T
     */
    protected static function serviceWith(string $class, array $properties): object
    {
        $reflection = new \ReflectionClass($class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($service, $value);
        }

        return $service;
    }

    /** @return array{0:Connection,1:PDO} */
    protected function transactionalConnection(): array
    {
        $pdo = new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return [$db, $pdo];
    }
}
