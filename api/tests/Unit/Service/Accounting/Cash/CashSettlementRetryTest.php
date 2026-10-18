<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting\Cash;

use MyInvoice\Repository\CashRegisterRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Tests\Support\AbortedTransactionTrait;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Hotovostní vyrovnání po uložení dokladu je měkká brána, ale odvolanou transakci
 * (1020/1213) nesmí spolknout do `cash.settlement_failed`: nic by ho nedohnalo.
 */
final class CashSettlementRetryTest extends TestCase
{
    use AbortedTransactionTrait;

    public function testOwnerRetriesAbortedSettlement(): void
    {
        [$db, $pdo] = $this->transactionalConnection();
        $purchases = $this->createMock(PurchaseInvoiceRepository::class);
        $purchases->expects(self::exactly(2))->method('find')
            ->willReturnCallback(self::abortingThen(static fn (): ?array => null));
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');

        $result = $this->service($db, $purchases, $activity)->maybeSettle(7, 'purchase_invoice', 11);

        self::assertSame(CashSettlementService::NOOP, $result['status']);
        self::assertFalse($pdo->inTransaction());
    }

    public function testAbortInsideForeignTransactionReachesItsOwner(): void
    {
        [$db, $pdo] = $this->transactionalConnection();
        $purchases = $this->createStub(PurchaseInvoiceRepository::class);
        $purchases->method('find')->willThrowException(self::abortedFailure(1020));
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');
        $pdo->beginTransaction();

        try {
            $this->service($db, $purchases, $activity)->maybeSettle(7, 'purchase_invoice', 11);
            self::fail('Odvolanou cizí transakci musí dostat její vlastník.');
        } catch (PDOException $e) {
            self::assertSame(1020, $e->errorInfo[1]);
        }
    }

    private function service(mixed $db, PurchaseInvoiceRepository $purchases, ActivityLogger $activity): CashSettlementService
    {
        return new CashSettlementService(
            $db,
            $this->createStub(CashDocumentService::class),
            $this->createStub(CashRegisterRepository::class),
            $this->createStub(InvoiceRepository::class),
            $purchases,
            $activity,
        );
    }
}
