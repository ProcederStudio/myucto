<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting\GoPay;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\GoPay\GoPayMovementPoster;
use MyInvoice\Tests\Support\AbortedTransactionTrait;
use MyInvoice\Tests\Support\ScriptedFailurePdo;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Zaúčtování GoPay pohybu chybu zapisuje do stavu pohybu. Odvolaná transakce
 * (1020/1213) ale není chyba pohybu: vlastník ji zopakuje, v cizí transakci ji
 * dostane její vlastník a ROLLBACK TO SAVEPOINT ji nepřemaskuje.
 */
final class GoPayMovementPosterRetryTest extends TestCase
{
    use AbortedTransactionTrait;

    private const LOAD = 'FROM gopay_movements gm';

    public function testOwnerRetriesAbortedPosting(): void
    {
        $pdo = $this->pdo();
        $ordinary = new PDOException('synthetic ordinary failure');
        $ordinary->errorInfo = ['42S02', 1146, 'synthetic ordinary failure'];
        $pdo->failOn(self::LOAD, self::abortedFailure(1213), $ordinary);

        $this->poster($pdo)->post(3, 1, null);

        self::assertSame(2, $pdo->calls[self::LOAD], 'Odvolaná transakce se zopakuje.');
        self::assertSame(['error', 'synthetic ordinary failure'], $this->state($pdo));
        self::assertFalse($pdo->inTransaction());
    }

    public function testAbortInsideForeignTransactionIsNotMaskedBySavepoint(): void
    {
        $pdo = $this->pdo();
        $pdo->failOn(self::LOAD, self::abortedFailure(1020));
        $pdo->beginTransaction();

        try {
            $this->poster($pdo)->post(3, 1, null);
            self::fail('Odvolanou cizí transakci musí dostat její vlastník.');
        } catch (PDOException $e) {
            self::assertSame(1020, $e->errorInfo[1]);
        }
        self::assertSame(1, $pdo->calls[self::LOAD]);
        self::assertSame(['pending', null], $this->state($pdo), 'Stav se nezapisuje v autocommitu po odvolání.');
    }

    private function pdo(): ScriptedFailurePdo
    {
        $pdo = new ScriptedFailurePdo();
        $pdo->exec('CREATE TABLE gopay_movements (id INTEGER PRIMARY KEY, supplier_id INTEGER, status TEXT,
            issue_code TEXT, issue_message TEXT, processed_at TEXT)');
        $pdo->exec("INSERT INTO gopay_movements (id, supplier_id, status) VALUES (1, 3, 'pending')");

        return $pdo;
    }

    private function poster(ScriptedFailurePdo $pdo): GoPayMovementPoster
    {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        return self::serviceWith(GoPayMovementPoster::class, ['db' => $db]);
    }

    /** @return array{0:string,1:?string} */
    private function state(ScriptedFailurePdo $pdo): array
    {
        $row = $pdo->query('SELECT status, issue_message FROM gopay_movements WHERE id = 1')->fetch(\PDO::FETCH_NUM);

        return [(string) $row[0], $row[1] === null ? null : (string) $row[1]];
    }
}
