<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EP-3 — souběh účtování × uzávěrky. Bariérový scénář nad DVĚMA nezávislými PDO
 * spojeními (dva kontejnery = dvě Connection = dvě DB sessions):
 *
 *   A (uzávěrka): v transakci zamkne řádek období SELECT … FOR UPDATE a přepne ho
 *                 na 'closing'. NEcommitne (drží zámek).
 *   B (účtování): pokusí se zaúčtovat do TÉHOŽ období. Jeho postDocument otevře
 *                 vlastní transakci a v ní čte období rovněž FOR UPDATE → BLOKUJE na
 *                 zámku A. S krátkým innodb_lock_wait_timeout to po ~1 s spadne (1205),
 *                 čímž je prokázáno, že se souběh SERIALIZUJE (dřív B běžel bez zámku
 *                 a stav ověřoval PŘED začátkem transakce → propadl by do uzavíraného
 *                 období). Po commitu A vidí B pod (uvolněným) zámkem 'closing' a
 *                 zápis ODMÍTNE (period_not_open).
 *
 * Akceptační kritérium: po zahájení uzávěrky NELZE zaúčtovat další zápis do stejného
 * období — v deníku nezůstane žádný zápis B.
 *
 * COMMITNUTÝ seed (druhé spojení musí seed vidět) → explicitní úklid v tearDown
 * (DELETE dodavatele → CASCADE + activity_log). Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class PostingClosingConcurrencyTest extends TestCase
{
    private const YEAR = 2099;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $dbA;
    private Connection $dbB;
    private AccountingPeriodRepository $periodsA;
    private PostingService $postingB;
    private \MyInvoice\Repository\JournalEntryRepository $journalB;
    private \MyInvoice\Service\Accounting\Dimension\DimensionService $dimensionsB;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private ?int $snapshotIsolation = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            // Dva NEZÁVISLÉ kontejnery → dvě Connection (dvě DB sessions) pro souběh.
            // Kontejner B se staví v nesdílené zóně: testovací běh jinak recykluje jedno
            // PDO přes všechny Connection a obě „strany" souběhu by seděly v téže DB
            // session — zámek by se sám sobě nikdy nepostavil do cesty a test by
            // serializaci jen předstíral.
            //
            // ⚠️ Connection MUSÍ vzniknout UVNITŘ zóny. Rozhodnutí „smím sdílet PDO?"
            // padá v jeho konstruktoru, ne až při prvním dotazu. Dřív to vycházelo
            // samo, protože buildApp() eagerně instancoval middleware a ty si
            // Connection vytáhly; od chvíle, kdy se middleware resolvují líně
            // (deferred), by se konstrukce odložila až za zónu a kontejner B by
            // dostal sdílené spojení — test by souběh jen předstíral.
            $containerA = Bootstrap::buildContainer();
            $containerB = Connection::withoutSharedTestConnection(static function () {
                $c = Bootstrap::buildContainer();
                $c->get(Connection::class);

                return $c;
            });
            $this->dbA       = $containerA->get(Connection::class);
            $this->dbB       = $containerB->get(Connection::class);
            $this->periodsA  = $containerA->get(AccountingPeriodRepository::class);
            $this->postingB  = $containerB->get(PostingService::class);
            $this->journalB = $containerB->get(\MyInvoice\Repository\JournalEntryRepository::class);
            $this->dimensionsB = $containerB->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
            $seederA         = $containerA->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdoA = $this->dbA->pdo();
        $this->userId = (int) ($pdoA->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdoA->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdoA->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdoA->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        // COMMITNUTÝ seed přes A — spojení B ho musí vidět (uncommitted je session-privátní).
        $stmt = $pdoA->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        );
        $stmt->execute(['EP-3 souběh s.r.o.', $czId, 'ep3-concurrency-' . uniqid() . '@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdoA->lastInsertId();

        $seederA->seedForSupplier($this->supplierId);
        $this->periodId = $this->periodsA->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
    }

    protected function tearDown(): void
    {
        if (isset($this->dbB)) {
            $pdoB = $this->dbB->pdo();
            if ($pdoB->inTransaction()) {
                $pdoB->rollBack();
            }
            $this->dbB->close();
        }
        if (isset($this->dbA) && $this->supplierId !== 0) {
            $pdoA = $this->dbA->pdo();
            if ($pdoA->inTransaction()) {
                $pdoA->rollBack();
            }
            if ($this->snapshotIsolation !== null) {
                $pdoA->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotIsolation);
            }
            $pdoA->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdoA->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdoA->prepare('DELETE FROM document_dimensions WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdoA->prepare('DELETE FROM document_dimension_splits WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdoA->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
            $this->dbA->close();
        }
    }

    public function testConcurrentClosingStartBlocksThenRejectsPosting(): void
    {
        $pdoA = $this->dbA->pdo();
        $pdoB = $this->dbB->pdo();

        // A: zahájení uzávěrky — zamkni řádek období FOR UPDATE a přepni na 'closing'.
        // Bez commitu (drží zámek), přesně jako ClosingService::start uvnitř své tx.
        $pdoA->beginTransaction();
        $locked = $this->periodsA->findForUpdate($this->periodId, $this->supplierId);
        self::assertNotNull($locked, 'A zamkne řádek období FOR UPDATE.');
        $pdoA->prepare(
            "UPDATE accounting_periods SET status = 'closing', row_version = row_version + 1
              WHERE id = ? AND supplier_id = ?"
        )->execute([$this->periodId, $this->supplierId]);

        // B: pokus zaúčtovat do téhož období. postDocument čte období FOR UPDATE ve
        // vlastní tx → blokuje na zámku A. Krátký timeout → 1205 (serializace prokázána).
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $this->postDocumentB();
        } catch (PostingException $e) {
            self::fail('B nesmí projít validací stavu — má být zablokováno zámkem A, ne odmítnuto (' . $e->errorCode . ').');
        } catch (\PDOException $e) {
            // Lock wait timeout (1205) — B čeká na zámek období, který drží uzávěrka A.
            $blocked = true;
        } catch (\Throwable $e) {
            $blocked = true;
        }
        self::assertTrue($blocked, 'Účtování B je serializováno zámkem období, který drží uzávěrka A (EP-3).');
        if ($pdoB->inTransaction()) {
            $pdoB->rollBack();
        }

        // A dokončí uzávěrku (commit → 'closing' je trvale viditelné pro B).
        $pdoA->commit();

        // B zkusí znovu: teď pod (uvolněným) zámkem uvidí 'closing' → period_not_open.
        try {
            $this->postDocumentB();
            self::fail('Po zahájení uzávěrky NELZE zaúčtovat do stejného období.');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode, 'Zápis do uzavíraného období je odmítnut.');
        }

        // Do uzavíraného období se nezaúčtoval žádný zápis (akceptační kritérium).
        $stmt = $this->dbB->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual'"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Žádný zápis B se do uzavíraného období nepropsal.');
    }

    public function testDimensionChangeSerializesWithClosingAndPreservesSnapshot(): void
    {
        $pdoA = $this->dbA->pdo();
        $pdoB = $this->dbB->pdo();
        self::assertNotSame($pdoA->query('SELECT CONNECTION_ID()')->fetchColumn(), $pdoB->query('SELECT CONNECTION_ID()')->fetchColumn());
        $this->dimensionsB->setEnabled($this->supplierId, true);
        $type = $this->dimensionsB->ensureDefaultTypes($this->supplierId, ['projekt'])['project'];
        $a = (int) $this->dimensionsB->createValue($this->supplierId, $type, ['code' => 'RACE-A', 'name' => 'Projekt A'])['id'];
        $b = (int) $this->dimensionsB->createValue($this->supplierId, $type, ['code' => 'RACE-B', 'name' => 'Projekt B'])['id'];
        $entry = $this->postingB->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 100.00, 'dimensions' => [$type => $a]],
            ['account_code' => '411', 'side' => 'credit', 'amount' => 100.00, 'dimensions' => [$type => $a]],
        ], ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId]);
        $assignments = new \MyInvoice\Repository\DimensionAssignmentRepository($this->dbB);
        $before = $assignments->entryLineDimensions($this->supplierId, $entry);
        $line = (int) array_key_first($before);
        $pdoA->beginTransaction();
        self::assertNotNull($this->periodsA->findForUpdate($this->periodId, $this->supplierId));
        $this->periodsA->setStatus($this->periodId, $this->supplierId, 'closed');
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->dimensionsB->saveEntryLines($this->supplierId, $entry, [$line => [$type => $b]]);
            self::fail('Editor musí čekat na zámek právě uzavíraného období.');
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0));
        }
        self::assertFalse($pdoB->inTransaction());
        self::assertSame($before, $assignments->entryLineDimensions($this->supplierId, $entry));
        $pdoA->commit();
        try {
            $this->dimensionsB->saveEntryLines($this->supplierId, $entry, [$line => [$type => $b]]);
            self::fail('Po commitu uzávěrky editor musí vidět nový stav.');
        } catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
        self::assertSame($before, $assignments->entryLineDimensions($this->supplierId, $entry));
        self::assertSame(0, $this->dimensionsB->saveEntryLines($this->supplierId, $entry, [$line => [$type => $a]]));
    }

    public function testOldSnapshotCannotRetagSourcePostedAndClosedByAnotherConnection(): void
    {
        [$type, $a, $b, $source] = $this->snapshotSource();
        $pdoA = $this->dbA->pdo();
        $pdoA->beginTransaction();
        self::assertSame(0, (int) $pdoA->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ' . $this->supplierId)->fetchColumn());
        $entry = $this->postSnapshotSource($source, $type, $b, true);
        $dimensions = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        try {
            $dimensions->saveDocument($this->supplierId, 'other_item', $source, [$type => $a], null);
            self::fail('Starší snapshot nesmí přehlédnout nový zápis uzavřeného období.');
        } catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
        $pdoA->rollBack();
        $assignments = new \MyInvoice\Repository\DimensionAssignmentRepository($this->dbA);
        self::assertSame([$type => $b], $assignments->documentDimensions($this->supplierId, 'other_item', $source)['header']);
        foreach ($assignments->entryLineDimensions($this->supplierId, $entry) as $dims) {
            self::assertSame([$type => $b], $dims);
        }
    }

    public function testOldSnapshotAllowsCurrentClosedNoOp(): void
    {
        $this->assertOldSnapshotEdit(true);
    }

    public function testOldSnapshotAllowsNewOpenEntryChange(): void
    {
        $this->assertOldSnapshotEdit(false);
    }

    private function assertOldSnapshotEdit(bool $closed): void
    {
        [$type, $a, $b, $source] = $this->snapshotSource();
        $pdoA = $this->dbA->pdo();
        $pdoA->beginTransaction();
        $pdoA->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
        $entry = $this->postSnapshotSource($source, $type, $b, $closed);
        $lines = (new \MyInvoice\Repository\DimensionAssignmentRepository($this->dbB))->entryLineDimensions($this->supplierId, $entry);
        $line = (int) array_key_first($lines);
        $dimensions = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        self::assertSame($closed ? 0 : 1, $dimensions->saveEntryLines($this->supplierId, $entry, [$line => [$type => $closed ? $b : $a]]));
        if ($closed) {
            self::assertSame(0, Bootstrap::buildContainer()->get(PostingService::class)->restampDimensions($this->supplierId, 'other_item', $source)['lines']);
            self::assertSame(0, $dimensions->saveDocument($this->supplierId, 'other_item', $source, [$type => $b], null)['restamp']['lines']);
        }
        $pdoA->rollBack();
    }

    private function snapshotSource(): array
    {
        if ($this->snapshotIsolation === null) {
            $this->snapshotIsolation = (int) $this->dbA->pdo()->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
            $this->dbA->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
        }
        $this->dimensionsB->setEnabled($this->supplierId, true);
        $type = $this->dimensionsB->ensureDefaultTypes($this->supplierId, ['projekt'])['project'];
        $suffix = uniqid();
        $a = (int) $this->dimensionsB->createValue($this->supplierId, $type, ['code' => 'SNAP-A-' . $suffix, 'name' => 'Projekt A'])['id'];
        $b = (int) $this->dimensionsB->createValue($this->supplierId, $type, ['code' => 'SNAP-B-' . $suffix, 'name' => 'Projekt B'])['id'];
        $this->dbB->pdo()->prepare('INSERT INTO other_items (supplier_id, side, title, issued_on, due_on, amount)
            VALUES (?, "payable", "Syntetický souběh dimenzí", ?, ?, 100)')->execute([$this->supplierId, self::ENDS_ON, self::ENDS_ON]);
        $source = (int) $this->dbB->pdo()->lastInsertId();
        $this->dimensionsB->saveDocument($this->supplierId, 'other_item', $source, [$type => $a], null);
        return [$type, $a, $b, $source];
    }

    private function postSnapshotSource(int $source, int $type, int $value, bool $closed): int
    {
        $this->dimensionsB->saveDocument($this->supplierId, 'other_item', $source, [$type => $value], null);
        $entry = $this->postingB->postDocument($this->supplierId, 'other_item', $source, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '411', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId]);
        if ($closed) {
            $this->dbB->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND id = ?")
                ->execute([$this->supplierId, $this->periodId]);
        }
        return $entry;
    }

    private function postDocumentB(): void
    {
        $this->postingB->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('partialSnapshotModes')]
    public function testPartialReplacementCannotRewriteClosedSnapshot(int $snapshotIsolation): void
    {
        [$source, $entry, $line, $version] = $this->partialReplacementSource();
        $before = $this->journalB->find($entry, $this->supplierId);
        $pdo = $this->dbA->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $snapshotIsolation);
        $pdo->beginTransaction();
        self::assertSame('open', $this->periodsA->findById($this->supplierId, $this->periodId)['status']);
        $periodsB = new AccountingPeriodRepository($this->dbB);
        self::assertTrue($periodsB->setStatusCas($this->periodId, $this->supplierId, 'closed', (int) $periodsB->findById($this->supplierId, $this->periodId)['row_version'], $this->userId));
        $failure = null;
        try {
            Bootstrap::buildContainer()->get(PostingService::class)->replaceEntryLines(
                $this->supplierId, 'other_item', $source, $version, [$line],
                [['account_code' => '518', 'side' => 'debit', 'amount' => 100.00]],
            );
        } catch (\Throwable $e) {
            $failure = $e;
        }
        $transactionActive = $pdo->inTransaction();
        $changed = $pdo->query('SELECT COUNT(*) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.entry_id = ' . $entry . " AND a.account_code = '518'")->fetchColumn();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        self::assertNotNull($failure, 'Closed snapshot was partially rewritten: new 518 debit rows = ' . $changed);
        self::assertSame(0, (int) $changed);
        if ($snapshotIsolation === 0) {
            self::assertInstanceOf(PostingException::class, $failure);
            self::assertSame('period_not_open', $failure->errorCode);
        } else {
            self::assertInstanceOf(\PDOException::class, $failure);
            self::assertSame(1020, (int) $failure->errorInfo[1]);
            self::assertFalse($transactionActive, 'Fatal snapshot conflict must end the caller transaction.');
        }
        self::assertSame($before, $this->journalB->find($entry, $this->supplierId));
    }

    public static function partialSnapshotModes(): array
    {
        return [[0], [1]];
    }

    public function testPartialReplacementLocksPeriodBeforeJournal(): void
    {
        [$source, $entry, $line, $version] = $this->partialReplacementSource();
        $pdoA = $this->dbA->pdo();
        $pdoB = $this->dbB->pdo();
        $pdoA->beginTransaction();
        $this->periodsA->findForUpdate($this->periodId, $this->supplierId);
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdoB->beginTransaction();
        $failure = null;
        try {
            $this->postingB->replaceEntryLines($this->supplierId, 'other_item', $source, $version, [$line],
                [['account_code' => '518', 'side' => 'debit', 'amount' => 100.00]]);
        } catch (\PDOException $e) {
            $failure = $e;
        }
        self::assertNotNull($failure, 'Replacement must wait on the period held by closing.');
        self::assertSame(1205, (int) $failure->errorInfo[1]);
        self::assertTrue($pdoB->inTransaction());
        self::assertSame($entry, (int) $pdoA->query('SELECT id FROM journal_entries WHERE id = ' . $entry . ' FOR UPDATE NOWAIT')->fetchColumn(),
            'Waiting replacement must not already hold the journal lock.');
    }

    private function partialReplacementSource(): array
    {
        $pdo = $this->dbB->pdo();
        self::assertNotSame($this->dbA->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
        $pdo->prepare('INSERT INTO other_items (supplier_id, side, title, issued_on, due_on, amount) VALUES (?, "payable", "Partial replacement concurrency", ?, ?, 100)')
            ->execute([$this->supplierId, self::ENDS_ON, self::ENDS_ON]);
        $source = (int) $pdo->lastInsertId();
        $entry = $this->postingB->postDocument($this->supplierId, 'other_item', $source, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '411', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId]);
        $current = $this->journalB->find($entry, $this->supplierId);
        $debit = array_values(array_filter($current['lines'], static fn (array $line): bool => $line['side'] === 'debit'))[0];
        return [$source, $entry, (int) $debit['id'], (int) $current['row_version']];
    }
}
