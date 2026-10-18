<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class TransactionFailureRetryingTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
    }

    public function testAbortedTransactionIsRetriedWithFreshTransaction(): void
    {
        $calls = 0;
        $result = TransactionFailure::retrying($this->pdo, function () use (&$calls): string {
            $calls++;
            $this->pdo->beginTransaction();
            if ($calls < 3) {
                throw self::failure($calls === 1 ? 1020 : 1213);
            }
            $this->pdo->commit();
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(3, $calls);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testGivesUpAfterLastAttempt(): void
    {
        $calls = 0;
        try {
            TransactionFailure::retrying($this->pdo, function () use (&$calls): never {
                $calls++;
                throw self::failure(1020);
            }, 3);
            self::fail('Po posledním pokusu musí chyba probublat.');
        } catch (PDOException $e) {
            self::assertSame(1020, $e->errorInfo[1]);
        }
        self::assertSame(3, $calls);
    }

    public function testOtherFailureIsNotRetried(): void
    {
        $calls = 0;
        $this->expectException(PDOException::class);
        try {
            TransactionFailure::retrying($this->pdo, function () use (&$calls): never {
                $calls++;
                throw self::failure(1062);
            });
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testInsideCallerTransactionNothingIsRetried(): void
    {
        $this->pdo->beginTransaction();
        $calls = 0;
        try {
            TransactionFailure::retrying($this->pdo, function () use (&$calls): never {
                $calls++;
                throw self::failure(1213);
            });
            self::fail('Chyba musí dojít k vlastníkovi vnější transakce.');
        } catch (PDOException) {
        }
        self::assertSame(1, $calls);
    }

    private static function failure(int $code): PDOException
    {
        $e = new PDOException('SQLSTATE[HY000]: General error: ' . $code);
        $e->errorInfo = ['HY000', $code, 'test'];
        return $e;
    }
}
