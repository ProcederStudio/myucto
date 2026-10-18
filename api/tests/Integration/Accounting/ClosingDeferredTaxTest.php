<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Odložená daň — ČÚS 003 a § 59 vyhlášky 500/2002 Sb. (481 / 592).
 *
 * Matice účetnictví vedla položku jako CHYBÍ a byl to nález s vysokým rizikem: účty 481
 * a 592 byly jen řádky v šabloně osnovy, výkazy je měly namapované, ale nikdy na ně nic
 * nepřistálo — žádný výpočet přechodných rozdílů, žádná kontace, žádný krok uzávěrky.
 * Splatná daň 591/341 přitom hotová byla.
 *
 * Dvě věci, které testy hlídají především:
 *   1. Základem je KUMULATIVNÍ rozdíl zůstatkových cen k rozvahovému dni, ne roční rozdíl
 *      odpisů — a majetek, který se v běžném roce už neodepisuje, z výpočtu vypadnout
 *      NESMÍ, protože přechodný rozdíl u něj trvá dál.
 *   2. Odloženou daňovou POHLEDÁVKU nelze zaúčtovat automaticky (§ 59 odst. 4) — systém
 *      neumí posoudit, jestli bude dosaženo základu daně, o který ji lze uplatnit.
 */
#[Group('integration')]
final class ClosingDeferredTaxTest extends TestCase
{
    private const YEAR = 2096;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $assetSeq = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW COLUMNS FROM accounting_closing_steps LIKE 'step_key'")->fetch() === false) {
            $this->markTestSkipped('Chybí accounting_closing_steps.');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        )->execute(['Odložená daň s.r.o.', $czId, 'odd@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── výpočet přechodných rozdílů ──────────────────────────────────────────

    /** Bez majetku a bez ztráty není co odkládat. */
    public function testNoDifferencesMeansZero(): void
    {
        $p = $this->closing->deferredTaxPreview($this->supplierId, $this->periodId);

        self::assertSame([], $p['computation']['titles']);
        self::assertSame(0.0, $p['computation']['deferred_tax']);
        self::assertSame('none', $p['computation']['kind']);
    }

    /**
     * Daňové odpisy předběhly účetní → účetní ZC je vyšší → v budoucnu se odečte méně
     * → odložený daňový ZÁVAZEK.
     */
    public function testFasterTaxDepreciationGivesLiability(): void
    {
        $this->asset(accountingResidual: 700000.0, taxResidual: 500000.0);

        $c = $this->closing->deferredTaxPreview($this->supplierId, $this->periodId)['computation'];

        self::assertSame('liability', $c['kind']);
        self::assertEqualsWithDelta(200000.0, $c['net_difference'], 0.01);
        self::assertEqualsWithDelta(200000.0 * $c['rate'], $c['deferred_tax'], 0.01);
        self::assertFalse($c['requires_prudence_check']);
    }

    /** Opačný směr (účetní odpisy rychlejší) dá pohledávku a vyžádá si posouzení. */
    public function testFasterAccountingDepreciationGivesAsset(): void
    {
        $this->asset(accountingResidual: 400000.0, taxResidual: 600000.0);

        $c = $this->closing->deferredTaxPreview($this->supplierId, $this->periodId)['computation'];

        self::assertSame('asset', $c['kind']);
        self::assertEqualsWithDelta(-200000.0, $c['net_difference'], 0.01);
        self::assertTrue($c['requires_prudence_check']);
        self::assertStringContainsString('§ 59 odst. 4', implode("\n", $c['warnings']));
    }

    /**
     * Majetek, který se v běžném roce už neodepisuje, musí ZŮSTAT v základu. Kdyby se
     * filtrovalo na jediný rok, jeho přechodný rozdíl by tiše zmizel, ačkoli trvá dál.
     */
    public function testAssetNotDepreciatedThisYearStillCounts(): void
    {
        $this->asset(accountingResidual: 300000.0, taxResidual: 100000.0, lastYear: self::YEAR - 3);

        $c = $this->closing->deferredTaxPreview($this->supplierId, $this->periodId)['computation'];

        self::assertEqualsWithDelta(200000.0, $c['net_difference'], 0.01);
    }

    /** Odpis z budoucího roku se do rozvahového dne započítat nesmí. */
    public function testFutureDepreciationIsIgnored(): void
    {
        $this->asset(accountingResidual: 300000.0, taxResidual: 100000.0, lastYear: self::YEAR + 1);

        $c = $this->closing->deferredTaxPreview($this->supplierId, $this->periodId)['computation'];

        self::assertSame([], $c['titles']);
    }

    /** Tituly se sčítají do JEDNÉ čisté hodnoty — 481 je vždy jeden zůstatek (§ 59). */
    public function testTitlesNetAgainstEachOther(): void
    {
        $this->asset(accountingResidual: 700000.0, taxResidual: 500000.0);

        $c = $this->closing->deferredTaxPreview($this->supplierId, $this->periodId, [
            'Účetní opravná položka nad rámec ZoR' => -50000.0,
        ])['computation'];

        self::assertCount(2, $c['titles']);
        self::assertEqualsWithDelta(150000.0, $c['net_difference'], 0.01);
    }

    /** Nulový ruční titul se zahodí — jinak by v rozpisu strašil prázdný řádek. */
    public function testZeroManualTitleIsDropped(): void
    {
        $c = $this->closing->deferredTaxPreview($this->supplierId, $this->periodId, ['Nic' => 0.0])['computation'];

        self::assertSame([], $c['titles']);
    }

    // ── zaúčtování ───────────────────────────────────────────────────────────

    /** Závazek se účtuje MD 592 / D 481. */
    public function testLiabilityPostsToExpenseAndLiability(): void
    {
        $this->startClosing();
        $res = $this->closing->runDeferredTax($this->supplierId, $this->periodId, 42000.0, $this->rowVersion(), $this->meta());

        self::assertSame('liability', $res['kind']);
        $lines = $this->entryLines($res['entry_id']);
        self::assertSame(['592' => 'debit', '481' => 'credit'], $lines);
    }

    /** Pohledávka má stejné účty prohozené — 481 je aktivum, 592 se snižuje. */
    public function testAssetPostsInverted(): void
    {
        $this->startClosing();
        $res = $this->closing->runDeferredTax(
            $this->supplierId, $this->periodId, -18000.0, $this->rowVersion(), $this->meta(), null, true
        );

        self::assertSame('asset', $res['kind']);
        self::assertSame(['481' => 'debit', '592' => 'credit'], $this->entryLines($res['entry_id']));
    }

    /**
     * Pohledávka BEZ potvrzení opatrnosti se zaúčtovat nesmí (§ 59 odst. 4). Automatické
     * zaúčtování by nadhodnotilo aktiva o daň, kterou nemusí být z čeho uplatnit.
     */
    public function testAssetWithoutPrudenceConfirmationIsRejected(): void
    {
        $this->startClosing();

        try {
            $this->closing->runDeferredTax($this->supplierId, $this->periodId, -18000.0, $this->rowVersion(), $this->meta());
            self::fail('Pohledávka bez potvrzení musí být odmítnuta.');
        } catch (ClosingException $e) {
            self::assertSame('prudence_check_required', $e->errorCode);
        }
    }

    /** Nulová odložená daň se neúčtuje — krok se přeskakuje. */
    public function testZeroAmountIsRejected(): void
    {
        $this->startClosing();

        $this->expectException(ClosingException::class);
        $this->closing->runDeferredTax($this->supplierId, $this->periodId, 0.0, $this->rowVersion(), $this->meta());
    }

    /** Opakované spuštění přepíše týž zápis, nezaloží druhý (idempotence přes source). */
    public function testRerunRewritesSameEntry(): void
    {
        $this->startClosing();
        $first = $this->closing->runDeferredTax($this->supplierId, $this->periodId, 42000.0, $this->rowVersion(), $this->meta());
        $second = $this->closing->runDeferredTax($this->supplierId, $this->periodId, 51000.0, $this->rowVersion(), $this->meta());

        self::assertSame($first['entry_id'], $second['entry_id']);
        self::assertSame($first['document_no'], $second['document_no']);
        self::assertSame(51000.0, $second['amount']);
    }

    /** Odchylka od výpočtu se nezakazuje, ale zůstává dohledatelná. */
    public function testDeviationFromComputationIsRecorded(): void
    {
        $this->asset(accountingResidual: 700000.0, taxResidual: 500000.0);
        $this->startClosing();

        $res = $this->closing->runDeferredTax(
            $this->supplierId, $this->periodId, 30000.0, $this->rowVersion(), $this->meta(), 'Část titulu neuznána'
        );

        self::assertNotNull($res['computed_amount']);
        self::assertEqualsWithDelta(30000.0 - (float) $res['computed_amount'], (float) $res['difference'], 0.01);
        self::assertSame('Část titulu neuznána', $res['reason']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** Majetek s evidovanými odpisy obou druhů; `lastYear` = rok posledního odpisu. */
    private function asset(float $accountingResidual, float $taxResidual, ?int $lastYear = null): int
    {
        $lastYear ??= self::YEAR;
        $pdo = $this->db->pdo();
        $this->assetSeq++;

        $pdo->prepare(
            'INSERT INTO assets (supplier_id, name, inventory_number, acquisition_date, input_price)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, 'Stroj ' . $this->assetSeq, 'INV-' . $this->assetSeq,
            (self::YEAR - 4) . '-01-15', 1000000.0,
        ]);
        $assetId = (int) $pdo->lastInsertId();

        foreach (['accounting' => $accountingResidual, 'tax' => $taxResidual] as $kind => $residual) {
            $pdo->prepare(
                'INSERT INTO depreciation_entries
                    (supplier_id, asset_id, kind, fiscal_year, amount, full_amount, residual_value_end, status)
                 VALUES (?, ?, ?, ?, 100000, 100000, ?, "confirmed")'
            )->execute([$this->supplierId, $assetId, $kind, $lastYear, $residual]);
        }

        return $assetId;
    }

    public function testDeferredTaxCanBeSkippedWithoutPosting(): void
    {
        $this->startClosing();
        $version = $this->rowVersion();
        $result = $this->closing->confirmStep($this->supplierId, $this->periodId, 'deferred_tax', 'skipped', 'Synthetic no temporary differences', $version, $this->meta());

        self::assertSame('deferred_tax', $result['step']);
        self::assertSame('skipped', $result['status']);
        self::assertSame($version + 1, $this->rowVersion());
        self::assertNull($this->journal->findBySource($this->supplierId, 'deferred_tax', $this->periodId));
        $stmt = $this->db->pdo()->prepare("SELECT status,note FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='deferred_tax'");
        $stmt->execute([$this->supplierId, $this->periodId]);
        self::assertSame(['status' => 'skipped', 'note' => 'Synthetic no temporary differences'], $stmt->fetch(\PDO::FETCH_ASSOC));
    }

    public function testRevertDeferredLiabilityRemovesPostedJournalAndAuditsItsLines(): void
    {
        $this->assertDeferredTaxRevert(42000.0);
    }

    public function testRevertDeferredAssetRemovesPostedJournalAndAuditsItsLines(): void
    {
        $this->assertDeferredTaxRevert(-18000.0);
    }

    public function testAbortRejectsPostedDeferredTaxEntry(): void
    {
        $this->startClosing();
        $this->closing->runDeferredTax($this->supplierId, $this->periodId, 42000.0, $this->rowVersion(), $this->meta());

        try {
            $this->closing->abort($this->supplierId, $this->periodId, $this->rowVersion(), $this->meta());
            self::fail('Zaúčtovaná odložená daň musí přerušení uzávěrky blokovat.');
        } catch (ClosingException $e) {
            self::assertSame('closing_entries_exist', $e->errorCode);
        }
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'deferred_tax', $this->periodId));
        self::assertSame('closing', $this->periods->findById($this->supplierId, $this->periodId)['status']);
    }

    private function assertDeferredTaxRevert(float $amount): void
    {
        $this->startClosing();
        $posted = $this->closing->runDeferredTax($this->supplierId, $this->periodId, $amount, $this->rowVersion(), $this->meta(), null, $amount < 0);
        $repository = new \MyInvoice\Repository\ClosingRepository($this->db);
        self::assertEqualsWithDelta(-$amount, $repository->accountBalance($this->supplierId, '481', self::ENDS_ON), 0.01);
        self::assertEqualsWithDelta($amount, $repository->accountBalance($this->supplierId, '592', self::ENDS_ON), 0.01);
        $rowVersion = $this->rowVersion();
        $result = $this->closing->revertStep($this->supplierId, $this->periodId, 'deferred_tax', $rowVersion, $this->meta());

        self::assertNull($this->journal->findBySource($this->supplierId, 'deferred_tax', $this->periodId), 'Vrácení nativního kroku musí odstranit jím vytvořený zápis.');
        self::assertEqualsWithDelta(0.0, $repository->accountBalance($this->supplierId, '481', self::ENDS_ON), 0.01);
        self::assertEqualsWithDelta(0.0, $repository->accountBalance($this->supplierId, '592', self::ENDS_ON), 0.01);
        self::assertSame($rowVersion + 1, $this->rowVersion());
        self::assertSame($posted['entry_id'], (int) $result['dumps']['deferred_tax']['entry']['id']);
        self::assertCount(2, $result['dumps']['deferred_tax']['lines']);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id = ?');
        $stmt->execute([$posted['entry_id']]);
        self::assertSame(0, (int) $stmt->fetchColumn());
        $stmt = $this->db->pdo()->prepare("SELECT status, payload FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'deferred_tax'");
        $stmt->execute([$this->supplierId, $this->periodId]);
        self::assertSame(['status' => 'pending', 'payload' => null], $stmt->fetch(\PDO::FETCH_ASSOC));
        $stmt = $this->db->pdo()->prepare("SELECT payload FROM activity_log WHERE supplier_id = ? AND entity_id = ? AND action = 'accounting.closing_step_reverted' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->supplierId, $this->periodId]);
        $audit = json_decode((string) $stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deferred_tax', $audit['step']);
        self::assertSame($result['dumps'], $audit['entry_dump']);
        $aborted = $this->closing->abort($this->supplierId, $this->periodId, $this->rowVersion(), $this->meta());
        self::assertSame('open', $aborted['status']);
    }

    private function startClosing(): void
    {
        $this->closing->start($this->supplierId, $this->periodId, $this->rowVersion(), $this->meta());
    }

    private function rowVersion(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array<string,mixed> */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /** @return array<string,string> account_code => side */
    private function entryLines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT coa.account_code, jel.side
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id
              WHERE jel.entry_id = ? ORDER BY jel.id'
        );
        $stmt->execute([$entryId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['account_code']] = (string) $r['side'];
        }

        return $out;
    }
}
