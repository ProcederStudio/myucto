<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\GoPay;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

/**
 * Vlastní transakce, nebo savepoint uvnitř transakce volajícího. GoPay účtování
 * běží samostatně (import, zpracování) i uvnitř evidence úhrady faktury.
 */
trait GoPayUnitOfWork
{
    private function beginUnit(PDO $pdo, string $savepoint): bool
    {
        if ($pdo->inTransaction()) {
            $pdo->exec('SAVEPOINT ' . $savepoint);
            return false;
        }
        $pdo->beginTransaction();
        return true;
    }

    private function commitUnit(PDO $pdo, bool $ownTx, string $savepoint): void
    {
        if ($ownTx) {
            $pdo->commit();
            return;
        }
        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollbackUnit(PDO $pdo, bool $ownTx, string $savepoint, \Throwable $cause): void
    {
        // Odvolaná transakce už savepoint nemá; ROLLBACK TO by původní chybu přemaskoval.
        if (TransactionFailure::rollbackIfAborted($pdo, $cause)) {
            return;
        }
        if ($ownTx) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
    }
}
