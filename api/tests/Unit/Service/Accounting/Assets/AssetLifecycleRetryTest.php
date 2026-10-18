<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting\Assets;

use MyInvoice\Repository\AssetRepository;
use MyInvoice\Service\Accounting\AssetSale\InvoiceAssetSaleService;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Tests\Support\AbortedTransactionTrait;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vyřazení, jeho vrácení a zařazení majetku si řídí vlastní transakci. Prodej z vydané
 * faktury chybu spolkne do `asset_sale.auto_failed`, takže odvolanou transakci (1020/1213)
 * musí zopakovat vlastník, jinak karta zůstane v užívání u prodaného majetku.
 */
final class AssetLifecycleRetryTest extends TestCase
{
    use AbortedTransactionTrait;

    /** @return iterable<string,array{\Closure(AssetService):mixed}> */
    public static function owners(): iterable
    {
        yield 'vyřazení' => [static fn (AssetService $s): mixed => $s->dispose(3, 5, ['date' => '2099-06-30', 'type' => 'sold'], [])];
        yield 'vrácení vyřazení' => [static fn (AssetService $s): mixed => $s->revertDisposal(3, 5)];
        yield 'zařazení' => [static fn (AssetService $s): mixed => $s->putIntoUse(3, 5, '2099-06-30', true, [])];
    }

    #[DataProvider('owners')]
    public function testOwnerRetriesAbortedTransaction(\Closure $call): void
    {
        [$db, $pdo] = $this->transactionalConnection();
        $assets = $this->createMock(AssetRepository::class);
        $assets->expects(self::exactly(2))->method('find')
            ->willReturnCallback(self::abortingThen(static fn (): ?array => null));
        $service = self::serviceWith(AssetService::class, ['assets' => $assets, 'db' => $db]);

        try {
            $call($service);
            self::fail('Karta neexistuje, opakovaný pokus ji nesmí najít.');
        } catch (AssetException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
        self::assertFalse($pdo->inTransaction());
    }

    #[DataProvider('owners')]
    public function testAbortInsideForeignTransactionIsNotRetried(\Closure $call): void
    {
        [$db, $pdo] = $this->transactionalConnection();
        $assets = $this->createMock(AssetRepository::class);
        $assets->expects(self::once())->method('find')->willThrowException(self::abortedFailure());
        $service = self::serviceWith(AssetService::class, ['assets' => $assets, 'db' => $db]);
        $pdo->beginTransaction();

        $this->expectException(PDOException::class);
        $call($service);
    }

    public function testSaleFromInvoiceRethrowsAbortInsideForeignTransaction(): void
    {
        [$db, $pdo] = $this->transactionalConnection();
        $pdo->exec("CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT)");
        $pdo->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, invoice_type TEXT, tax_date TEXT, issue_date TEXT, exchange_rate REAL, currency_id INTEGER)");
        $pdo->exec("CREATE TABLE invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, small_asset_id INTEGER, asset_id INTEGER, total_without_vat REAL, order_index INTEGER)");
        $pdo->exec("CREATE TABLE assets (id INTEGER PRIMARY KEY, supplier_id INTEGER, status TEXT, sale_invoice_id INTEGER, name TEXT)");
        $pdo->exec("CREATE TABLE supplier (id INTEGER PRIMARY KEY, accounting_mode TEXT)");
        $pdo->exec("INSERT INTO supplier VALUES (3, 'double_entry')");
        $pdo->exec("INSERT INTO invoices VALUES (9, 3, 'invoice', '2099-06-30', '2099-06-30', NULL, NULL)");
        $pdo->exec("INSERT INTO invoice_items VALUES (1, 9, NULL, 5, 1000, 0)");
        $pdo->exec("INSERT INTO assets VALUES (5, 3, 'in_use', NULL, 'Synthetic asset')");

        $assets = $this->createStub(AssetRepository::class);
        $assets->method('find')->willThrowException(self::abortedFailure());
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::never())->method('log');
        $sale = self::serviceWith(InvoiceAssetSaleService::class, [
            'db' => $db,
            'assets' => self::serviceWith(AssetService::class, ['assets' => $assets, 'db' => $db]),
            'activity' => $activity,
        ]);
        $pdo->beginTransaction();

        $this->expectException(PDOException::class);
        $sale->applyForIssuedInvoice(3, 9);
    }
}
