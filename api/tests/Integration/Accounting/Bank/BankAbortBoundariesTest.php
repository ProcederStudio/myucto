<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CardClearingSettingsRepository;
use MyInvoice\Service\Accounting\Bank\StaleSuggestionSweep;
use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Accounting\Card\CardPaymentAutomation;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Automation\RuleProposalService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class BankAbortBoundariesTest extends BankPostingTestCase
{
    private string $table;
    private PDO $actual;
    private int $snapshotMode;
    private mixed $worker = null;
    private array $pipes = [];
    private ?string $readyFile = null;

    protected function setUp(): void
    {
        $this->actual = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        self::assertStringEndsWith('_test', (string) $this->actual->query('SELECT DATABASE()')->fetchColumn());
        self::assertFalse($this->actual->inTransaction());
        $this->snapshotMode = (int) $this->actual->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $this->actual->exec('SET SESSION innodb_snapshot_isolation = 1');
        $this->table = 'test_bank_abort_' . bin2hex(random_bytes(6));
        $this->actual->exec("CREATE TABLE {$this->table} (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
        $values = array_map(static fn (int $id): string => '(' . $id . ',0)', range(1, 5002));
        $this->actual->exec("INSERT INTO {$this->table} VALUES " . implode(',', $values));
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->actual->inTransaction()) $this->actual->rollBack();
        $this->finishWorker();
        $this->actual->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotMode);
        $this->actual->exec("DROP TABLE {$this->table}");
        if (isset($this->db)) (new \ReflectionProperty($this->db, 'pdo'))->setValue($this->db, $this->actual);
        parent::tearDown();
    }

    #[DataProvider('boundaries')]
    public function testAbortEscapesAndOrdinaryFailureRemainsBestEffort(string $boundary, int $driverCode): void
    {
        $statement = $this->statement();
        $tx = $this->transaction($statement, -100.00);
        $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId, 'bank_transaction_id' => $tx,
            'source' => 'rule', 'debit_account_code' => '568', 'credit_account_code' => '221',
            'amount' => 100, 'note' => 'liability_prescription_missing',
        ]);
        $this->container->get(CardClearingSettingsRepository::class)->save($this->supplierId, ['enabled' => true], $this->userId);
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
        $work = match ($boundary) {
            'card' => fn () => $this->container->get(CardPaymentAutomation::class)->afterPurchaseReady($this->supplierId, 1, $this->userId),
            'proposal' => fn () => $this->container->get(RuleProposalService::class)->apply($this->supplierId, $this->userId, [[
                'name' => 'Synthetic boundary', 'direction' => 'outgoing',
                'debit_account_code' => '568', 'credit_account_code' => '221', 'message_contains' => 'synthetic',
            ]], false),
            'backfill' => fn () => $this->backfill->run($this->supplierId, null, false, true, $this->userId),
            'sweep' => fn () => $this->container->get(StaleSuggestionSweep::class)->run(true, $this->supplierId),
        };
        $this->actual->exec("UPDATE {$this->table} SET value = 7 WHERE id = 2");
        self::assertSame(0, (int) $this->actual->query("SELECT value FROM {$this->table} WHERE id = 1")->fetchColumn());
        if ($driverCode === 1020) {
            $other = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
            $other->pdo()->exec("UPDATE {$this->table} SET value = 1 WHERE id = 1");
            $other->close();
        } elseif ($driverCode === 1213) {
            $this->startWorker();
        }
        $raised = null;
        $hits = 0;
        $fault = function (string $sql) use ($boundary, $driverCode, &$raised, &$hits): void {
            if ($hits !== 0) return;
            $methods = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
            $matches = match ($boundary) {
                'card' => str_contains($sql, 'SELECT pi.status'),
                'proposal' => in_array('validate', $methods, true),
                'backfill' => in_array('reconcileMatchedIncoming', $methods, true),
                'sweep' => in_array('isOpenDate', $methods, true),
            };
            if (!$matches) return;
            $hits++;
            try {
                if (in_array($driverCode, [1020, 1213], true)) {
                    $this->actual->query("SELECT value FROM {$this->table} WHERE id = 1 FOR UPDATE")->fetchColumn();
                    throw new \LogicException('Expected an actual MariaDB transaction conflict.');
                }
                if ($driverCode === 0) throw new PostingException('period_not_open', 'Synthetic domain failure');
                $failure = new \PDOException('Synthetic statement timeout');
                $failure->errorInfo = ['HY000', $driverCode, 'Synthetic statement timeout'];
                throw $failure;
            } catch (\Throwable $e) {
                $raised = $e;
                throw $e;
            }
        };
        $proxy = new BankAbortBoundaryPdo($this->actual, $fault);
        (new \ReflectionProperty($this->db, 'pdo'))->setValue($this->db, $proxy);
        $caught = null;
        $result = null;
        try {
            $result = $work();
        } catch (\Throwable $e) {
            $caught = $e;
        }
        self::assertSame(1, $hits, 'The intended service boundary must be reached.');
        if (in_array($driverCode, [1020, 1213], true)) {
            self::assertInstanceOf(\PDOException::class, $raised);
            self::assertSame($driverCode, (int) $raised->errorInfo[1], 'The failure must come from MariaDB, not a synthetic exception.');
            self::assertSame($raised, $caught, 'The original abort must escape; dry-run cleanup must not mask it.');
            self::assertFalse($proxy->inTransaction());
            $this->finishWorker();
            self::assertSame($driverCode === 1213 ? 1 : 0, (int) $this->actual->query("SELECT value FROM {$this->table} WHERE id = 2")->fetchColumn(), 'The caller marker 7 must be rolled back.');
        } else {
            self::assertNull($caught);
            self::assertTrue($proxy->inTransaction());
            self::assertSame(7, (int) $this->actual->query("SELECT value FROM {$this->table} WHERE id = 2")->fetchColumn());
            match ($boundary) {
                'card' => self::assertSame(['matched_transaction_id' => null, 'settlements' => 0], $result),
                'proposal' => self::assertCount(1, $result['skipped']),
                'backfill', 'sweep' => self::assertCount(1, $result['errors']),
            };
        }
    }

    public static function boundaries(): array
    {
        $cases = [];
        foreach (['card', 'proposal', 'backfill', 'sweep'] as $boundary) {
            foreach ([1020, 1213, 1205, 0] as $code) $cases[$boundary . '-' . $code] = [$boundary, $code];
        }
        return $cases;
    }

    private function startWorker(): void
    {
        $this->readyFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->table . '.ready';
        $root = dirname(__DIR__, 5);
        $this->worker = proc_open([PHP_BINARY, $root . '/api/tests/Fixtures/bank-abort-deadlock-worker.php', $root, $this->table, $this->readyFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        self::assertIsResource($this->worker);
        fclose($this->pipes[0]);
        $deadline = microtime(true) + 10;
        $stage = '';
        while ($stage !== 'ready' && microtime(true) < $deadline) {
            clearstatcache(true, $this->readyFile);
            $stage = is_file($this->readyFile) ? (string) file_get_contents($this->readyFile) : '';
            if ($stage === 'ready') break;
            if (!proc_get_status($this->worker)['running']) break;
            usleep(10000);
        }
        self::assertSame('ready', $stage, 'The independent session must hold the opposite row lock.');
    }

    private function finishWorker(): void
    {
        if (!is_resource($this->worker)) return;
        $deadline = microtime(true) + 12;
        while (($status = proc_get_status($this->worker))['running'] && microtime(true) < $deadline) usleep(10000);
        if ($status['running']) proc_terminate($this->worker);
        $output = stream_get_contents($this->pipes[1]);
        $error = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->worker);
        $this->worker = null;
        if ($this->readyFile !== null && is_file($this->readyFile)) unlink($this->readyFile);
        self::assertSame('', $error);
        self::assertSame("DEADLOCK_PEER_COMMITTED\n", $output);
    }
}

final class BankAbortBoundaryPdo extends PDO
{
    public function __construct(private PDO $actual, private \Closure $fault) {}
    public function prepare(string $query, array $options = []): \PDOStatement|false { ($this->fault)($query); return $this->actual->prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false { return $fetchMode === null ? $this->actual->query($query) : $this->actual->query($query, $fetchMode, ...$fetchModeArgs); }
    public function exec(string $statement): int|false { return $this->actual->exec($statement); }
    public function beginTransaction(): bool { return $this->actual->beginTransaction(); }
    public function commit(): bool { return $this->actual->commit(); }
    public function rollBack(): bool { return $this->actual->rollBack(); }
    public function inTransaction(): bool { return $this->actual->inTransaction(); }
    public function lastInsertId(?string $name = null): string|false { return $this->actual->lastInsertId($name); }
    public function getAttribute(int $attribute): mixed { return $this->actual->getAttribute($attribute); }
    public function setAttribute(int $attribute, mixed $value): bool { return $this->actual->setAttribute($attribute, $value); }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return $this->actual->quote($string, $type); }
}
