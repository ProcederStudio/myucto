<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentSeriesRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Řada pokladních dokladů je jeden řádek pro firmu × řadu × rok. Pod REPEATABLE READ
 * s `innodb_snapshot_isolation` skončí jeho zámek chybou 1020, když ho mezi začátkem
 * transakce a výdejem čísla posune souběžný doklad. Vlastník transakce (založení
 * a zaúčtování pokladního dokladu) ji proto zopakuje s novým snapshotem; číslo z
 * odvolaného pokusu se vrátilo s rollbackem, takže řada zůstává souvislá.
 */
#[Group('integration')]
final class CashDocumentSeriesConflictTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $dbA;
    private Connection $dbB;
    private CashDocumentService $documents;
    private int $supplier = 0;
    private int $register = 0;
    private int $user = 0;
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
        $this->originalSnapshotIsolation = (int) $pdo->query('SELECT @@SESSION.innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');

        // Souběžný doklad: výdej čísla téže řady z druhého spojení, commitnutý.
        $concurrent = $b->get(DocumentSeriesService::class);
        $issueConcurrently = function () use ($concurrent): void {
            $pdoB = $this->dbB->pdo();
            $pdoB->beginTransaction();
            $concurrent->next($this->supplier, 'cash_out', self::YEAR);
            $pdoB->commit();
        };
        $a->set(DocumentSeriesRepository::class, new class ($this->dbA, function () use ($issueConcurrently): void {
            if ($this->interferences > 0) {
                $this->interferences--;
                $issueConcurrently();
            }
        }) extends DocumentSeriesRepository {
            /** @var \Closure():void */
            private \Closure $beforeEnsure;

            public function __construct(Connection $db, \Closure $beforeEnsure)
            {
                parent::__construct($db);
                $this->beforeEnsure = $beforeEnsure;
            }

            public function ensure(int $supplierId, string $seriesCode, int $fiscalYear, string $defaultPrefix, int $registerId = 0, ?string $numberFormat = null): void
            {
                ($this->beforeEnsure)();
                parent::ensure($supplierId, $seriesCode, $fiscalYear, $defaultPrefix, $registerId, $numberFormat);
            }
        });
        $this->documents = $a->get(CashDocumentService::class);

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $currency, $vat, $this->user));

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['Synthetic cash series conflict', 'Test 1', 'Praha', '11000', $country, uniqid('cashser-') . '@example.com', $currency, $vat, 'tax_evidence']);
        $this->supplier = (int) $pdo->lastInsertId();
        $this->register = $a->get(CashRegisterService::class)->create($this->supplier, [
            'name' => 'Pokladna', 'account_code' => '211', 'is_default' => true,
        ]);
        // Řádek řady už existuje a první číslo je vydané.
        $issueConcurrently();
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
            foreach (['activity_log', 'cash_documents', 'accounting_document_series', 'cash_registers'] as $table) {
                $pdo->prepare("DELETE FROM {$table} WHERE supplier_id = ?")->execute([$this->supplier]);
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
        $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $this->originalSnapshotIsolation);
        $this->dbB->close();
        $this->dbA->close();
    }

    public function testConcurrentIssueOfSameSeriesIsRetriedNotLost(): void
    {
        $this->interferences = 1;

        $result = $this->documents->create($this->supplier, [
            'register_id'  => $this->register,
            'issue_date'   => self::YEAR . '-06-15',
            'description'  => 'Syntetický výdaj',
            'purpose'      => 'purchase',
            'doc_type'     => 'out',
            'total_amount' => 100.00,
            'post'         => true,
        ], $this->user);

        self::assertSame(0, $this->interferences, 'Souběžný výdej proběhl uprostřed zaúčtování dokladu.');
        self::assertSame('posted', $result['status']);
        self::assertSame('VPD-' . self::YEAR . '-0003', $result['doc_number'], 'Řada zůstává souvislá: 1 a 2 souběžně, 3 tento doklad.');
        $count = $this->dbA->pdo()->prepare('SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ?');
        $count->execute([$this->supplier]);
        self::assertSame(1, (int) $count->fetchColumn(), 'Odvolaný pokus po sobě doklad nenechal.');
    }
}
