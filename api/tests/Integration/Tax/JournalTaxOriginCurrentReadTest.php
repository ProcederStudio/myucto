<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\TestCase;

final class JournalTaxOriginCurrentReadTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testCurrentReadSeesNewReversalChainWithoutReplacingCallerSnapshot(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $pdo = $db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $seed);
        $supplier = $this->createIsolatedSupplier($pdo, $seed);
        $otherContainer = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        $other = Connection::withoutSharedTestConnection(fn () => $otherContainer->get(Connection::class));
        $snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        try {
            $period = (new AccountingPeriodRepository($db))->create($supplier, 2096, '2096-01-01', '2096-12-31');
            $insert = static function (\PDO $connection, string $type, ?int $sourceId) use ($supplier, $period): int {
                $connection->prepare("INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type,source_id) VALUES (?,?,'2096-06-01',?,?)")
                    ->execute([$supplier, $period, $type, $sourceId]);
                return (int) $connection->lastInsertId();
            };
            $root = $insert($pdo, 'closing', $period);
            $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
            $pdo->beginTransaction();
            $sql = static fn (bool $current): string => 'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplier, $current) . ' SELECT id,source_type,source_id FROM tax_journal_origins ORDER BY id';
            self::assertSame([], $pdo->query($sql(false))->fetchAll(\PDO::FETCH_ASSOC));
            $first = $insert($other->pdo(), 'manual', null);
            $second = $insert($other->pdo(), 'manual', null);
            $other->pdo()->prepare('UPDATE journal_entries SET reversed_by = ? WHERE supplier_id = ? AND id = ?')->execute([$first, $supplier, $root]);
            $other->pdo()->prepare('UPDATE journal_entries SET reversed_by = ? WHERE supplier_id = ? AND id = ?')->execute([$second, $supplier, $first]);
            self::assertSame([], $pdo->query($sql(false))->fetchAll(\PDO::FETCH_ASSOC));
            (new DimensionPeriodGuard($db))->lockPeriods($supplier);
            $rows = $pdo->query($sql(true))->fetchAll(\PDO::FETCH_ASSOC);
            self::assertSame([$root, $first, $second], array_map('intval', array_column($rows, 'id')));
            self::assertSame(['closing', 'closing', 'closing'], array_column($rows, 'source_type'));
            self::assertSame([$period, $period, $period], array_map('intval', array_column($rows, 'source_id')));
            self::assertTrue($pdo->inTransaction());
            self::assertSame([], $pdo->query($sql(false))->fetchAll(\PDO::FETCH_ASSOC));
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $snapshotIsolation);
            $other->close();
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplier]);
        }
    }
}
