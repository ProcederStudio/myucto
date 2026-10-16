<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use PHPUnit\Framework\TestCase;

final class AccountingFrameworkServiceTest extends TestCase
{
    public function testMissingHistoryFallsBackToCz500AndKeepsTenantScope(): void
    {
        $repository = $this->createMock(AccountingFrameworkRepository::class);
        $repository->expects(self::once())
            ->method('forDate')
            ->with(37, '2026-12-31')
            ->willReturn(null);

        $service = new AccountingFrameworkService(
            $this->createStub(Connection::class),
            $repository,
        );

        self::assertSame(AccountingFrameworkService::CZ_500, $service->forDate(37, '2026-12-31'));
    }

    public function testHistoricalCz504RowRemainsReadable(): void
    {
        $repository = $this->createStub(AccountingFrameworkRepository::class);
        $repository->method('forDate')->willReturn(AccountingFrameworkService::CZ_504);
        $service = new AccountingFrameworkService($this->createStub(Connection::class), $repository);

        self::assertSame(AccountingFrameworkService::CZ_504, $service->forDate(9, '2030-01-01'));
    }

    public function testCz504ActivationIsRejectedBeforePersistence(): void
    {
        $repository = $this->createMock(AccountingFrameworkRepository::class);
        $repository->expects(self::never())->method('periodsOverlappingForUpdate');
        $service = new AccountingFrameworkService($this->createStub(Connection::class), $repository);

        try {
            $service->changeFromPeriodStart(9, '2030-01-01', AccountingFrameworkService::CZ_504);
            self::fail('Aktivace cz_504 měla být odmítnuta.');
        } catch (AccountingFrameworkException $e) {
            self::assertSame('accounting_framework_not_available', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $service = new AccountingFrameworkService(
            $this->createStub(Connection::class),
            $this->createStub(AccountingFrameworkRepository::class),
        );

        try {
            $service->forDate(9, '2026-02-30');
            self::fail('Neplatné datum mělo být odmítnuto.');
        } catch (AccountingFrameworkException $e) {
            self::assertSame('invalid_accounting_framework_date', $e->errorCode);
        }
    }
}
