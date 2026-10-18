<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;
use PDOException;

/**
 * Rozpozná MariaDB chyby, které samy vracejí zpět celou transakci.
 * Lock timeout 1205 sem nepatří, protože standardně vrací jen aktuální statement.
 */
final class TransactionFailure
{
    public static function aborted(\Throwable $failure): bool
    {
        if (!$failure instanceof PDOException) {
            return false;
        }

        return in_array((int) ($failure->errorInfo[1] ?? 0), [1020, 1213], true);
    }

    public static function rollbackIfAborted(PDO $pdo, \Throwable $failure): bool
    {
        if (!self::aborted($failure)) {
            return false;
        }
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
            }
        }

        return true;
    }
}
