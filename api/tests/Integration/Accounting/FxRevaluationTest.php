<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy kurzového přecenění k rozvahovému dni (Epic F4, §6.2 I7–I10):
 * slot 1 saldokonto z reálné EUR faktury (hodnoty U6), slot 2 banka, vyloučení
 * uhrazených dokladů, FX storno k 1. dni dle settings (R11) a idempotentní
 * re-run (in-place rewrite, R6).
 *
 * Kurz ČNB se seeduje přímo do exchange_rates (cache-first klient — bez HTTP).
 * Izolovaný supplier, transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class FxRevaluationTest extends TestCase
{
    private const YEAR = 2098;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private ClosingRepository $closingRepo;
    private ChartOfAccountsRepository $accounts;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;
    private AccountingSupplierSettingsRepository $settings;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $eurId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db          = $container->get(Connection::class);
            $this->posting     = $container->get(PostingService::class);
            $this->closing     = $container->get(ClosingService::class);
            $this->closingRepo = $container->get(ClosingRepository::class);
            $this->accounts    = $container->get(ChartOfAccountsRepository::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $this->journal     = $container->get(JournalEntryRepository::class);
            $this->settings    = $container->get(AccountingSupplierSettingsRepository::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId    = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId      = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId      = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "f4-fx@example.com", ?, ?)'
        );
        $stmt->execute(['F4 FX test s.r.o.', $this->czId, $currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);

        // EUR měna izolovaného suppliera (openFxItems čte currency_code z řádků deníku,
        // faktura potřebuje currency_id).
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "EUR", "EUR", "€", "Euro", "Euro", 2, 1, 0)'
        )->execute([$this->supplierId]);
        $this->eurId = (int) $pdo->lastInsertId();

        // Kurz ČNB k rozvahovému dni — seed cache (žádné HTTP).
        $this->seedRate(self::ENDS_ON, 'EUR', 25.10);
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

    // ── I7: saldokonto (U6 hodnoty) + banka ──────────────────────────────────

    public function testI7RevaluesOpenReceivableAndBankBalance(): void
    {
        $this->postEurInvoice(1000.00, 24.50);
        // Kč zůstatek banky 221 = 50 000 (devizový účet vedený v Kč v deníku)
        $this->manual([
            self::l('221', 'debit', 50000.00),
            self::l('411', 'credit', 50000.00),
        ], self::YEAR . '-01-15');

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $result = $this->closing->runFxRevaluation(
            $this->supplierId,
            $this->periodId,
            [['account_code' => '221', 'currency_code' => 'EUR', 'foreign_balance' => 2000.00]],
            $this->rv(),
            $this->meta(),
        );

        // Slot 1 (saldokonto): ('fx_revaluation', pid*10+1), MD 311 600 / D 663 (U6)
        $slot1 = $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($this->periodId));
        self::assertNotNull($slot1, 'Slot 1 saldokonto existuje.');
        self::assertSame(self::ENDS_ON, (string) $slot1['entry_date']);
        $byCode = $this->linesByAccountCode((int) $slot1['id']);
        self::assertSame(self::cents(600.00), self::cents($byCode['311']['debit']), 'MD 311 600 = 1 000 × (25,10 − 24,50).');
        self::assertSame(self::cents(600.00), self::cents($byCode['663']['credit']), 'D 663 600 (kurzový zisk).');

        // Slot 2 (banka): MD 221 200 / D 663 (2 000 × 25,10 − 50 000)
        $slot2 = $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxBank($this->periodId));
        self::assertNotNull($slot2, 'Slot 2 banka existuje.');
        $byCode2 = $this->linesByAccountCode((int) $slot2['id']);
        self::assertSame(self::cents(200.00), self::cents($byCode2['221']['debit']));
        self::assertSame(self::cents(200.00), self::cents($byCode2['663']['credit']));

        // Payload kroku: rozpad per doklad — 1 doklad
        $state = $this->closing->state($this->supplierId, $this->periodId);
        $fxStep = null;
        foreach ($state['steps'] as $step) {
            if ($step['step_key'] === 'fx_revaluation') {
                $fxStep = $step;
            }
        }
        self::assertNotNull($fxStep);
        self::assertSame('done', $fxStep['status']);
        self::assertCount(1, $fxStep['payload']['detail'], 'Detail per doklad — 1 otevřená EUR faktura.');
        self::assertCount(2, $result['entry_ids'], 'Sloty saldo + bank.');
    }

    // ── I8: uhrazený doklad se nepřeceňuje ───────────────────────────────────

    public function testI8PaidInvoiceIsNotRevalued(): void
    {
        $invoiceId = $this->postEurInvoice(1000.00, 24.50);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_at = ? WHERE id = ?')
            ->execute([self::YEAR . '-11-30', $invoiceId]);

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($this->supplierId, $this->periodId, [], $this->rv(), $this->meta());

        self::assertNull(
            $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($this->periodId)),
            'Doklad uhrazený před rozvahovým dnem se nepřeceňuje (R10a).',
        );
    }

    public function testUnpaidRedStornoCreditNoteIsRevaluedWithSignedFxAmount(): void
    {
        $creditNoteId = $this->postEurInvoice(
            -1000.00,
            24.50,
            'credit_note',
            'DOB-2098-EUR-1',
        );

        $open = array_values(array_filter(
            $this->closingRepo->openFxItems($this->supplierId, self::ENDS_ON),
            static fn (array $item): bool => $item['doc_type'] === 'invoice'
                && $item['doc_id'] === $creditNoteId,
        ));
        self::assertCount(1, $open);
        self::assertSame(self::cents(-1000.00), self::cents((float) $open[0]['amount_foreign']));

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $preview = $this->closing->fxPreview($this->supplierId, $this->periodId);
        $this->closing->runFxRevaluation(
            $this->supplierId,
            $this->periodId,
            [],
            $this->rv(),
            $this->meta(),
        );

        $slot = $this->journal->findBySource(
            $this->supplierId,
            'fx_revaluation',
            ClosingSourceId::fxSaldo($this->periodId),
        );
        self::assertNotNull($slot, 'Záporná otevřená cizoměnová pohledávka se nesmí přeskočit.');
        $byCode = $this->linesByAccountCode((int) $slot['id']);
        self::assertSame(self::cents(600.00), self::cents($byCode['563']['debit']));
        self::assertSame(self::cents(600.00), self::cents($byCode['311']['credit']));

        self::assertCount(1, $preview['saldo']['detail']);
        $detail = $preview['saldo']['detail'][0];
        self::assertSame(self::cents(-1000.00), self::cents((float) $detail['remaining_foreign']));
        self::assertSame(self::cents(-600.00), self::cents((float) $detail['diff']));
        self::assertSame('loss', $detail['direction']);
    }

    public function testOrdinaryInvoiceAndRedCreditNoteAreRevaluedAsSignedNet(): void
    {
        $this->postEurInvoice(1000.00, 24.50, 'invoice', 'FV-2098-EUR-MIX');
        $this->postEurInvoice(-400.00, 24.50, 'credit_note', 'DOB-2098-EUR-MIX');

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $preview = $this->closing->fxPreview($this->supplierId, $this->periodId);
        $this->closing->runFxRevaluation(
            $this->supplierId,
            $this->periodId,
            [],
            $this->rv(),
            $this->meta(),
        );

        $slot = $this->journal->findBySource(
            $this->supplierId,
            'fx_revaluation',
            ClosingSourceId::fxSaldo($this->periodId),
        );
        self::assertNotNull($slot);
        $byCode = $this->linesByAccountCode((int) $slot['id']);
        self::assertSame(self::cents(360.00), self::cents($byCode['311']['debit']),
            'Přecenění musí vycházet z netto 600 EUR, ne z hrubých 1 000 EUR.');
        self::assertSame(self::cents(360.00), self::cents($byCode['663']['credit']));
        self::assertArrayNotHasKey('563', $byCode);

        self::assertCount(2, $preview['saldo']['detail']);
        self::assertSame(self::cents(360.00), self::cents((float) $preview['totals']['gain']));
        self::assertSame(0, self::cents((float) $preview['totals']['loss']));
    }

    // ── I9: FX storno k 1. dni dle fx_reversal_at_open (R11) ────────────────

    public function testI9OpenNextCreatesSaldoReversalOnlyWhenEnabled(): void
    {
        $this->seedRate(self::YEAR . '-06-15', 'EUR', 24.60);
        $this->postEurInvoice(1000.00, 24.50);
        $this->manual([
            self::l('221', 'debit', 50000.00),
            self::l('411', 'credit', 50000.00),
        ], self::YEAR . '-01-15');
        $this->runChainToClosed([['account_code' => '221', 'currency_code' => 'EUR', 'foreign_balance' => 2000.00]]);

        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // Slot 3 = zrcadlo slotu 1 k 1. dni nového období
        $slot3 = $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxReversal($this->periodId));
        self::assertNotNull($slot3, 'FX storno saldokonta (slot 3) existuje (default fx_reversal_at_open = 1).');
        self::assertSame((int) $result['fx_reversal_entry_id'], (int) $slot3['id']);
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $slot3['entry_date'], 'entry_date = 1. den nového období (R6).');
        $byCode = $this->linesByAccountCode((int) $slot3['id']);
        self::assertSame(self::cents(600.00), self::cents($byCode['311']['credit']), 'Zrcadlo: D 311 600.');
        self::assertSame(self::cents(600.00), self::cents($byCode['663']['debit']), 'Zrcadlo: MD 663 600.');

        // Slot 2 (banka) se NEstornuje — trvalá úprava carrying amount (R11)
        $slot3Lines = $this->linesByAccountCode((int) $slot3['id']);
        self::assertArrayNotHasKey('221', $slot3Lines, 'Banka (slot 2) bez storna.');
    }

    public function testI9NoReversalWhenSettingDisabled(): void
    {
        $this->seedRate(self::YEAR . '-06-15', 'EUR', 24.60);
        $this->postEurInvoice(1000.00, 24.50);
        $this->settings->upsert($this->supplierId, null, null, null, null, false);
        $this->runChainToClosed([]);

        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNull(
            $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxReversal($this->periodId)),
            'S fx_reversal_at_open = 0 slot 3 nevznikne.',
        );
    }

    // ── I10: re-run = in-place rewrite (stejné entry id, nové částky) ────────

    public function testM8PriorUnreversedSaldoAdjustmentIsNotBookedTwice(): void
    {
        $this->postEurInvoice(1000.00, 24.50);
        $this->posting->postDocument($this->supplierId, 'fx_revaluation', 987654321, [
            [
                'account_code' => '311',
                'side' => 'debit',
                'amount' => 200.00,
                'currency_code' => 'EUR',
                'fx_rate' => 24.70,
                'amount_foreign' => 0.0,
            ],
            self::l('663', 'credit', 200.00),
        ], [
            'entry_date' => self::YEAR . '-01-01',
            'posted_by' => $this->userId,
            'allow_closing_period' => true,
        ]);

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($this->supplierId, $this->periodId, [], $this->rv(), $this->meta());

        $current = $this->journal->findBySource(
            $this->supplierId,
            'fx_revaluation',
            ClosingSourceId::fxSaldo($this->periodId),
        );
        self::assertNotNull($current);
        $byCode = $this->linesByAccountCode((int) $current['id']);
        self::assertSame(
            self::cents(400.00),
            self::cents($byCode['311']['debit']),
            'K přecenění 600 Kč se po ponechané starší úpravě 200 Kč doúčtuje jen 400 Kč.',
        );
    }

    public function testM8ClearsPriorUnreversedAdjustmentWhenItemIsNoLongerOpen(): void
    {
        $invoiceId = $this->postEurInvoice(1000.00, 24.50);
        // Syntetické source_id ve slotu saldokonta (period_id * 10 + 1) — slot banky
        // (…2) saldokonto záměrně nezapočítává.
        $this->posting->postDocument($this->supplierId, 'fx_revaluation', 987654331, [
            [
                'account_code' => '311',
                'side' => 'debit',
                'amount' => 200.00,
                'currency_code' => 'EUR',
                'fx_rate' => 24.70,
                'amount_foreign' => 0.0,
            ],
            self::l('663', 'credit', 200.00),
        ], [
            'entry_date' => self::YEAR . '-01-01',
            'posted_by' => $this->userId,
            'allow_closing_period' => true,
        ]);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_at = ?, status = "paid" WHERE id = ?')
            ->execute([self::YEAR . '-11-30', $invoiceId]);

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($this->supplierId, $this->periodId, [], $this->rv(), $this->meta());

        $current = $this->journal->findBySource(
            $this->supplierId,
            'fx_revaluation',
            ClosingSourceId::fxSaldo($this->periodId),
        );
        self::assertNotNull($current);
        $byCode = $this->linesByAccountCode((int) $current['id']);
        self::assertSame(self::cents(200.00), self::cents($byCode['311']['credit']));
        self::assertSame(self::cents(200.00), self::cents($byCode['563']['debit']));
    }

    public function testI10RerunRewritesInPlaceWithNewRate(): void
    {
        $this->postEurInvoice(1000.00, 24.50);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($this->supplierId, $this->periodId, [], $this->rv(), $this->meta());

        $first = $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($this->periodId));
        self::assertNotNull($first);
        $firstId = (int) $first['id'];
        $firstDocNo = (string) $first['document_no'];

        // Změna kurzu → re-run
        $this->seedRate(self::ENDS_ON, 'EUR', 26.00);
        $this->closing->runFxRevaluation($this->supplierId, $this->periodId, [], $this->rv(), $this->meta());

        $second = $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($this->periodId));
        self::assertSame($firstId, (int) $second['id'], 'In-place rewrite — stejné entry id (R6).');
        self::assertSame($firstDocNo, (string) $second['document_no'], 'Document_no se při rewrite zachovává.');
        $byCode = $this->linesByAccountCode($firstId);
        self::assertSame(self::cents(1500.00), self::cents($byCode['311']['debit']), 'Nová částka 1 000 × (26,00 − 24,50).');

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'fx_revaluation'"
        )->fetchColumn();
        self::assertSame(1, $count, 'Jediný FX zápis — žádný duplikát.');
    }

    // ── (c) peněžní účet 379 v EUR — přecenění z deníku ─────────────────────

    /**
     * Eurový faktoring: záloha od faktora 1 000 EUR kurzem 24,50 (MD 221 / D 379, obě
     * nohy s FX stopou) a vedle ní korunový faktoring na tomtéž 379. K 31. 12. při kurzu
     * 25,10 se závazek 379 v EUR zvýší o 600 Kč (ztráta), korunový pohyb se nepřeceňuje.
     * Re-run kroku zapíše totéž (in-place) a saldokonto přecenění banky ani 379 neotáčí.
     */
    public function testOtherLiability379InEurIsRevaluedFromJournalAndRerunIsIdempotent(): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 24500.00,
             'currency_code' => 'EUR', 'fx_rate' => 24.50, 'amount_foreign' => 1000.00],
            ['account_code' => '379', 'side' => 'credit', 'amount' => 24500.00,
             'currency_code' => 'EUR', 'fx_rate' => 24.50, 'amount_foreign' => 1000.00],
        ], ['entry_date' => self::YEAR . '-05-10', 'posted_by' => $this->userId]);
        $this->manual([
            self::l('221', 'debit', 10000.00),
            self::l('379', 'credit', 10000.00),
        ], self::YEAR . '-05-11');

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $preview = $this->closing->fxPreview($this->supplierId, $this->periodId);
        self::assertCount(1, $preview['ledger']['lines']);
        $line = $preview['ledger']['lines'][0];
        self::assertSame('379', $line['account_code']);
        self::assertSame(self::cents(-1000.00), self::cents($line['foreign_balance']));
        self::assertSame(self::cents(-600.00), self::cents($line['diff']));
        self::assertSame('loss', $line['direction']);

        foreach ([1, 2] as $run) {
            $this->closing->runFxRevaluation($this->supplierId, $this->periodId, [], $this->rv(), $this->meta());
            $slot2 = $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxBank($this->periodId));
            self::assertNotNull($slot2, "Běh {$run}: přecenění 379 jde do slotu banky.");
            $byCode = $this->linesByAccountCode((int) $slot2['id']);
            self::assertSame(self::cents(600.00), self::cents($byCode['379']['credit']), "Běh {$run}: D 379 600.");
            self::assertSame(self::cents(600.00), self::cents($byCode['563']['debit']), "Běh {$run}: MD 563 600.");
            self::assertNull(
                $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($this->periodId)),
                "Běh {$run}: saldokonto přecenění 379 neprotiúčtuje.",
            );
        }
    }

    /** Účet přeceňovaný z deníku nesmí přijít i v bank_rows — přecenil by se dvakrát. */
    public function testLedgerRevaluedAccountInBankRowsIsRejected(): void
    {
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        try {
            $this->closing->fxPreview(
                $this->supplierId,
                $this->periodId,
                [['account_code' => '379', 'currency_code' => 'EUR', 'foreign_balance' => -1000.00]],
            );
            self::fail('379 v bank_rows musí být odmítnut.');
        } catch (ClosingException $e) {
            self::assertSame('fx_ledger_account_in_bank_rows', $e->errorCode);
        }
    }

    /**
     * Přecenění banky ze slotu 2 se v saldokontu nesmí objevit jako „už zaúčtovaná úprava":
     * re-run kroku by jinak bankovní přecenění 221 protiúčtoval ve slotu saldokonta.
     */
    public function testRerunDoesNotCounterBookBankRevaluationInSaldoSlot(): void
    {
        $this->postForeignAccountMovement('221', 'debit', 2000.00, 50000.00, 25.00, self::YEAR . '-01-15');
        $bankRows = [['account_code' => '221', 'currency_code' => 'EUR', 'foreign_balance' => 2000.00]];

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($this->supplierId, $this->periodId, $bankRows, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($this->supplierId, $this->periodId, $bankRows, $this->rv(), $this->meta());

        self::assertNull(
            $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($this->periodId)),
            'Bez otevřených dokladů nevzniká zápis saldokonta ani při re-runu.',
        );
        $slot2 = $this->journal->findBySource($this->supplierId, 'fx_revaluation', ClosingSourceId::fxBank($this->periodId));
        self::assertNotNull($slot2);
        $byCode = $this->linesByAccountCode((int) $slot2['id']);
        self::assertSame(self::cents(200.00), self::cents($byCode['221']['debit']), '2 000 × 25,10 − 50 000.');
    }

    // ── R10b: bankProposals z deníku (oprava bugu s bank_statements per měnu) ──

    /**
     * Regrese hlavní chyby: dřív se návrh bral z currencies × poslední
     * bank_statements.curr_balance (jeden účet per měnu, k datu výpisu). Teď
     * musí vrátit VŠECHNY cizoměnové analytiky 211/221/261 se zůstatkem
     * z DENÍKU přesně k rozvahovému dni — vč. termínovaného vkladu bez
     * jakéhokoli bankovního výpisu (221800 TV EUR).
     */
    public function testR10bBankProposalsFromJournalBalancesAcrossAllCurrencyAccounts(): void
    {
        $parent221 = $this->accounts->findByCode($this->supplierId, '221');
        self::assertNotNull($parent221, 'Seeder musí vytvořit syntetický 221.');

        $mainId = $this->accounts->insert($this->supplierId, [
            'account_code' => '221100',
            'name'         => 'Běžný účet EUR',
            'account_type' => 'asset',
            'normal_side'  => 'debit',
            'is_synthetic' => false,
            'parent_id'    => $parent221['id'],
        ]);
        $tvId = $this->accounts->insert($this->supplierId, [
            'account_code' => '221800',
            'name'         => 'Termínovaný vklad EUR',
            'account_type' => 'asset',
            'normal_side'  => 'debit',
            'is_synthetic' => false,
            'parent_id'    => $parent221['id'],
        ]);
        unset($mainId, $tvId);

        // Vklad na běžný účet: MD 221100 24 000 EUR / 598 560 Kč (kurz dokladu 24,94).
        $this->postForeignAccountMovement('221100', 'debit', 24000.00, 598560.00, 24.94, self::YEAR . '-02-01');
        // Termínovaný vklad — žádný bank_statements řádek existuje jen v deníku.
        $this->postForeignAccountMovement('221800', 'debit', 10000.00, 249000.00, 24.90, self::YEAR . '-03-01');
        // Pohyb PO rozvahovém dni (v příštím účetním období) se do zůstatku k D nesmí počítat.
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->postForeignAccountMovement('221100', 'debit', 500.00, 12500.00, 25.00, (self::YEAR + 1) . '-01-10');

        $proposals = $this->closingRepo->bankProposals($this->supplierId, self::ENDS_ON);
        $byAccount = [];
        foreach ($proposals as $p) {
            $byAccount[$p['account_code']] = $p;
        }

        self::assertArrayHasKey('221100', $byAccount, 'Běžný EUR účet je v návrhu.');
        self::assertSame('EUR', $byAccount['221100']['currency_code']);
        self::assertSame(self::cents(24000.00), self::cents($byAccount['221100']['foreign_balance']), '24 000 EUR k D — pohyb z ledna, ne z příštího roku.');
        self::assertSame(self::cents(598560.00), self::cents($byAccount['221100']['czk_balance']));

        self::assertArrayHasKey('221800', $byAccount, 'Termínovaný vklad bez bankovního výpisu musí být v návrhu (hlavní bug).');
        self::assertSame(self::cents(10000.00), self::cents($byAccount['221800']['foreign_balance']));
        self::assertSame(self::cents(249000.00), self::cents($byAccount['221800']['czk_balance']));

        // fxPreview musí proposals rovnou obsahovat account_code (FE se jím předvyplní).
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $preview = $this->closing->fxPreview($this->supplierId, $this->periodId);
        $previewCodes = array_column($preview['proposals'], 'account_code');
        self::assertContains('221100', $previewCodes);
        self::assertContains('221800', $previewCodes);
    }

    /**
     * @param 'debit'|'credit' $side
     */
    private function postForeignAccountMovement(
        string $accountCode,
        string $side,
        float $amountForeign,
        float $amountCzk,
        float $fxRate,
        string $entryDate,
    ): int {
        $counterSide = $side === 'debit' ? 'credit' : 'debit';
        return $this->posting->postDocument($this->supplierId, 'manual', null, [
            [
                'account_code'   => $accountCode,
                'side'           => $side,
                'amount'         => $amountCzk,
                'currency_code'  => 'EUR',
                'fx_rate'        => $fxRate,
                'amount_foreign' => $amountForeign,
            ],
            self::l('411', $counterSide, $amountCzk),
        ], [
            'entry_date' => $entryDate,
            'posted_by'  => $this->userId,
            'allow_closing_period' => true,
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * EUR faktura bez DPH (základ = celkem v EUR) s kurzem dokladu — saldokontní
     * řádek 311 nese currency/fx_rate/amount_foreign (1008 withForeign).
     */
    private function postEurInvoice(
        float $totalEur,
        float $docRate,
        string $invoiceType = 'invoice',
        string $varsymbol = 'FV-2098-EUR-1',
    ): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "EU Odběratel", "Test 1", "Praha", "11000", ?, "CZ12345678", "eu@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $this->czId, $this->eurId]);
        $clientId = (int) $pdo->lastInsertId();

        $issue = self::YEAR . '-06-15';
        $stmt = $pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0.00, ?, "issued", "1", ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $invoiceType, $clientId, $issue, $issue, $issue,
            $this->eurId, $docRate, $totalEur, $totalEur, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Služby", 1, "ks", ?, ?, 0, ?, 0.00, ?, 0)'
        )->execute([$invoiceId, $totalEur, $this->vatRateId, $totalEur, $totalEur]);

        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        if ($totalEur < 0.0) {
            foreach ($lines as &$line) {
                $line['side'] = $line['side'] === 'debit' ? 'credit' : 'debit';
                $line['is_red_storno'] = true;
            }
            unset($line);
        }
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, [
            'entry_date' => $issue,
            'posted_by' => $this->userId,
        ]);
        return $invoiceId;
    }

    /**
     * start → precheck → skip kroků → FX → closeBooks (stav closed).
     *
     * @param list<array{account_code:string, currency_code:string, foreign_balance:float}> $bankRows
     */
    private function runChainToClosed(array $bankRows): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, $bankRows, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        $this->completeInventory($sid, $pid, $this->userId);
        $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta());
    }

    /** EP-6: dokončí inventarizaci rozvahových účtů (skutečný = účetní → resolved), aby closeBooks neblokoval. */
    private function completeInventory(int $sid, int $pid, ?int $uid): void
    {
        $rv = (int) $this->periods->findById($sid, $pid)['row_version'];
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $rv, ['complete' => true], $items, ['user_id' => $uid]);
    }

    private function seedRate(string $date, string $code, float $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, $code, $rate]);
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function manual(array $lines, string $date): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, $lines, [
            'entry_date' => $date,
            'posted_by' => $this->userId,
        ]);
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    /**
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = (string) $r['account_code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $r['side']] += (float) $r['amount'];
        }
        return $out;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
