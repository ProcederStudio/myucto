<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionFailureTest extends TestCase
{
    /** @return iterable<string,array{int,bool}> */
    public static function failures(): iterable
    {
        yield 'snapshot conflict aborts transaction' => [1020, true];
        yield 'deadlock aborts transaction' => [1213, true];
        yield 'lock timeout normally aborts statement only' => [1205, false];
        yield 'duplicate key does not abort transaction' => [1062, false];
    }

    #[DataProvider('failures')]
    public function testRecognizesOnlyMariaDbWholeTransactionFailures(int $driverCode, bool $expected): void
    {
        self::assertSame($expected, TransactionFailure::aborted(self::pdoFailure($driverCode)));
    }

    public function testRequiresPdoDriverErrorCode(): void
    {
        self::assertFalse(TransactionFailure::aborted(new \RuntimeException('synthetic failure', 1020)));
        self::assertFalse(TransactionFailure::aborted(new PDOException('synthetic failure', 1213)));
    }

    #[DataProvider('failures')]
    public function testRollbackOnlyHandlesWholeTransactionFailures(int $driverCode, bool $expected): void
    {
        $pdo = new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();

        self::assertSame($expected, TransactionFailure::rollbackIfAborted($pdo, self::pdoFailure($driverCode)));
        self::assertSame(!$expected, $pdo->inTransaction());
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private static function pdoFailure(int $driverCode): PDOException
    {
        $failure = new PDOException('synthetic database failure');
        $failure->errorInfo = ['HY000', $driverCode, 'synthetic database failure'];

        return $failure;
    }
}
