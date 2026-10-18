<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Tax\Return\{TaxLossService, TaxReturnService, TaxReturnException};
use MyInvoice\Tests\Integration\Tax\DppoFinalSnapshotAuditTest;
use PHPUnit\Framework\TestCase;

final class TaxReturnLossAllocationTest extends TestCase
{
    private DppoFinalSnapshotAuditTest $fixture;
    private Connection $db;
    private TaxReturnService $returns;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->fixture = new DppoFinalSnapshotAuditTest('testFinalDppoDetailAndXmlStayFrozenAfterLedgerAndSupplierChanges');
        (new \ReflectionMethod($this->fixture, 'setUp'))->invoke($this->fixture);
        foreach (['db', 'returns', 'supplierId', 'userId'] as $key) {
            $this->$key = (new \ReflectionProperty($this->fixture, $key))->getValue($this->fixture);
        }
    }

    protected function tearDown(): void
    {
        (new \ReflectionMethod($this->fixture, 'tearDown'))->invoke($this->fixture);
    }

    public function testNative500FinalizationRejectsUnallocatedLossBeforeSnapshotOrLedgerWrites(): void
    {
        $losses = new TaxLossService($this->db, new TaxConstantsRepository($this->db));
        $losses->registerOpeningLoss($this->supplierId, 'po', 2048, 100000);
        $draft = $this->returns->getReturn($this->supplierId, 2049, 'po', $this->userId);
        $draft = $this->returns->saveInputs($this->supplierId, 2049, 'po', ['loss_carryforward' => 200000], $draft['return']['row_version'], $this->userId, partial: true);
        self::assertSame(200000, (int) $draft['computed']['summary']['loss_applied']);
        try {
            $this->returns->finalize($this->supplierId, 2049, 'po', $draft['return']['row_version'], $this->userId);
            self::fail('Native return deducted 200000 while only 100000 could be allocated.');
        } catch (TaxReturnException $e) {
            self::assertSame('loss_allocation_insufficient', $e->errorCode);
        }
        self::assertSame('draft', (new TaxReturnRepository($this->db))->find($this->supplierId, 2049, 'po')['status']);
        foreach (['income_tax_return_snapshots', 'tax_loss_applications'] as $table) {
            $query = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id=?');
            $query->execute([$this->supplierId]);
            self::assertSame(0, (int) $query->fetchColumn());
        }
        self::assertSame(100000.0, $losses->card($this->supplierId, 2049, 'po')['available_total']);
    }
}
