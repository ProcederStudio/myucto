<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stats;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přepočet cache klientů a projektů souběžně s jiným přepočtem.
 *
 * Dřív se cache přepisovala `DELETE … WHERE client_id = ?` a novým INSERTem. Pod
 * REPEATABLE READ bere takový DELETE zámky i na mezery mezi klíči, takže dvě souběžně
 * vystavené faktury (týž klient, nebo sousední klíče) skončily deadlockem 1213 na
 * `client_revenue_cache`. Přepočet teď zapisuje jen konkrétní klíče a nečeká na řádek,
 * který souběžná transakce teprve zakládá.
 */
#[Group('integration')]
final class StatsRecomputerConcurrencyTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TAX_DATE = '2093-06-10';

    private Connection $dbA;
    private Connection $dbB;
    private StatsRecomputer $stats;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $eurId = 0;
    private int $clientId = 0;
    private int $projectId = 0;
    private int $lockWaitTimeout = 50;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $a = Bootstrap::buildContainer();
        $b = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class);
            return $container;
        });
        $this->dbA = $a->get(Connection::class);
        $this->dbB = $b->get(Connection::class);
        $this->stats = $a->get(StatsRecomputer::class);

        $pdo = $this->dbA->pdo();
        self::assertNotSame(
            $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
            $this->dbB->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(),
        );
        $this->lockWaitTimeout = (int) $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();

        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->czkId = $this->currency('CZK', 'Kč');
        $this->eurId = $this->currency('EUR', '€');
        $this->clientId = $this->client();
        $pdo->prepare(
            'INSERT INTO projects (client_id, name, status, payment_due_days, hourly_rate, currency_id)
             VALUES (?, "Zakázka", "active", 14, 0, ?)'
        )->execute([$this->clientId, $this->czkId]);
        $this->projectId = (int) $pdo->lastInsertId();
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
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM project_revenue_cache WHERE project_id = ?')->execute([$this->projectId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM projects WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
        $this->dbB->close();
        $this->dbA->close();
    }

    /**
     * Souběžná transakce zakládá řádek cache téhož klienta v jiné měně. Přepočet na ni
     * nesmí čekat: dva takové přepočty proti sobě by jinak skončily deadlockem.
     */
    public function testClientRecomputeDoesNotWaitForConcurrentlyInsertedRow(): void
    {
        $this->insertIssuedInvoice(1000, 1210);
        $b = $this->dbB->pdo();
        $b->beginTransaction();
        $b->prepare(
            'INSERT INTO client_revenue_cache (client_id, currency_id, revenue, last_invoice_date, invoice_count)
             VALUES (?, ?, 1, NULL, 1)'
        )->execute([$this->clientId, $this->eurId]);

        $this->dbA->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->stats->recomputeClient($this->clientId);
        $b->rollBack();

        $rows = $this->clientRows();
        self::assertCount(1, $rows);
        self::assertSame($this->czkId, (int) $rows[0]['currency_id']);
        self::assertSame(1, (int) $rows[0]['invoice_count']);
    }

    public function testProjectRecomputeDoesNotWaitForConcurrentlyInsertedRow(): void
    {
        $this->insertIssuedInvoice(1000, 1210);
        $b = $this->dbB->pdo();
        $b->beginTransaction();
        $b->prepare(
            'INSERT INTO project_revenue_cache (project_id, currency_id, revenue, last_invoice_date, invoice_count)
             VALUES (?, ?, 1, NULL, 1)'
        )->execute([$this->projectId, $this->eurId]);

        $this->dbA->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->stats->recomputeProject($this->projectId);
        $b->rollBack();

        $rows = $this->projectRows();
        self::assertCount(1, $rows);
        self::assertSame($this->czkId, (int) $rows[0]['currency_id']);
        self::assertSame(1, (int) $rows[0]['invoice_count']);
    }

    /**
     * Výsledek zůstává stejný jako dřív: hodnoty se přepíší a řádek měny, ve které klient
     * ani projekt už žádný doklad nemá, z cache zmizí.
     */
    public function testRecomputeOverwritesValuesAndDropsStaleCurrency(): void
    {
        $pdo = $this->dbA->pdo();
        foreach ([[$this->czkId, 1.0, 9], [$this->eurId, 999.0, 5]] as [$currency, $revenue, $count]) {
            $pdo->prepare(
                'INSERT INTO client_revenue_cache (client_id, currency_id, revenue, last_invoice_date, invoice_count)
                 VALUES (?, ?, ?, "2000-01-01", ?)'
            )->execute([$this->clientId, $currency, $revenue, $count]);
            $pdo->prepare(
                'INSERT INTO project_revenue_cache (project_id, currency_id, revenue, last_invoice_date, invoice_count)
                 VALUES (?, ?, ?, "2000-01-01", ?)'
            )->execute([$this->projectId, $currency, $revenue, $count]);
        }
        $this->insertIssuedInvoice(1000, 1210);
        $this->insertIssuedInvoice(500, 605);

        $this->stats->recomputeClient($this->clientId);
        $this->stats->recomputeProject($this->projectId);

        foreach ([$this->clientRows(), $this->projectRows()] as $rows) {
            self::assertCount(1, $rows, 'Řádek EUR bez dokladu musí zmizet.');
            self::assertSame($this->czkId, (int) $rows[0]['currency_id']);
            self::assertSame(2, (int) $rows[0]['invoice_count']);
            self::assertEqualsWithDelta(1500.0, (float) $rows[0]['revenue'], 0.01);
            self::assertSame(self::TAX_DATE, $rows[0]['last_invoice_date']);
        }

        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->stats->recomputeClient($this->clientId);
        $this->stats->recomputeProject($this->projectId);
        self::assertSame([], $this->clientRows(), 'Klient bez dokladu nemá v cache žádný řádek.');
        self::assertSame([], $this->projectRows());
    }

    // ── pomůcky ──────────────────────────────────────────────────────────────

    private function insertIssuedInvoice(float $net, float $gross): void
    {
        static $seq = 0;
        $seq++;
        $this->dbA->pdo()->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, project_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?)"
        )->execute([
            'LOCK-' . self::TAX_DATE . '-' . $seq, $this->clientId, $this->projectId, $this->supplierId,
            self::TAX_DATE, self::TAX_DATE, self::TAX_DATE, $this->czkId, $net, $gross, $this->userId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function clientRows(): array
    {
        $stmt = $this->dbA->pdo()->prepare('SELECT * FROM client_revenue_cache WHERE client_id = ? ORDER BY currency_id');
        $stmt->execute([$this->clientId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function projectRows(): array
    {
        $stmt = $this->dbA->pdo()->prepare('SELECT * FROM project_revenue_cache WHERE project_id = ? ORDER BY currency_id');
        $stmt->execute([$this->projectId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function currency(string $code, string $symbol): int
    {
        $pdo = $this->dbA->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, ?)'
        )->execute([$this->supplierId, $code, $code, $symbol, $code, $code, $code === 'CZK' ? 1 : 0]);

        return (int) $pdo->lastInsertId();
    }

    private function client(): int
    {
        $pdo = $this->dbA->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Souběh s.r.o.", "25596641", "Testovací 1", "Praha", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }
}
