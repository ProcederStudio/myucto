<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementMatcherTransactionStateTest extends TestCase
{
    /** @return iterable<string,array{int}> */
    public static function abortedCodes(): iterable
    {
        yield 'snapshot conflict' => [1020];
        yield 'deadlock' => [1213];
    }

    #[DataProvider('abortedCodes')]
    public function testAbortedOuterTransactionDoesNotMaskOriginalFailureWithMissingSavepoint(int $driverCode): void
    {
        $pdo = new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $matcher = new StatementMatcher(
            $this->createStub(Connection::class),
            $this->createStub(FinalFromProformaCreator::class),
        );
        $pdo->beginTransaction();
        (new \ReflectionMethod($matcher, 'begin'))->invoke($matcher, $pdo);
        $pdo->exec('RELEASE SAVEPOINT stmt_match_0');

        $original = new PDOException('synthetic aborted transaction');
        $original->errorInfo = ['HY000', $driverCode, 'synthetic aborted transaction'];
        $caught = null;
        try {
            try {
                throw $original;
            } catch (\Throwable $failure) {
                (new \ReflectionMethod($matcher, 'rollBackIfOpen'))->invoke($matcher, $pdo, $failure);
                throw $failure;
            }
        } catch (\Throwable $failure) {
            $caught = $failure;
        }

        self::assertSame($original, $caught);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], (new \ReflectionProperty($matcher, 'txStack'))->getValue($matcher));
    }
}
