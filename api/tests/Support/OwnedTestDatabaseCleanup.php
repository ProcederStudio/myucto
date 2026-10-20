<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;
use PDOException;

final class OwnedTestDatabaseCleanup
{
    public static function drop(PDO $server, string $schema, array $ownedSchemas): void
    {
        if (!in_array($schema, $ownedSchemas, true)
            || preg_match('/\Amyucto_[a-z0-9_]+_test\z/D', $schema) !== 1) {
            throw new \LogicException('Cleanup requires an explicitly owned test database.');
        }
        // Sdílené testovací spojení na vlastní databázi může držet otevřenou transakci
        // (metadata lock) a DROP by na ni čekal donekonečna. Databáze patří jen testu,
        // takže se její zbylá spojení ukončí dřív, než se smaže.
        $sessions = $server->prepare('SELECT ID FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID()');
        $sessions->execute([$schema]);
        foreach ($sessions->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $server->exec('KILL ' . (int) $id);
            } catch (PDOException) {
                // Spojení mezitím skončilo samo.
            }
        }
        for ($attempt = 0; ; ++$attempt) {
            try {
                $server->exec('DROP DATABASE IF EXISTS ' . DatabaseCloner::quote($schema));
                return;
            } catch (PDOException $error) {
                if ($attempt >= 4 || (int) ($error->errorInfo[1] ?? 0) !== 1010
                    || stripos($error->getMessage(), 'Directory not empty') === false) {
                    throw $error;
                }
                usleep(($attempt + 1) * 50_000);
            }
        }
    }
}
