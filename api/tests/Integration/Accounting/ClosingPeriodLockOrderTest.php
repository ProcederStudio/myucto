<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ClosingPeriodLockOrderTest extends TestCase
{
    private Connection $dbA;
    private Connection $dbB;
    private AccountingPeriodRepository $periods;
    private ClosingService $closing;
    private JournalEntryRepository $journal;
    private int $supplier = 0;
    private int $earlier;
    private int $later;
    private int $version;

    protected function setUp(): void
    {
        $a = Bootstrap::buildContainer();
        $b = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class);
            return $container;
        });
        $this->dbA = $a->get(Connection::class);
        $this->dbB = $b->get(Connection::class);
        $this->periods = $a->get(AccountingPeriodRepository::class);
        $this->closing = $b->get(ClosingService::class);
        $this->journal = $b->get(JournalEntryRepository::class);
        $pdo = $this->dbA->pdo();
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->dbB->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $currency, $vat));
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['Synthetic lock order', 'Test 1', 'Test', '11000', $country, uniqid('period-') . '@example.com', $currency, $vat, 'double_entry']);
        $this->supplier = (int) $pdo->lastInsertId();
        $a->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $this->earlier = $this->periods->create($this->supplier, 2098, '2098-01-01', '2098-12-31');
        $this->later = $this->periods->create($this->supplier, 2099, '2099-01-01', '2099-12-31');
        $this->periods->setStatus($this->later, $this->supplier, 'closing');
        $this->version = (int) $this->periods->findById($this->supplier, $this->later)['row_version'];
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbB, $this->dbA] as $db) {
            if ($db->pdo()->inTransaction()) {
                $db->pdo()->rollBack();
            }
        }
        if ($this->supplier !== 0) {
            $pdo = $this->dbA->pdo();
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
        $this->dbB->close();
        $this->dbA->close();
    }

    public function testAssistedClosingDoesNotHoldLaterPeriodWhileWaitingForEarlier(): void
    {
        $a = $this->dbA->pdo();
        $b = $this->dbB->pdo();
        $a->beginTransaction();
        $this->periods->findForUpdate($this->earlier, $this->supplier);
        $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $b->beginTransaction();
        $b->prepare('UPDATE supplier SET city = ? WHERE id = ?')->execute(['OUTER-MARKER', $this->supplier]);
        $failure = null;
        try {
            $this->createAssistedEntry();
        } catch (PDOException $e) {
            $failure = $e;
        }
        self::assertNotNull($failure);
        self::assertSame(1205, (int) $failure->errorInfo[1]);
        self::assertTrue($b->inTransaction(), 'Ordinary lock timeout must preserve the caller transaction.');
        self::assertSame('OUTER-MARKER', $b->query('SELECT city FROM supplier WHERE id = ' . $this->supplier)->fetchColumn());
        $laterLockFailure = null;
        try {
            self::assertSame($this->later, (int) $a->query('SELECT id FROM accounting_periods WHERE id = ' . $this->later . ' FOR UPDATE NOWAIT')->fetchColumn());
        } catch (PDOException $e) {
            $laterLockFailure = $e;
        }
        self::assertNull($laterLockFailure, 'Closing owns the later period while waiting for the earlier one: a lock cycle is possible.');
        $a->rollBack();
        $b->rollBack();

        $result = $this->createAssistedEntry();
        $entry = $this->journal->find((int) $result['entry_id'], $this->supplier);
        self::assertSame($this->later, $entry['period_id']);
        self::assertSame([125.5, 125.5], array_column($entry['lines'], 'amount'));
    }

    public function testCurrentSnapshotConflictEndsOuterClosingTransaction(): void
    {
        $a = $this->dbA->pdo();
        $b = $this->dbB->pdo();
        $b->exec('SET SESSION innodb_snapshot_isolation = 1');
        $b->beginTransaction();
        self::assertSame('closing', $b->query('SELECT status FROM accounting_periods WHERE id = ' . $this->later)->fetchColumn());
        $b->prepare('UPDATE supplier SET city = ? WHERE id = ?')->execute(['ABORTED-MARKER', $this->supplier]);
        $a->prepare('UPDATE accounting_periods SET row_version = row_version + 1 WHERE id = ?')->execute([$this->later]);
        $failure = null;
        try {
            $this->createAssistedEntry();
        } catch (PDOException $e) {
            $failure = $e;
        }
        self::assertNotNull($failure);
        self::assertSame(1020, (int) $failure->errorInfo[1]);
        self::assertFalse($b->inTransaction(), 'Fatal current-read conflict must end the caller transaction.');
        self::assertSame('Test', $a->query('SELECT city FROM supplier WHERE id = ' . $this->supplier)->fetchColumn());
        self::assertSame(0, (int) $a->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ' . $this->supplier)->fetchColumn());
    }

    private function createAssistedEntry(): array
    {
        return $this->closing->createAssistedEntry($this->supplier, $this->later, 'estimates', [
            'row_version' => $this->version, 'rule_key' => 'estimate.liability',
            'amount' => 125.5, 'description' => 'Synthetic lock-order entry', 'counter_account' => '518',
        ]);
    }
}
