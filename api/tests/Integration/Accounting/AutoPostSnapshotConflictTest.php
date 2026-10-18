<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ActivityLogHashChain;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hlava hash řetězu auditní stopy je jeden řádek pro celou instanci. Když ji mezi začátkem
 * transakce zaúčtování a zapečetěním záznamu `accounting.posted` posune souběžný požadavek,
 * MariaDB se `innodb_snapshot_isolation` odvolá celou transakci chybou 1020. Auto-post po
 * vystavení chybu spolkne, takže při hromadném importu přes API zůstávaly vystavené faktury
 * nezaúčtované. Zaúčtování proto odvolanou transakci zopakuje s novým snapshotem.
 */
#[Group('integration')]
final class AutoPostSnapshotConflictTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $dbA;
    private Connection $dbB;
    private JournalEntryRepository $journal;
    private DocumentAutoPoster $autoPoster;
    private int $supplier = 0;
    private int $user = 0;
    private int $currency = 0;
    private int $vat = 0;
    private int $country = 0;
    private int $interferences = 0;
    private int $originalSnapshotIsolation = 1;

    protected function setUp(): void
    {
        $a = Bootstrap::buildContainer();
        $b = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class);
            return $container;
        });
        $this->dbA = $a->get(Connection::class);
        $this->dbB = $b->get(Connection::class);
        $pdo = $this->dbA->pdo();
        self::assertNotSame(
            $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
            $this->dbB->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
        );
        if (!$this->dbA->hasColumn('activity_log_chain_head', 'last_hash')) {
            self::markTestSkipped('Chybí hlava hash řetězu (migrace 1171).');
        }

        $this->originalSnapshotIsolation = (int) $pdo->query('SELECT @@SESSION.innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');

        // Souběžný požadavek: zápis do auditní stopy z druhého spojení posune hlavu řetězu.
        $concurrent = $b->get(ActivityLogger::class);
        $interfere = function () use ($concurrent): void {
            if ($this->interferences > 0) {
                $this->interferences--;
                $concurrent->log('test.concurrent_request', null, null, null, null, null, null, $this->supplier);
            }
        };
        $a->set(ActivityLogger::class, new class ($this->dbA, $a->get(ActivityLogHashChain::class), $interfere) extends ActivityLogger {
            /** @var \Closure():void */
            private \Closure $beforePosted;

            public function __construct(Connection $db, ActivityLogHashChain $chain, \Closure $beforePosted)
            {
                parent::__construct($db, $chain);
                $this->beforePosted = $beforePosted;
            }

            public function log(
                string $action,
                ?int $userId = null,
                ?string $entityType = null,
                ?int $entityId = null,
                ?array $payload = null,
                ?string $ip = null,
                ?string $userAgent = null,
                ?int $supplierId = null,
            ): void {
                if ($action === 'accounting.posted') {
                    ($this->beforePosted)();
                }
                parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
            }
        });

        $this->journal = $a->get(JournalEntryRepository::class);
        $this->autoPoster = $a->get(DocumentAutoPoster::class);

        $this->country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $this->vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($this->country, $this->currency, $this->vat, $this->user));

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode, auto_post_invoices)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute(['Synthetic snapshot conflict', 'Test 1', 'Praha', '11000', $this->country, uniqid('snap-') . '@example.com', $this->currency, $this->vat, 'double_entry']);
        $this->supplier = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by) VALUES (?, 'document.invoice', 'auto', ?)"
        )->execute([$this->supplier, $this->user]);
        $a->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $a->get(AccountingPeriodRepository::class)->create($this->supplier, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbB, $this->dbA] as $db) {
            if ($db->pdo()->inTransaction()) {
                $db->pdo()->rollBack();
            }
        }
        $pdo = $this->dbA->pdo();
        if ($this->supplier !== 0) {
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
        $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $this->originalSnapshotIsolation);
        $this->dbB->close();
        $this->dbA->close();
    }

    public function testConcurrentAuditWriteDoesNotLeaveIssuedInvoiceUnposted(): void
    {
        $invoiceId = $this->issuedInvoice('SNAP-2099-1');
        $this->interferences = 1;

        $this->autoPoster->maybeAutoPost($this->supplier, 'invoice', $invoiceId, $this->user);

        self::assertSame(0, $this->interferences, 'Souběžný zápis do auditní stopy proběhl uprostřed zaúčtování.');
        self::assertNotNull(
            $this->journal->findBySource($this->supplier, 'invoice', $invoiceId),
            'Odvolaná transakce (1020) se zopakuje a faktura se zaúčtuje.',
        );
        self::assertSame(0, $this->auditCount('accounting.auto_post_failed', $invoiceId));
        self::assertSame(1, $this->auditCount('accounting.posted', null), 'V auditní stopě zůstal jen úspěšný pokus.');
        self::assertTrue($this->dbA->pdo()->query('SELECT 1')->fetchColumn() !== false);
    }

    /**
     * Zaúčtování hlavu řetězu vůbec nezamyká (pečetí se po commitu), takže souběžný
     * zápis do auditní stopy ho neodvolá ani napoprvé — opakování je jen pojistka.
     */
    public function testConcurrentAuditWriteDoesNotAbortPostingAtAll(): void
    {
        if (!$this->dbA->hasColumn('activity_log_chain_head', 'deferred_from_id')) {
            self::markTestSkipped('Migrace 2055 neproběhla.');
        }
        $invoiceId = $this->issuedInvoice('SNAP-2099-2');
        $this->interferences = 100;

        $this->autoPoster->maybeAutoPost($this->supplier, 'invoice', $invoiceId, $this->user);

        self::assertNotNull($this->journal->findBySource($this->supplier, 'invoice', $invoiceId));
        self::assertSame(99, $this->interferences, 'Zaúčtování proběhlo napoprvé, bez opakování.');
        self::assertSame(0, $this->auditCount('accounting.auto_post_failed', $invoiceId));
        $hash = $this->dbA->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = 'accounting.posted' AND hash IS NULL"
        );
        $hash->execute([$this->supplier]);
        self::assertSame(0, (int) $hash->fetchColumn(), 'Záznam zaúčtování je po commitu zapečetěný.');
    }

    private function issuedInvoice(string $varsymbol): int
    {
        $pdo = $this->dbA->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplier, $this->country, $this->currency]);
        $clientId = (int) $pdo->lastInsertId();

        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 1000, 210, 1210, "issued", "1", ?)'
        )->execute([$this->supplier, $varsymbol, $clientId, $issue, $issue, $issue, $this->currency, $this->user]);
        $invoiceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", 1000, ?, 21, 1000, 210, 1210, 0)'
        )->execute([$invoiceId, $this->vat]);

        return $invoiceId;
    }

    private function auditCount(string $action, ?int $invoiceId): int
    {
        $sql = 'SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ?';
        $params = [$this->supplier, $action];
        if ($invoiceId !== null) {
            $sql .= " AND entity_type = 'invoice' AND entity_id = ?";
            $params[] = $invoiceId;
        }
        $stmt = $this->dbA->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
