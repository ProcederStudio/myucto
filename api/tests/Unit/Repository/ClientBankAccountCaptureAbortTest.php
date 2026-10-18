<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Service\Payment\IbanValidator;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zachycení účtu protistrany běží uvnitř transakce párování. Běžná chyba ho jen
 * přeskočí, odvolanou transakci (1020/1213) ale musí dostat vlastník transakce.
 */
final class ClientBankAccountCaptureAbortTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function captures(): iterable
    {
        yield 'vydaná faktura' => ['captureForInvoiceTransaction'];
        yield 'přijatá faktura' => ['captureForPurchaseInvoiceTransaction'];
    }

    #[DataProvider('captures')]
    public function testAbortedTransactionIsRethrown(string $method): void
    {
        $failure = new PDOException('Deadlock found when trying to get lock');
        $failure->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        $this->expectExceptionObject($failure);
        $this->repository($failure)->{$method}(1, 2);
    }

    #[DataProvider('captures')]
    public function testOrdinaryFailureStaysBestEffort(string $method): void
    {
        $failure = new PDOException('Unknown column');
        $failure->errorInfo = ['42S22', 1054, 'Unknown column'];

        $this->repository($failure)->{$method}(1, 2);
        $this->addToAssertionCount(1);
    }

    private function repository(PDOException $failure): ClientBankAccountRepository
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willThrowException($failure);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return new ClientBankAccountRepository($db, new IbanValidator());
    }
}
