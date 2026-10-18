<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPaymentMatchRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentSettlementSignalRepository;
use MyInvoice\Service\Accounting\Expense\PurchaseDiscountReclass;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\AbortedTransactionTrait;
use MyInvoice\Tests\Support\ScriptedFailurePdo;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Po odvolané transakci (1020/1213) savepoint neexistuje. Helper, který zavolá
 * `ROLLBACK TO SAVEPOINT`, skončí chybou 1305 a volající místo deadlocku uvidí
 * „SAVEPOINT does not exist". Helpery proto nejdřív zavolají
 * TransactionFailure::rollbackIfAborted a vyhodí původní chybu.
 */
final class SavepointAbortMaskingTest extends TestCase
{
    use AbortedTransactionTrait;

    public function testLicenseCapacityGateKeepsOriginalFailure(): void
    {
        $pdo = new ScriptedFailurePdo();
        $gate = self::serviceWith(LicenseCapacityGate::class, ['db' => $this->connection($pdo)]);
        $pdo->beginTransaction();

        $this->assertOriginalFailure($pdo, fn () => (new \ReflectionMethod($gate, 'transactional'))->invoke(
            $gate,
            static fn () => throw $pdo->abortWith(self::abortedFailure(1213)),
        ));
    }

    public function testPayrollPaymentMatchTransactionKeepsOriginalFailure(): void
    {
        $pdo = new ScriptedFailurePdo();
        $repository = self::serviceWith(PayrollPaymentMatchRepository::class, ['db' => $this->connection($pdo)]);
        $pdo->beginTransaction();

        $this->assertOriginalFailure($pdo, fn () => $repository->transaction(
            static fn () => throw $pdo->abortWith(self::abortedFailure(1020)),
        ));
    }

    /** @return iterable<string,array{string,string}> */
    public static function payrollRunHelpers(): iterable
    {
        yield 'příkaz běhu' => ['rollbackCommandTransaction', 'payroll_run_command'];
        yield 'smazání běhu' => ['rollbackDeleteTransaction', 'payroll_run_delete'];
    }

    #[DataProvider('payrollRunHelpers')]
    public function testPayrollRunRollbackHelperDoesNotTouchMissingSavepoint(string $helper, string $savepoint): void
    {
        $pdo = new ScriptedFailurePdo();
        $service = self::serviceWith(PayrollRunCommandService::class, ['db' => $this->connection($pdo)]);
        $pdo->beginTransaction();
        $pdo->exec('SAVEPOINT ' . $savepoint);
        $failure = $pdo->abortWith(self::abortedFailure(1213));

        $this->assertOriginalFailure($pdo, function () use ($service, $helper, $pdo, $failure): never {
            (new \ReflectionMethod($service, $helper))->invoke($service, $pdo, true, $failure);
            throw $failure;
        });
    }

    public function testSettlementSignalDoesNotSwallowAbortInsideMatchTransaction(): void
    {
        $signals = $this->createStub(PayrollPaymentSettlementSignalRepository::class);
        $signals->method('resolveForLiability')->willThrowException(self::abortedFailure(1213));
        $service = self::serviceWith(PayrollPaymentReconciliationService::class, ['signals' => $signals]);

        try {
            (new \ReflectionMethod($service, 'resolveSignals'))->invoke($service, 3, 5, 7);
            self::fail('Odvolanou transakci spárování nelze spolknout jako chybějící tabulku.');
        } catch (PDOException $e) {
            self::assertSame(1213, $e->errorInfo[1]);
        }
    }

    public function testPurchaseDiscountReclassKeepsOriginalFailure(): void
    {
        $pdo = new ScriptedFailurePdo();
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, parent_purchase_invoice_id INTEGER, document_kind TEXT, status TEXT)');
        $pdo->exec('CREATE TABLE purchase_invoice_items (id INTEGER PRIMARY KEY, purchase_invoice_id INTEGER, description TEXT, total_without_vat REAL,
            vat_rate_snapshot REAL, expense_kind TEXT, expense_account_code TEXT, order_index INTEGER)');
        $pdo->exec("INSERT INTO purchase_invoices VALUES (9, 3, NULL, 'invoice', 'booked')");
        $pdo->exec("INSERT INTO purchase_invoice_items VALUES (1, 9, 'Syntetické zboží', 1000, 21, NULL, NULL, 0)");
        $pdo->exec("INSERT INTO purchase_invoice_items VALUES (2, 9, 'Sleva', -100, 21, NULL, NULL, 1)");
        $pdo->failOn('FROM accounting_periods', self::abortedFailure(1213));
        $reclass = self::serviceWith(PurchaseDiscountReclass::class, ['db' => $this->connection($pdo)]);
        $pdo->beginTransaction();

        $this->assertOriginalFailure($pdo, fn () => $reclass->run(3, 9, true));
        self::assertSame(1, $pdo->calls['FROM accounting_periods']);
    }

    private function assertOriginalFailure(ScriptedFailurePdo $pdo, \Closure $call): void
    {
        try {
            $call();
            self::fail('Odvolaná transakce se musí dostat k volajícímu.');
        } catch (PDOException $e) {
            self::assertContains($e->errorInfo[1] ?? null, [1020, 1213], 'Původní chybu přemaskoval: ' . $e->getMessage());
        }
        self::assertFalse($pdo->inTransaction(), 'Odvolaná transakce se má ukončit.');
    }

    private function connection(ScriptedFailurePdo $pdo): Connection
    {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return $db;
    }
}
