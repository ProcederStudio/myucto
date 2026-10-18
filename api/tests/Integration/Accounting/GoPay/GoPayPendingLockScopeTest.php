<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\GoPay;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\GoPay\GoPayPendingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Založení čekajícího GoPay pohybu k úhradě zamyká jen úhradu. Dřív `JOIN supplier …
 * FOR UPDATE` zamkl výlučně i řádek firmy, a tím ji celou serializoval: každý souběžný
 * zápis s cizím klíčem na firmu (vložení faktury, platby, …) bere na řádek firmy sdílený
 * zámek a GoPay úhrada na něj musela čekat, případně skončila deadlockem.
 */
#[Group('integration')]
final class GoPayPendingLockScopeTest extends TestCase
{
    private Connection $dbA;
    private Connection $dbB;
    private GoPayPendingService $pending;
    private int $supplierId = 0;
    private int $paymentId = 0;
    private int $lockWaitTimeout = 50;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 5) . '/cfg.php')) {
            $this->markTestSkipped('Test vyžaduje lokální databázi.');
        }
        $a = Bootstrap::buildContainer();
        $b = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class)->pdo();
            return $container;
        });
        $this->dbA = $a->get(Connection::class);
        $this->dbB = $b->get(Connection::class);
        $this->pending = $a->get(GoPayPendingService::class);
        $pdo = $this->dbA->pdo();
        self::assertNotSame(
            $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
            $this->dbB->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
        );
        $this->lockWaitTimeout = (int) $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $currency, $vat, $user));

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['Synthetic GoPay lock', 'Test 1', 'Praha', '11000', $country, uniqid('gopay-') . '@example.com', $currency, $vat, 'double_entry']);
        $this->supplierId = (int) $pdo->lastInsertId();
        $a->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $account = (int) $pdo->query('SELECT id FROM chart_of_accounts WHERE supplier_id = ' . $this->supplierId . ' ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $account);
        $pdo->prepare(
            'INSERT INTO gopay_settings
                (supplier_id, currency, gopay_account_id, receivable_account_id, fee_account_id, clearing_account_id, destination_bank_account_id)
             VALUES (?, "CZK", ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $account, $account, $account, $account, $account]);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $currency]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, "2099-06-15", "2099-06-15", "2099-06-29", ?, 0, 1000, 210, 1210, "issued", "1", ?)'
        )->execute([$this->supplierId, uniqid('GP-'), $client, $currency, $user]);
        $invoice = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, bank_reference, source)
             VALUES (?, ?, "2099-06-20", 1210, "CZK", "GOPAY:1000000003", "manual")'
        )->execute([$this->supplierId, $invoice]);
        $this->paymentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->dbA)) {
            return;
        }
        foreach ([$this->dbB, $this->dbA] as $db) {
            if ($db->pdo()->inTransaction()) {
                $db->pdo()->rollBack();
            }
        }
        $pdo = $this->dbA->pdo();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $this->lockWaitTimeout);
        if ($this->supplierId !== 0) {
            $pdo->prepare('DELETE FROM gopay_movements WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM gopay_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
        $this->dbB->close();
        $this->dbA->close();
    }

    public function testPendingMovementDoesNotWaitForSharedLockOnSupplier(): void
    {
        $b = $this->dbB->pdo();
        $b->beginTransaction();
        // Sdílený zámek firmy, jaký drží souběžná transakce po vložení libovolného
        // dokladu s cizím klíčem na firmu.
        $b->query('SELECT id FROM supplier WHERE id = ' . $this->supplierId . ' LOCK IN SHARE MODE')->fetchAll();

        $this->dbA->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $movementId = $this->pending->recordForPayment($this->paymentId, null);
        $b->rollBack();

        self::assertNotNull($movementId);
        $stmt = $this->dbA->pdo()->prepare(
            'SELECT COUNT(*) FROM gopay_movements WHERE supplier_id = ? AND invoice_payment_id = ? AND origin = "payment"'
        );
        $stmt->execute([$this->supplierId, $this->paymentId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    /** Úhrada firmy bez podvojného účetnictví pohyb nezakládá — kontrola nastavení zůstává. */
    public function testTaxEvidenceSupplierCreatesNoMovement(): void
    {
        $this->dbA->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);

        self::assertNull($this->pending->recordForPayment($this->paymentId, null));
        $stmt = $this->dbA->pdo()->prepare('SELECT COUNT(*) FROM gopay_movements WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }
}
