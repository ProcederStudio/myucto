<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Souběžné úhrady téže faktury (hromadné zadávání přes API ze dvou proudů).
 *
 * INSERT do `invoice_payments` bere přes cizí klíč sdílený zámek na řádek faktury
 * a přepočet `paid_total` pak potřebuje výlučný. Dvě úhrady, které obě stihly vložit
 * platbu, na sebe čekaly a skončily deadlockem 1213. Úhrada proto fakturu zamkne
 * hned na začátku (souběh čeká ve frontě, nic nedrží) a odvolanou transakci zopakuje.
 *
 * Souběh se tu skládá deterministicky: druhé spojení pošle vícepříkazový dotaz, který
 * server zpracovává dál, zatímco test pokračuje (výsledky se dočtou až nakonec).
 */
#[Group('integration')]
final class PaymentConcurrencyTest extends TestCase
{
    private Connection $dbA;
    private Connection $dbB;
    private Connection $dbC;
    private InvoicePaymentService $payments;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $lockWaitTimeout = 50;
    private int $threadA = 0;
    private int $threadB = 0;
    /** @var list<PDOStatement> */
    private array $pending = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $a = Bootstrap::buildContainer();
        [$b, $c] = Connection::withoutSharedTestConnection(static function () {
            $containers = [Bootstrap::buildContainer(), Bootstrap::buildContainer()];
            foreach ($containers as $container) {
                $container->get(Connection::class)->pdo();
            }
            return $containers;
        });
        $this->dbA = $a->get(Connection::class);
        $this->dbB = $b->get(Connection::class);
        $this->dbC = $c->get(Connection::class);
        $this->payments = $a->get(InvoicePaymentService::class);

