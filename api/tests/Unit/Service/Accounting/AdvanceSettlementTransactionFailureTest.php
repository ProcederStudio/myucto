<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AdvanceSettlementSync;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvanceSettlementTransactionFailureTest extends TestCase
{
    #[DataProvider('failures')]
    public function testFatalTransactionFailureEscapesBestEffort(int $driverCode, bool $serverRolledBack, string $service): void
    {
        $failure = $driverCode === 0
            ? new PostingException('period_not_open', 'Synthetic closed period')
            : new \PDOException('Synthetic transaction failure');
        if ($failure instanceof \PDOException) {
            $failure->errorInfo = ['40001', $driverCode, 'Synthetic transaction failure'];
        }
        $pdo = new class ($failure, $serverRolledBack) extends \PDO {
            public function __construct(private \Throwable $failure, private bool $serverRolledBack)
            {
                parent::__construct('sqlite::memory:');
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if ($this->serverRolledBack) {
                    $this->rollBack();
                }
                throw $this->failure;
            }
        };
        $pdo->exec('CREATE TABLE marker (id INTEGER)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO marker VALUES (1)');
        $connection = new Connection(new Config([]));
        (new \ReflectionProperty($connection, 'pdo'))->setValue($connection, $pdo);
        $sync = (new \ReflectionClass($service))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($sync, 'db'))->setValue($sync, $connection);
        $caught = null;
        try {
            if ($sync instanceof AdvanceSettlementSync) {
                $sync->syncAdvances(1, ['purchase' => [1]]);
            } else {
                self::assertSame('error', $sync->handleTransaction(1)['reason']);
            }
        } catch (\Throwable $e) {
            $caught = $e;
        }
        if (in_array($driverCode, [0, 1205], true)) {
            self::assertNull($caught);
            self::assertTrue($pdo->inTransaction());
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM marker')->fetchColumn());
            $pdo->rollBack();
        } else {
            self::assertSame($failure, $caught, 'Transaction abort must not be swallowed or replaced by a missing-savepoint exception.');
            self::assertFalse($pdo->inTransaction());
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM marker')->fetchColumn());
        }
    }

    public static function failures(): array
    {
        $cases = [];
        foreach ([AdvanceSettlementSync::class, BankPostingService::class] as $service) {
            foreach ([[1020, false], [1213, false], [1213, true], [1205, false], [0, false]] as [$code, $rolledBack]) {
                $cases[] = [$code, $rolledBack, $service];
            }
        }
        return $cases;
    }
}
