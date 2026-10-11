<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard action item „Zaúčtuj doklady" (type=unbooked_documents):
 *   - počítá vystavené (FV) + přijaté (PF) doklady s booked_at IS NULL,
 *     jen finalizované (status NOT IN draft/cancelled), FV navíc jen daňové typy;
 *   - JEN pro firmy v podvojném účetnictví (accounting_mode='double_entry');
 *   - v daňové evidenci se položka NEzobrazí (booked_at tam nefunguje).
 *
 * Doklady jsou datované DNES a čísla se ověřují DELTou proti baseline reálné DB.
 * accounting_mode/is_vat_payer prvního dodavatele se v tearDown vrací zpět.
 * Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class CrmUnbookedDocumentsTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private ?string $origMode = null;
    private int $createdClientId = 0;
    /** @var int[] */
    private array $createdInvoices = [];
    /** @var int[] */
    private array $createdPurchases = [];
    /** @var int[] */
    private array $createdSuggestions = [];
    /** @var int[] */
    private array $createdTransactions = [];
    /** @var int[] */
    private array $createdJournalEntries = [];
    private int $createdStatementId = 0;
    private int $createdCurrencyId = 0;
    private int $createdPeriodId = 0;
    private string $today;
    private int $seq = 0;

    /** Účet, který si test seedí do currencies i na výpis, ať pohyby patří testované firmě. */
    private const TEST_ACCOUNT = '123456789';
    private const TEST_BANK_CODE = '0100';

    protected function setUp(): void
    {
        $this->today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí CZK currency/user.');
        }
        // Klienta si test seedí sám — čistá testovací DB žádného nemá a podmíněný skip
        // znamenal, že tenhle test nikdy nedoběhl. Maže se v tearDown.
        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->clientId === 0) {
            $countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->supplierId, 'CRM Unbooked Test s.r.o.', 'Testovací 1', 'Praha', '11000',
                $countryId, 'crm-unbooked-test@example.invalid', $this->currencyId,
            ]);
            $this->clientId = (int) $pdo->lastInsertId();
            $this->createdClientId = $this->clientId;
        }
        $this->origMode = (string) ($pdo->query("SELECT accounting_mode FROM supplier WHERE id = {$this->supplierId}")->fetchColumn() ?: 'tax_evidence');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            foreach ($this->createdInvoices as $id) {
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            }
            foreach ($this->createdPurchases as $id) {
                $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
            }
            foreach ($this->createdSuggestions as $id) {
                $pdo->prepare('DELETE FROM bank_posting_suggestions WHERE id = ?')->execute([$id]);
            }
            foreach ($this->createdJournalEntries as $id) {
                $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$id]);
            }
            $this->createdJournalEntries = [];
            foreach ($this->createdTransactions as $id) {
                $pdo->prepare('DELETE FROM bank_transactions WHERE id = ?')->execute([$id]);
            }
            if ($this->createdStatementId > 0) {
                $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$this->createdStatementId]);
                $this->createdStatementId = 0;
            }
            if ($this->createdCurrencyId > 0) {
                $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->createdCurrencyId]);
                $this->createdCurrencyId = 0;
            }
            if ($this->createdPeriodId > 0) {
                $pdo->prepare('DELETE FROM accounting_periods WHERE id = ?')->execute([$this->createdPeriodId]);
                $this->createdPeriodId = 0;
            }
            $this->createdSuggestions = [];
            $this->createdTransactions = [];
            $this->createdInvoices = [];
            $this->createdPurchases = [];
            if ($this->createdClientId > 0) {
                $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->createdClientId]);
                $this->createdClientId = 0;
            }
            if ($this->origMode !== null && $this->supplierId > 0) {
                $pdo->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')
                    ->execute([$this->origMode, $this->supplierId]);
            }
        }
    }

    private function setMode(string $mode): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')
            ->execute([$mode, $this->supplierId]);
    }

    private function insertInvoice(string $type, string $status, ?string $bookedAt): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, booked_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 121, ?, ?)"
        )->execute([
            $type, 'CRMUNB' . (++$this->seq), $this->clientId, $this->supplierId,
            $this->today, $this->today, $this->today, $this->currencyId, $status, $bookedAt, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->createdInvoices[] = $id;
        return $id;
    }

    private function insertPurchase(string $status, ?string $bookedAt, string $documentKind = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, issue_date, tax_date, due_date,
                 received_at, currency_id, vendor_snapshot, status, document_kind, total_without_vat, total_with_vat, booked_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '{}', ?, ?, 100, 121, ?, ?)"
        )->execute([
            $this->supplierId, $this->clientId, 'CRMUNBP' . (++$this->seq), 'CRMUNBP' . $this->seq,
            $this->today, $this->today, $this->today, $this->today, $this->currencyId, $status, $documentKind,
            $bookedAt, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->createdPurchases[] = $id;
        return $id;
    }

    /** Pending návrh zaúčtování nad transakcí daného zdroje (statement / email_notice). */
    private function insertPendingSuggestion(string $txSource, string $matchStatus = 'unmatched'): int
    {
        $pdo = $this->db->pdo();
        if ($this->createdStatementId === 0) {
            // Vlastnictví pohybu se odvozuje shodou bs.account_number ↔ currencies.account_number
            // (bank_transactions nemá supplier_id). Bez účtu v currencies by pohyb do fronty
            // nepatřil žádné firmě — proto si test účet seedí, jako ho má reálná firma.
            $pdo->prepare(
                "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
                 VALUES (?, 'CZK', 'CRM test účet', 'Kč', 'Koruna', 'Koruna', ?, ?)"
            )->execute([$this->supplierId, self::TEST_ACCOUNT, self::TEST_BANK_CODE]);
            $this->createdCurrencyId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->supplierId, 'crm-unbooked-test.gpc', str_repeat('c', 64),
                self::TEST_ACCOUNT, self::TEST_BANK_CODE, $this->today,
            ]);
            $this->createdStatementId = (int) $pdo->lastInsertId();
        }
        // bank_transactions nemá supplier_id — tenant se odvozuje přes statement_id.
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, source, match_status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->createdStatementId, $this->today, 100.00, $txSource, $matchStatus]);
        $txId = (int) $pdo->lastInsertId();
        $this->createdTransactions[] = $txId;

        $pdo->prepare(
            "INSERT INTO bank_posting_suggestions
                (supplier_id, bank_transaction_id, source, debit_account_code, credit_account_code, amount, status)
             VALUES (?, ?, 'rule', '221', '602', 100.00, 'pending')"
        )->execute([$this->supplierId, $txId]);
        $id = (int) $pdo->lastInsertId();
        $this->createdSuggestions[] = $id;
        return $id;
    }

    /** Živý zápis v deníku nad bankovním pohybem — přesně to, co fronta bere jako „zaúčtováno". */
    private function insertBankJournalEntry(int $txId): int
    {
        $pdo = $this->db->pdo();
        $year = (int) (new \DateTimeImmutable($this->today))->format('Y');
        $periodId = (int) ($pdo->query(
            "SELECT id FROM accounting_periods
              WHERE supplier_id = {$this->supplierId} AND fiscal_year = {$year} LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($periodId === 0) {
            // Čistá testovací DB žádné období nemá — seedni ho stejně jako klienta výše.
            $pdo->prepare(
                "INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
                 VALUES (?, ?, ?, ?, 'open')"
            )->execute([$this->supplierId, $year, "{$year}-01-01", "{$year}-12-31"]);
            $periodId = (int) $pdo->lastInsertId();
            $this->createdPeriodId = $periodId;
        }
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id)
             VALUES (?, ?, ?, 'bank', ?)"
        )->execute([$this->supplierId, $periodId, $this->today, $txId]);
        $id = (int) $pdo->lastInsertId();
        $this->createdJournalEntries[] = $id;
        return $id;
    }

    /** Vrátí položku unbooked_documents (nebo null), userId=null → bez dismissals. */
    private function unbookedItem(): ?array
    {
        $res = $this->crm->actionItems($this->supplierId, null);
        foreach ($res['items'] as $item) {
            if ($item['type'] === 'unbooked_documents') {
                return $item;
            }
        }
        return null;
    }

    /** Přímý COUNT dle stejné WHERE logiky (booked_at IS NULL) — kontrolní hodnota. */
    private function sqlUnbookedCounts(): array
    {
        $pdo = $this->db->pdo();
        $inv = (int) $pdo->query(
            "SELECT COUNT(*) FROM invoices WHERE supplier_id = {$this->supplierId}
               AND booked_at IS NULL AND status NOT IN ('draft','cancelled')
               AND invoice_type IN ('invoice','credit_note','tax_document','penalty')"
        )->fetchColumn();
        $pi = (int) $pdo->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = {$this->supplierId}
               AND booked_at IS NULL AND status NOT IN ('draft','cancelled')
               AND COALESCE(document_kind, 'invoice') <> 'advance'"
        )->fetchColumn();
        return ['invoices' => $inv, 'purchase_invoices' => $pi];
    }

    public function testPocetSediSWherebookedAtIsNull(): void
    {
        $this->setMode('double_entry');
        $base = $this->unbookedItem();
        $baseCount = $base['count'] ?? 0;

        // 2 nezaúčtované (booked_at NULL) FV + 1 zaúčtovaná (booked_at set, nesmí se počítat)
        $this->insertInvoice('invoice', 'issued', null);
        $this->insertInvoice('invoice', 'sent', null);
        $this->insertInvoice('invoice', 'paid', $this->today . ' 10:00:00');
        // 1 nezaúčtovaná PF + 1 zaúčtovaná PF
        $this->insertPurchase('received', null);
        $this->insertPurchase('booked', $this->today . ' 10:00:00');
        // Šum, který se počítat NESMÍ: koncept FV, stornovaná FV, proforma
        $this->insertInvoice('invoice', 'draft', null);
        $this->insertInvoice('invoice', 'cancelled', null);
        $this->insertInvoice('proforma', 'issued', null);

        $item = $this->unbookedItem();
        self::assertNotNull($item, 'Položka unbooked_documents se v podvojném účetnictví zobrazí.');
        // +2 FV a +1 PF nezaúčtované = +3 proti baseline.
        self::assertSame($baseCount + 3, $item['count'], 'Count = FV+PF+banka s booked_at IS NULL (bez konceptů/storno/proforem/zaúčtovaných).');

        // Count musí sedět se součtem přímého SQL (FV+PF) + banka z breakdownu.
        $sql = $this->sqlUnbookedCounts();
        $bank = 0;
        foreach ($item['breakdown'] as $b) {
            if ($b['key'] === 'bank') {
                $bank = (int) $b['count'];
            }
        }
        self::assertSame($sql['invoices'] + $sql['purchase_invoices'] + $bank, $item['count'],
            'Count agregace = přímý COUNT(booked_at IS NULL) FV+PF + pending banka.');

        // Breakdown obsahuje FV i PF prokliky na ?booked=0.
        $byKey = [];
        foreach ($item['breakdown'] as $b) {
            $byKey[$b['key']] = $b;
        }
        self::assertArrayHasKey('invoices', $byKey);
        self::assertArrayHasKey('purchase_invoices', $byKey);
        self::assertSame('/invoices?booked=0', $byKey['invoices']['link']);
        self::assertSame('/purchase-invoices?booked=0', $byKey['purchase_invoices']['link']);
        self::assertSame($sql['invoices'], (int) $byKey['invoices']['count']);
        self::assertSame($sql['purchase_invoices'], (int) $byKey['purchase_invoices']['count']);
    }

    /**
     * Regrese: karta počítala i zálohové přijaté faktury, ale seznam je pod `?booked=0`
     * nikdy neukáže (PurchaseInvoiceRepository je vylučuje shodně s PostingService —
     * záloha se neúčtuje). Proklik pak končil na prázdném seznamu.
     */
    public function testZalohovePrijateFakturySeNepocitaji(): void
    {
        $this->setMode('double_entry');
        $base = $this->unbookedItem();
        $baseCount = $base['count'] ?? 0;

        $this->insertPurchase('received', null, 'advance');
        $this->insertPurchase('received', null, 'advance');

        $item = $this->unbookedItem();
        self::assertSame($baseCount, $item['count'] ?? 0,
            'Zálohové přijaté faktury se do fronty „k zaúčtování" počítat nesmí.');

        // Kontrola proti seznamu: běžná PF se počítá, záloha ne.
        $this->insertPurchase('received', null, 'invoice');
        $item = $this->unbookedItem();
        self::assertSame($baseCount + 1, $item['count'] ?? 0,
            'Běžná přijatá faktura frontu zvýší, záloha ne.');
    }

    /**
     * Regrese: bankovní fronta počítala i návrhy nad e-mailovým avízem a nad ignorovanou
     * transakcí. Obojí BankPostingService::post() odmítne (avízo je provizorní duplikát,
     * doúčtuje se až GPC transakce) — karta tedy nabízela akci, která skončí „skipped".
     */
    public function testBankovniFrontaVynechavaAvizoAIgnorovane(): void
    {
        $this->setMode('double_entry');
        $base = $this->unbookedItem();
        $baseCount = $base['count'] ?? 0;

        $this->insertPendingSuggestion('email_notice');
        $this->insertPendingSuggestion('statement', 'ignored');

        $item = $this->unbookedItem();
        self::assertSame($baseCount, $item['count'] ?? 0,
            'Návrhy nad avízem / ignorovanou transakcí se do fronty počítat nesmí.');

        // Kontrola opačným směrem: návrh nad řádným výpisem frontu zvýšit musí.
        $this->insertPendingSuggestion('statement');
        $item = $this->unbookedItem();
        self::assertSame($baseCount + 1, $item['count'] ?? 0,
            'Návrh nad řádným výpisem frontu zvýší.');
    }

    /**
     * Regrese (ostrá data): karta počítala pending návrhy, jenže stav fronty se od reality
     * rozchází — 88 návrhů viselo na `pending` k pohybům, které UŽ měly živý zápis v deníku.
     * Karta hlásila 88, tab „Nezaúčtované pohyby" pod prokliknutím poctivě 0. Autoritativní
     * je existence zápisu, ne stav návrhu.
     */
    public function testZauctovanyPohybSVisicimNavrhemSeNepocita(): void
    {
        $this->setMode('double_entry');
        $base = $this->unbookedItem();
        $baseCount = $base['count'] ?? 0;

        // Pohyb s pending návrhem frontu zvýší...
        $this->insertPendingSuggestion('statement');
        $txId = $this->createdTransactions[array_key_last($this->createdTransactions)];
        $item = $this->unbookedItem();
        self::assertSame($baseCount + 1, $item['count'] ?? 0, 'Nezaúčtovaný pohyb frontu zvýší.');

        // ...ale jakmile je zaúčtovaný, z fronty zmizí, i když návrh zůstal viset na 'pending'.
        $this->insertBankJournalEntry($txId);
        $item = $this->unbookedItem();
        self::assertSame($baseCount, $item['count'] ?? 0,
            'Zaúčtovaný pohyb se počítat nesmí ani s visícím pending návrhem.');
    }

    public function testDanovaEvidenceNezobrazuje(): void
    {
        $this->setMode('tax_evidence');
        // I s nezaúčtovanými doklady se položka v daňové evidenci nesmí objevit.
        $this->insertInvoice('invoice', 'issued', null);
        $this->insertPurchase('received', null);

        self::assertNull($this->unbookedItem(), 'V daňové evidenci se unbooked_documents nezobrazuje.');
    }

    /**
     * Issue #146: právnická osoba je v podvojném režimu od založení, ale bez licence
     * se doklady neúčtují a Účetnictví je zamčené. Karta by vybízela k práci, kterou
     * uživatel udělat nemůže, a skrytí na týden ji jen odložilo.
     */
    public function testBezLicenceNezobrazuje(): void
    {
        $this->setMode('double_entry');
        $this->insertInvoice('invoice', 'issued', null);
        $this->insertPurchase('received', null);

        $pdo = $this->db->pdo();
        $original = $pdo->query('SELECT license_key, token, token_payload, trial_started_at FROM license WHERE id = 1')
            ->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($original)) {
            self::markTestSkipped('Chybí řádek licence.');
        }
        try {
            $pdo->prepare(
                'UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL, trial_started_at = ? WHERE id = 1'
            )->execute([date('Y-m-d H:i:s', time() - 90 * 86400)]);
            $license = new LicenseService(
                $this->db,
                new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
                new LicenseTokenVerifier(),
                $this->createStub(LicenseClient::class),
            );
            self::assertFalse($license->current()->hasCommercialFeatures(), 'Licenci se nepodařilo vypnout.');
            $this->crm = new CrmAggregationService($this->db, license: $license, cache: EntityCache::disabled());

            self::assertNull($this->unbookedItem(), 'Bez licence se unbooked_documents nezobrazuje.');
        } finally {
            $pdo->prepare(
                'UPDATE license SET license_key = ?, token = ?, token_payload = ?, trial_started_at = ? WHERE id = 1'
            )->execute([$original['license_key'], $original['token'], $original['token_payload'], $original['trial_started_at']]);
        }
    }
}