        $pdo = $this->dbA->pdo();
        $ids = array_map(
            static fn (Connection $db): string => (string) $db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
            [$this->dbA, $this->dbB, $this->dbC],
        );
        self::assertCount(3, array_unique($ids));
        [$this->threadA, $this->threadB] = [(int) $ids[0], (int) $ids[1]];
        $this->lockWaitTimeout = (int) $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $this->currencyId, $vat, $this->userId));

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['Synthetic payment race', 'Test 1', 'Praha', '11000', $country, uniqid('race-') . '@example.com', $this->currencyId, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->dbA)) {
            return;
        }
        $this->drainPending();
        foreach ([$this->dbC, $this->dbB, $this->dbA] as $db) {
            if ($db->pdo()->inTransaction()) {
                $db->pdo()->rollBack();
            }
        }
        $pdo = $this->dbA->pdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $this->lockWaitTimeout);
        if ($this->supplierId !== 0) {
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
        $this->dbC->close();
        $this->dbB->close();
        $this->dbA->close();
    }

    /**
     * Úhrada čeká na fakturu dřív, než cokoli zapíše. Kdyby nejdřív vložila platbu, držela
     * by sdílený zámek faktury a s druhou úhradou, která dělá totéž, by se zablokovala.
     */
    public function testPaymentWaitsForInvoiceBeforeWritingAnything(): void
    {
        $invoiceId = $this->issuedInvoice();
        $b = $this->dbB->pdo();
        $b->beginTransaction();
        // Sdílený zámek faktury, jaký drží souběžná transakce po vložení platby.
        $b->query('SELECT id FROM invoices WHERE id = ' . $invoiceId . ' LOCK IN SHARE MODE')->fetchAll();

        $probe = $this->background($this->dbC,
            'DO 0; DO SLEEP(1); SELECT trx_rows_modified FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ' . $this->threadA);

        $this->dbA->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 3');
        $failure = null;
        try {
            $this->payments->recordPayment($invoiceId, 100, '2099-06-20', ['source' => 'manual']);
        } catch (PDOException $e) {
            $failure = $e;
        }
        self::assertNotNull($failure, 'Úhrada měla čekat na zámek faktury.');
        self::assertSame(1205, (int) $failure->errorInfo[1]);

        $probe->nextRowset();
        $probe->nextRowset();
        $modified = $probe->fetchColumn();
        self::assertNull($this->drainPending());
        $b->rollBack();

        self::assertNotFalse($modified, 'Úhrada během čekání neměla otevřenou transakci.');
        self::assertSame(0, (int) $modified, 'Úhrada vložila platbu dřív, než získala zámek faktury.');
        self::assertSame(0, $this->paymentCount($invoiceId));
    }

    /**
     * Souběžná úhrada, která už vložila platbu a pak přepočítává fakturu. Úhrada
     * v tomhle okamžiku nesmí skončit chybou: buď počká, nebo odvolanou transakci zopakuje.
     */
    public function testConcurrentPaymentOfSameInvoiceDoesNotFail(): void
    {
        $invoiceId = $this->issuedInvoice();

        // Souběžná transakce je „těžší" (víc zapsaných řádků), takže při deadlocku
        // MariaDB odvolá úhradu z testu, ne ji.
        $ballast = implode(',', array_fill(0, 50, "({$this->supplierId}, 'test.payment_race_ballast')"));
        $this->background($this->dbB, implode('; ', [
            'START TRANSACTION',
            "INSERT INTO activity_log (supplier_id, action) VALUES {$ballast}",
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
                  VALUES ({$this->supplierId}, {$invoiceId}, '2099-06-20', 100.00, 'CZK', 'manual')",
            'DO SLEEP(2)',
            "UPDATE invoices SET paid_total = (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = {$invoiceId})
              WHERE id = {$invoiceId}",
            'COMMIT',
        ]));
        $this->waitUntilSleeping($this->threadB);

        $result = $this->payments->recordPayment($invoiceId, 1110, '2099-06-20', ['source' => 'manual']);

        $concurrentFailure = $this->drainPending();
        self::assertNull($concurrentFailure, 'Souběžná transakce skončila chybou: ' . ($concurrentFailure?->getMessage() ?? ''));
        self::assertSame(1, $this->paymentCount($invoiceId, 1110.0));
        self::assertSame(1, $this->paymentCount($invoiceId, 100.0));
        $row = $this->dbA->pdo()->query('SELECT status, paid_total FROM invoices WHERE id = ' . $invoiceId)->fetch(PDO::FETCH_ASSOC);
        self::assertEqualsWithDelta(1210.0, (float) $row['paid_total'], 0.001);
        self::assertSame('paid', $row['status']);
        self::assertTrue($result['became_paid']);
    }

    private function background(Connection $db, string $sql): PDOStatement
    {
        $pdo = $db->pdo();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $stmt = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $stmt);
        $this->pending[] = $stmt;

        return $stmt;
    }

    private function drainPending(): ?PDOException
    {
        $failure = null;
        foreach ($this->pending as $stmt) {
            try {
                while ($stmt->nextRowset()) {
                }
            } catch (PDOException $e) {
                $failure = $e;
            }
        }
        $this->pending = [];

        return $failure;
    }

    /** Počká, až souběžné spojení dojde k `DO SLEEP` (všechno před ním už provedlo). */
    private function waitUntilSleeping(int $thread): void
    {
        $probe = $this->dbA->pdo()->prepare('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID = ?');
        $deadline = microtime(true) + 5;
        do {
            $probe->execute([$thread]);
            if ($probe->fetchColumn() === 'User sleep') {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail('Souběžná transakce nedošla k čekání: ' . ($this->drainPending()?->getMessage() ?? 'bez chyby'));
    }

    private function issuedInvoice(): int
    {
        $pdo = $this->dbA->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, "2099-06-15", "2099-06-15", "2099-06-29", ?, 0, 1000, 210, 1210, "issued", "1", ?)'
        )->execute([$this->supplierId, uniqid('RACE-'), $this->clientId, $this->currencyId, $this->userId]);

        return (int) $pdo->lastInsertId();
    }

    private function paymentCount(int $invoiceId, ?float $amount = null): int
    {
        $sql = 'SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?';
        $params = [$invoiceId];
        if ($amount !== null) {
            $sql .= ' AND amount = ?';
            $params[] = $amount;
        }
        $stmt = $this->dbA->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
