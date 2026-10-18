<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AdvanceSettlementSync;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Accounting\Card\CardPaymentAutomation;
use MyInvoice\Service\Accounting\Card\CardSettlementService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BestEffortAuditTransactionFailureTest extends TestCase
{
    #[DataProvider('auditFailures')]
    public function testAuditAbortCannotBeSwallowedAfterOrdinaryFailure(string $class, int $code): void
    {
        $pdo = new class extends \PDO {
            public function __construct() { parent::__construct('sqlite::memory:'); }
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                throw new PostingException('period_not_open', 'Synthetic ordinary failure');
            }
        };
        $pdo->exec('CREATE TABLE marker (id INTEGER)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO marker VALUES (1)');
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
        $failure = self::failure($code);
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())->method('log')->willThrowException($failure);
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'db'))->setValue($service, $db);
        (new \ReflectionProperty($service, 'activity'))->setValue($service, $activity);
        if ($service instanceof CardPaymentAutomation) {
            $regime = (new \ReflectionClass(CardClearingRegime::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty($regime, 'settingsMemo'))->setValue($regime, [1 => ['enabled' => true]]);
            (new \ReflectionProperty($service, 'regime'))->setValue($service, $regime);
        }
        $caught = null;
        try {
            if ($service instanceof AdvanceSettlementSync) $service->syncAdvances(1, ['purchase' => [1]]);
            elseif ($service instanceof CardPaymentAutomation) $service->afterPurchaseReady(1, 1);
            else $service->handleTransaction(1);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertSame($failure, $caught);
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM marker')->fetchColumn());
    }

    public static function auditFailures(): array
    {
        $cases = [];
        foreach ([AdvanceSettlementSync::class, BankPostingService::class, CardPaymentAutomation::class] as $class) {
            foreach ([1020, 1213] as $code) $cases[] = [$class, $code];
        }
        return $cases;
    }

    #[DataProvider('fatalCodes')]
    public function testCardSettlementAbortEscapesWithoutSavepointMasking(int $code): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE marker (id INTEGER)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO marker VALUES (1)');
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
        $failure = self::failure($code);
        $service = $this->getMockBuilder(BankPostingService::class)->disableOriginalConstructor()->onlyMethods(['liveCardClearingLine', 'syncCardSettlement'])->getMock();
        $service->method('liveCardClearingLine')->willReturn(['code' => '378.100']);
        $service->expects(self::once())->method('syncCardSettlement')->willThrowException($failure);
        (new \ReflectionProperty(BankPostingService::class, 'db'))->setValue($service, $db);
        (new \ReflectionProperty(BankPostingService::class, 'cardSettlement'))->setValue($service, (new \ReflectionClass(CardSettlementService::class))->newInstanceWithoutConstructor());
        $caught = null;
        try {
            (new \ReflectionMethod(BankPostingService::class, 'afterCardBankPosted'))->invoke($service, 1, 1, null);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertSame($failure, $caught);
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM marker')->fetchColumn());
    }

    public static function fatalCodes(): array { return [[1020], [1213]]; }

    #[DataProvider('telemetryFailures')]
    public function testAuxiliaryBankWritesCannotHideTransactionAbort(string $method, int $code): void
    {
        $failure = self::failure($code);
        $pdo = new class ($failure) extends \PDO {
            public function __construct(private \PDOException $failure) { parent::__construct('sqlite::memory:'); }
            public function prepare(string $query, array $options = []): \PDOStatement|false { throw $this->failure; }
        };
        $pdo->exec('CREATE TABLE marker (id INTEGER)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO marker VALUES (1)');
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
        $service = (new \ReflectionClass(BankPostingService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'db'))->setValue($service, $db);
        if ($method === 'recordAiDecision') {
            $dependency = (new \ReflectionClass(\MyInvoice\Service\Ai\AiSuggestionService::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty($dependency, 'db'))->setValue($dependency, $db);
            (new \ReflectionProperty($service, 'aiSuggestions'))->setValue($service, $dependency);
        } else {
            $dependency = (new \ReflectionClass(\MyInvoice\Service\Ai\EmbeddingWriter::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty($dependency, 'jobs'))->setValue($dependency, new \MyInvoice\Service\Ai\AiJobService($db));
            (new \ReflectionProperty($service, 'embeddingWriter'))->setValue($service, $dependency);
        }
        $caught = null;
        try {
            $arguments = $method === 'recordAiDecision' ? [1, 'llm', 1, 'accepted_count'] : [1, 1];
            (new \ReflectionMethod($service, $method))->invokeArgs($service, $arguments);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        if ($code === 1205) {
            self::assertNull($caught);
            self::assertTrue($pdo->inTransaction());
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM marker')->fetchColumn());
            $pdo->rollBack();
        } else {
            self::assertSame($failure, $caught);
            self::assertFalse($pdo->inTransaction());
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM marker')->fetchColumn());
        }
    }

    public static function telemetryFailures(): array
    {
        $cases = [];
        foreach (['recordAiDecision', 'enqueueBankEmbedding'] as $method) {
            foreach ([1020, 1213, 1205] as $code) $cases[] = [$method, $code];
        }
        return $cases;
    }

    private static function failure(int $code): \PDOException
    {
        $failure = new \PDOException('Synthetic fatal transaction failure');
        $failure->errorInfo = ['40001', $code, 'Synthetic fatal transaction failure'];
        return $failure;
    }
}
