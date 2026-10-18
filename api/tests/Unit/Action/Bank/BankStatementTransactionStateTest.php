<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankStatementTransactionStateTest extends TestCase
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
        $pdo->beginTransaction();
        $pdo->exec('SAVEPOINT bank_statement_test');
        $pdo->exec('RELEASE SAVEPOINT bank_statement_test');

        $original = new PDOException('synthetic aborted transaction');
        $original->errorInfo = ['HY000', $driverCode, 'synthetic aborted transaction'];
        $caught = null;
        try {
            try {
                throw $original;
            } catch (\Throwable $failure) {
                (new \ReflectionMethod(BankStatementAction::class, 'rollbackAtomic'))->invoke(
                    null,
                    $pdo,
                    false,
                    'bank_statement_test',
                    $failure,
                );
                throw $failure;
            }
        } catch (\Throwable $failure) {
            $caught = $failure;
        }

        self::assertSame($original, $caught);
        self::assertFalse($pdo->inTransaction());
    }
}
