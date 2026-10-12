<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Sample;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Sample\SampleDataGenerator;
use MyInvoice\Service\Sample\SampleDataService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Evidence + přesné odebrání ukázkových dat (issue #162) end-to-end:
 *
 *   1. generate() vytvoří sample a každou kořenovou entitu zaeviduje (sample_data_entries).
 *   2. guard: druhý generate nad neprázdnou DB selže (žádné duplicity / pád na UNIQUE).
 *   3. purge() smaže celou sample dávku FK-bezpečně (děti kaskádou, projects bez supplier_id).
 *
 * Izolace: throwaway dodavatel s vlastními měnami, úklid v tearDown. Soft-skip bez cfg.php/DB.
 */
#[Group('integration')]
final class SampleDataPurgeTest extends TestCase
{
    private Connection $db;
    private BankPostingService $bankPosting;
    private SampleDataGenerator $generator;
    private SampleDataService $service;

    private int $supplierId = 0;
    private int $adminId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db        = $c->get(Connection::class);
            $this->bankPosting = $c->get(BankPostingService::class);
            $this->generator = $c->get(SampleDataGenerator::class);
            $this->service   = $c->get(SampleDataService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->adminId = (int) $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.system_key = 'superadmin' AND r.is_active = 1 AND u.is_active = 1
              ORDER BY u.id LIMIT 1"
        )->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $vatId = (int) $pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn();
        $anyCur = (int) $pdo->query("SELECT id FROM currencies LIMIT 1")->fetchColumn();
        if (!$this->adminId || !$countryId || !$vatId || !$anyCur) {
            $this->markTestSkipped('Chybí předpoklady (admin/country/vat/currency).');
        }

        // Throwaway dodavatel + CZK/EUR měny (generátor je řeší per supplier_id+code).
        $pdo->prepare(
            "INSERT INTO supplier
                (company_name, ic, street, city, zip, country_id, email, default_currency_id,
                 default_vat_rate_id, taxpayer_type, is_vat_payer)
             VALUES ('__SAMPLE_PURGE_TEST__ s.r.o.','00000019','Test 1','Praha','11000', ?,
                     'samplepurge@example.invalid', ?, ?, 'po', 1)"
        )->execute([$countryId, $anyCur, $vatId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $insCur = $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?,?,?,?,?,?,2,1,?)"
        );
        $insCur->execute([$this->supplierId, 'CZK', 'Kč', 'Kč', 'koruna', 'crown', 1]);
        $czk = (int) $pdo->lastInsertId();
        $insCur->execute([$this->supplierId, 'EUR', 'EUR', '€', 'euro', 'euro', 0]);
        $pdo->prepare("UPDATE supplier SET default_currency_id=? WHERE id=?")->execute([$czk, $this->supplierId]);
    }

    protected function tearDown(): void
    {
        if ($this->supplierId <= 0 || !isset($this->db)) return;
        $pdo = $this->db->pdo();
        try { $this->service->purge($this->supplierId); } catch (\Throwable) {}

        // Když purge() selže (nebo ho test schválně nedoběhne), zůstanou doklady viset.
        // Následující sweep je maže se ZAPNUTOU FK kontrolou, aby doběhly kaskády na
        // položky, a hlavně DŘÍV než se níž s vypnutou kontrolou smažou `clients`
        // a `currencies` — jinak tu po běhu zůstanou osiřelé přijaté faktury bez
        // dodavatele i měny (naměřeno: 120 řádků, které pak shazovaly export testy).
        foreach ([
            'purchase_invoices',
            'invoices',
            'purchase_orders',
            'recurring_invoice_templates',
            'projects',
            'offset_agreements',
        ] as $table) {
            try {
                $pdo->prepare("DELETE FROM `$table` WHERE supplier_id=?")->execute([$this->supplierId]);
            } catch (\Throwable) {
                // Tabulka v tomhle schématu být nemusí — úklid pokračuje dál.
            }
        }

        // POZOR: `SET FOREIGN_KEY_CHECKS=0` nevypne jen KONTROLU, ale i ON DELETE CASCADE.
        // Dřívější verze mazala supplier s vypnutými kontrolami, takže kaskáda nikdy
        // neproběhla a po každém běhu tu zůstalo ~220 osiřelých řádků účtové osnovy,
        // ~132 ai_jobs, číselné řady, období… Za 28 běhů se jich v ostré DB nasbíralo
        // přes 12 000 a shazovaly migrace, které do chart_of_accounts insertují.
        //
        // Správný postup (týž jako SettingsAction::deleteSupplier): FK kontrolu vypnout
        // JEN na tabulky s RESTRICT FK, které by DELETE zablokovaly, a vlastní
        // `DELETE FROM supplier` provést se ZAPNUTOU kontrolou, aby kaskáda doběhla.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ([
                'sample_data_entries',
                'supplier_bank_accounts',
                'invoice_counters',
                'purchase_invoice_counters',
                'clients',
                'activity_log',
                'currencies',
            ] as $table) {
                $pdo->prepare("DELETE FROM `$table` WHERE supplier_id=?")->execute([$this->supplierId]);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplierId]);
    }

    public function testGenerateTracksAndPurgeRemovesEverything(): void
    {
        $pdo = $this->db->pdo();

        $r = $this->generator->generate($this->supplierId, $this->adminId);
        self::assertSame(24, $r['clients']);
        self::assertSame(12, $r['vendors']);
        self::assertSame(120, $r['invoices']);
        self::assertSame(120, $r['purchase_invoices']);
        self::assertSame(120, $r['stock_documents']);
        self::assertSame(2, $r['assets']);
        self::assertSame(6, $r['bank_statements']);
        self::assertSame(233, $r['bank_transactions']);
        self::assertSame(4, $r['drafts']);
        self::assertSame(4, $r['expense_rules']);
        self::assertSame(3, $r['bank_posting_rules']);
        self::assertSame(5, $r['dimension_types']);
        self::assertSame(3, $r['dimension_values']);
        self::assertSame(263, $r['dimension_documents']);
        self::assertGreaterThan(0, $r['dimension_journal_lines']);
        self::assertSame(1, (int) $pdo->query("SELECT dimensions_enabled FROM supplier WHERE id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM document_dimensions d JOIN dimension_values v ON v.id=d.dimension_value_id JOIN dimension_types t ON t.id=d.dimension_type_id JOIN invoices i ON i.id=d.doc_id WHERE d.supplier_id={$this->supplierId} AND d.doc_type='invoice' AND t.kind='project' AND NOT (i.project_id <=> v.project_id)")->fetchColumn());
        self::assertSame(4, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE supplier_id={$this->supplierId} AND status='draft'")->fetchColumn());
        self::assertSame(4, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE supplier_id={$this->supplierId} AND invoice_type='invoice' AND status<>'draft' AND paid_total=0")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE supplier_id={$this->supplierId} AND invoice_type='invoice' AND paid_total>0 AND paid_total<amount_to_pay-0.01")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id JOIN invoices i ON i.id=bt.matched_invoice_id WHERE bs.supplier_id={$this->supplierId} AND i.paid_total>0 AND i.paid_total<i.amount_to_pay-0.01 AND bt.match_status='auto_partial'")->fetchColumn());
        self::assertSame(116, (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id={$this->supplierId} AND status='paid'")->fetchColumn());
        self::assertSame(12, (int) $pdo->query("SELECT COUNT(*) FROM credit_note_offsets WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoices i LEFT JOIN projects p ON p.id=i.project_id WHERE i.supplier_id={$this->supplierId} AND i.status='draft' AND (p.id IS NULL OR p.client_id<>i.client_id OR i.varsymbol IS NOT NULL OR i.booked_at IS NOT NULL)")->fetchColumn());
        self::assertSame(4, $r['payroll_persons']);
        self::assertSame(4, $r['payroll_runs']);
        self::assertSame(12, $r['payroll_results']);
        self::assertSame(3, $r['payroll_posting_batches']);
        self::assertSame(216, $r['automation_auto']);
        self::assertSame(6, $r['automation_pending']);
        self::assertSame(1, $r['automation_needs_input']);
        self::assertSame(1, $r['automation_approved']);
        self::assertSame(1, $r['cash_registers']);
        self::assertSame(7, $r['cash_documents']);
        self::assertSame(3, $r['manufacturers']);
        self::assertSame(3, $r['eshop_categories']);
        self::assertGreaterThanOrEqual(375, $r['journal_entries']);
        self::assertTrue($r['accounting_enabled']);
        self::assertSame([], $r['warnings']);
        self::assertSame(3, $r['documents']);
        $pdfFiles = $pdo->query(
            "SELECT sha256,filename FROM documents WHERE supplier_id={$this->supplierId}"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(3, $pdfFiles);
        foreach ($pdfFiles as $file) {
            $path = (new \MyInvoice\Service\Document\DocumentStorage(
                Bootstrap::buildContainer()->get(\MyInvoice\Infrastructure\Config\Config::class),
            ))->pathFor($this->supplierId, $file['sha256'], $file['filename']);
            self::assertFileExists($path);
            self::assertSame('%PDF', file_get_contents($path, false, null, 0, 4));
        }
        self::assertSame(3, (int) $pdo->query(
            "SELECT COUNT(DISTINCT document_id) FROM document_links WHERE supplier_id={$this->supplierId} AND entity_type='journal_entry'"
        )->fetchColumn());

        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM stock_documents sd
              LEFT JOIN invoices i ON i.id=sd.invoice_id
              LEFT JOIN purchase_invoices p ON p.id=sd.purchase_invoice_id
             WHERE sd.supplier_id={$this->supplierId}
               AND sd.doc_date <> COALESCE(i.issue_date,p.issue_date)"
        )->fetchColumn(), 'Skladový pohyb musí odpovídat datu zdrojového dokladu.');
        self::assertSame(12, (int) $pdo->query(
            "SELECT COUNT(DISTINCT DATE_FORMAT(doc_date,'%Y-%m')) FROM stock_documents
              WHERE supplier_id={$this->supplierId} AND doc_type='issue'"
        )->fetchColumn(), 'Skladové prodeje mají pokrývat všech dvanáct měsíců.');
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id={$this->supplierId}
              AND SUBSTRING(varsymbol,3,4)<>DATE_FORMAT(issue_date,'%y%m')"
        )->fetchColumn(), 'Číselná řada nákupu musí odpovídat konečnému datu dokladu.');

        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_transactions bt
              JOIN bank_statements bs ON bs.id=bt.statement_id
              JOIN invoices i ON i.id=bt.matched_invoice_id
              JOIN clients c ON c.id=i.client_id
             WHERE bs.supplier_id={$this->supplierId}
               AND (bt.posted_at < i.issue_date OR bt.counterparty_name <> c.company_name)"
        )->fetchColumn(), 'Úhrada musí navazovat na datum a odběratele faktury.');

        $tracked = (int) $pdo->query("SELECT COUNT(*) FROM sample_data_entries WHERE supplier_id={$this->supplierId}")->fetchColumn();

        $sum = $this->service->summary($this->supplierId);
        self::assertTrue($sum['has']);
        self::assertSame($tracked, $sum['total']);
        self::assertSame(124, $sum['counts']['invoice']);
        self::assertSame($r['journal_entries'], $sum['counts']['journal_entry']);

        self::assertSame(36, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id={$this->supplierId}")->fetchColumn());
        $supplierFlags = $pdo->query(
            "SELECT accounting_mode, stock_enabled FROM supplier WHERE id={$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('double_entry', $supplierFlags['accounting_mode']);
        self::assertSame(1, (int) $supplierFlags['stock_enabled']);
        $accountingFrom = (string) $pdo->query(
            "SELECT MIN(effective_from) FROM supplier_accounting_modes WHERE supplier_id={$this->supplierId}"
        )->fetchColumn();
        $oldestDocument = (string) $pdo->query(
            "SELECT MIN(document_date) FROM (
                SELECT issue_date AS document_date FROM invoices WHERE supplier_id={$this->supplierId}
                UNION ALL
                SELECT issue_date FROM purchase_invoices WHERE supplier_id={$this->supplierId}
            ) sample_documents"
        )->fetchColumn();
        self::assertLessThanOrEqual($oldestDocument, $accountingFrom);
        self::assertSame(
            ['1', '2'],
            $pdo->query("SELECT CAST(tax_group AS CHAR) FROM assets WHERE supplier_id={$this->supplierId} ORDER BY tax_group")
                ->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame(104, (int) $pdo->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE supplier_id={$this->supplierId} AND bank_transaction_id IS NOT NULL"
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM supplier_bank_accounts WHERE supplier_id={$this->supplierId}"
        )->fetchColumn());
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending'
                AND operation_type='bank.payment.matched' AND debit_account_code='221' AND credit_account_code='311'"
        )->fetchColumn());
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending'
                AND operation_type='bank.payment.matched' AND debit_account_code='321' AND credit_account_code='221'"
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending'
                AND operation_type='bank.fee' AND debit_account_code='568' AND credit_account_code='221'"
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending'
                AND operation_type='bank.interest' AND debit_account_code='221' AND credit_account_code='662'"
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions bps
               JOIN journal_entries je
                 ON je.supplier_id=bps.supplier_id
                AND je.id=CAST(SUBSTRING_INDEX(bps.note, '#', -1) AS UNSIGNED)
              WHERE bps.supplier_id={$this->supplierId} AND bps.status='needs_input'
                AND bps.note LIKE 'duplicate_suspect:#%' AND je.source_type='manual'"
        )->fetchColumn());
        $pendingSuggestionIds = array_map('intval', $pdo->query(
            "SELECT id FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(6, $pendingSuggestionIds);
        foreach ($pendingSuggestionIds as $suggestionId) {
            $preview = $this->bankPosting->previewSuggestion($this->supplierId, $suggestionId);
            self::assertCount(2, $preview['lines']);
        }
        self::assertGreaterThan(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id={$this->supplierId}"
        )->fetchColumn());
        self::assertNull($pdo->query(
            "SELECT account_number FROM currencies WHERE supplier_id={$this->supplierId} AND code='CZK'"
        )->fetchColumn());
        self::assertSame(7, (int) $pdo->query(
            "SELECT COUNT(*) FROM cash_documents WHERE supplier_id={$this->supplierId} AND status='posted' AND journal_entry_id IS NOT NULL"
        )->fetchColumn());
        self::assertGreaterThan(0.0, (float) $pdo->query(
            "SELECT SUM(CASE WHEN doc_type='in' THEN total_amount ELSE -total_amount END)
               FROM cash_documents WHERE supplier_id={$this->supplierId}"
        )->fetchColumn());
        self::assertSame(12, (int) $pdo->query(
            "SELECT COUNT(*) FROM stock_items
              WHERE supplier_id={$this->supplierId} AND manufacturer_id IS NOT NULL AND export_eshop=1"
        )->fetchColumn());
        self::assertSame(12, (int) $pdo->query(
            "SELECT COUNT(*) FROM stock_item_categories WHERE supplier_id={$this->supplierId} AND is_primary=1"
        )->fetchColumn());

        $unbalanced = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT je.id,
                       SUM(CASE WHEN jel.side='debit' THEN jel.amount ELSE 0 END) md,
                       SUM(CASE WHEN jel.side='credit' THEN jel.amount ELSE 0 END) d
                  FROM journal_entries je
                  JOIN journal_entry_lines jel ON jel.entry_id=je.id AND jel.supplier_id=je.supplier_id
                 WHERE je.supplier_id={$this->supplierId}
              GROUP BY je.id
                HAVING ROUND(md,2) <> ROUND(d,2)
            ) x"
        )->fetchColumn();
        self::assertSame(0, $unbalanced);

        foreach ($pendingSuggestionIds as $suggestionId) {
            $this->bankPosting->approveSuggestion($this->supplierId, $suggestionId, ['user_id' => $this->adminId, 'posted_by' => $this->adminId]);
        }
        $bankBalance = (float) $pdo->query(
            "SELECT SUM(CASE WHEN l.side='debit' THEN l.amount ELSE -l.amount END)
               FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id
               JOIN chart_of_accounts a ON a.id=l.account_id
              WHERE e.supplier_id={$this->supplierId} AND (a.account_code='221' OR a.account_code LIKE '221.%') AND e.reversed_by IS NULL"
        )->fetchColumn();
        self::assertEqualsWithDelta((float) $pdo->query(
            "SELECT curr_balance FROM bank_statements WHERE supplier_id={$this->supplierId} ORDER BY statement_date DESC,id DESC LIMIT 1"
        )->fetchColumn(), $bankBalance, 0.005, 'Po schválení fronty musí banka souhlasit s hlavní knihou.');
        self::assertEqualsWithDelta(0, (float) $pdo->query(
            "SELECT SUM(CASE WHEN l.side='debit' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id=l.account_id
              WHERE l.supplier_id={$this->supplierId} AND (a.account_code='261' OR a.account_code LIKE '261.%')"
        )->fetchColumn(), 0.005, 'Převod mezi bankou a pokladnou musí mít obě strany.');

        $postedIds = $pdo->query("SELECT id FROM journal_entries WHERE supplier_id={$this->supplierId}")->fetchAll(PDO::FETCH_COLUMN);

        $container = Bootstrap::buildContainer();
        $documentRepo = $container->get(\MyInvoice\Repository\DocumentRepository::class);
        $documentStorage = $container->get(\MyInvoice\Service\Document\DocumentStorage::class);
        $shared = $pdfFiles[0];
        $ownDocumentId = $documentRepo->insert([
            'supplier_id' => $this->supplierId, 'title' => 'Vlastní dokument', 'original_name' => 'vlastni.pdf',
            'filename' => $shared['filename'], 'sha256' => $shared['sha256'], 'mime_type' => 'application/pdf',
            'size_bytes' => 1, 'doc_type' => 'pdf', 'uploaded_by' => $this->adminId,
        ]);
        $container->get(\MyInvoice\Repository\DocumentFileRepository::class)->add([
            'document_id' => $ownDocumentId, 'supplier_id' => $this->supplierId,
            'role' => 'primary', 'sha256' => $shared['sha256'], 'filename' => $shared['filename'],
        ]);
        $sampleInvoiceId = (int) $pdo->query("SELECT MIN(id) FROM invoices WHERE supplier_id={$this->supplierId}")->fetchColumn();
        $container->get(\MyInvoice\Repository\DocumentLinkRepository::class)->attach($this->supplierId, $ownDocumentId, 'invoice', $sampleInvoiceId);

        $del = $this->service->purge($this->supplierId);
        self::assertSame(36, $del['clients']);
        self::assertSame(120, $del['purchase_invoices']);
        self::assertSame(count($postedIds), $del['journal_entries']);
        self::assertSame(3, $del['documents']);
        self::assertSame(12, $del['credit_note_offsets']);
        self::assertSame(4, $del['expense_rules']);
        self::assertSame(3, $del['bank_posting_rules']);
        self::assertSame(3, $del['dimension_values']);
        self::assertSame(5, $del['dimension_types']);
        self::assertSame(0, (int) $pdo->query("SELECT dimensions_enabled FROM supplier WHERE id={$this->supplierId}")->fetchColumn());
        foreach (['dimension_types','dimension_values','document_dimensions','journal_entry_line_dimensions','dimension_defaults'] as $table) {
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table WHERE supplier_id={$this->supplierId}")->fetchColumn(), $table);
        }
        foreach ($pdfFiles as $file) {
            $path = $documentStorage->pathFor($this->supplierId, $file['sha256'], $file['filename']);
            if ($file['sha256'] === $shared['sha256']) self::assertFileExists($path);
            else self::assertFileDoesNotExist($path);
        }
        self::assertSame([$ownDocumentId], array_map('intval', $pdo->query("SELECT id FROM documents WHERE supplier_id={$this->supplierId}")->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM document_links WHERE supplier_id={$this->supplierId}")->fetchColumn());
        $pdo->prepare('DELETE FROM documents WHERE supplier_id=? AND id=?')->execute([$this->supplierId, $ownDocumentId]);
        $documentStorage->deleteIfOrphan($this->supplierId, $shared['sha256'], $shared['filename'], null, $documentRepo, []);
        self::assertSame(120, $del['stock_documents']);
        self::assertSame(1, $del['bank_accounts']);
        self::assertSame(7, $del['cash_documents']);
        self::assertSame(1, $del['cash_registers']);
        self::assertSame(3, $del['manufacturers']);
        self::assertSame(3, $del['eshop_categories']);
        self::assertGreaterThan(0, $del['activity_logs']);

        // Po purge je vše pryč.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM bank_statements WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM supplier_bank_accounts WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame([], $pdo->query("SELECT entity_type,entity_id,action FROM activity_log WHERE supplier_id={$this->supplierId}")->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_items WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_levels WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM cash_documents WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM cash_registers WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM manufacturers WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_categories WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE supplier_id={$this->supplierId}")->fetchColumn());

        // Polymorfní přívěsky bez FK (entity_type/entity_id) — smazáním dokladu nezmizí,
        // takže je purge musí uklidit sám. AI joby i korekce visí i na bank_transaction,
        // které se needviduje jednotlivě (patří pod výpis) → cesta přes bank_statements.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM ai_jobs WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM accounting_corrections WHERE supplier_id={$this->supplierId}")->fetchColumn());

        // Čítače číselných řad: po odebrání ukázek nesmí zůstat vyhnané nahoru, jinak
        // první REÁLNÁ faktura naváže číslováním až za smazanými ukázkovými doklady.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_counters WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoice_counters WHERE supplier_id={$this->supplierId}")->fetchColumn());

        self::assertFalse($this->service->hasSampleData($this->supplierId));
        self::assertSame([], array_filter(\MyInvoice\Service\Sample\SamplePayrollPurger::snapshot($pdo, $this->supplierId)));
    }

    public function testSecondGenerateIsBlockedByGuard(): void
    {
        $pdo = $this->db->pdo();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id={$this->supplierId} AND code='CZK'")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Existující data", "Test 1", "Praha", "11000", ?, "existing@example.invalid", ?)'
        )->execute([$this->supplierId, $countryId, $currencyId]);

        $this->expectException(\RuntimeException::class);
        $this->generator->generate($this->supplierId, $this->adminId);
    }

    public function testNonVatSoleTraderKeepsTaxEvidenceAndStockDisabled(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status) VALUES (?, "disabled")')->execute([$this->supplierId]);
        $module = $pdo->prepare('SELECT * FROM payroll_module_state WHERE supplier_id = ?');
        $module->execute([$this->supplierId]);
        $beforeModule = $module->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare(
            "UPDATE supplier
                SET taxpayer_type='fo', is_vat_payer=0, accounting_mode='tax_evidence', stock_enabled=0
              WHERE id=?"
        )->execute([$this->supplierId]);

        $result = $this->generator->generate($this->supplierId, $this->adminId);
        self::assertFalse($result['accounting_enabled']);
        self::assertSame(120, $result['invoices']);
        self::assertSame(120, $result['purchase_invoices']);
        self::assertSame(0, $result['stock_documents']);
        self::assertSame(0, $result['assets']);
        self::assertSame(0, $result['bank_statements']);
        self::assertSame(1, $result['cash_registers']);
        self::assertSame(7, $result['cash_documents']);
        self::assertSame(0, $result['manufacturers']);
        self::assertSame(0, $result['eshop_categories']);
        self::assertSame(0, $result['journal_entries']);

        $flags = $pdo->query(
            "SELECT accounting_mode, stock_enabled FROM supplier WHERE id={$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('tax_evidence', $flags['accounting_mode']);
        self::assertSame(0, (int) $flags['stock_enabled']);

        $deleted = $this->service->purge($this->supplierId);
        self::assertSame(7, $deleted['cash_documents']);
        self::assertSame(1, $deleted['cash_registers']);
        self::assertFalse($this->service->hasSampleData($this->supplierId));
        $module->execute([$this->supplierId]);
        self::assertSame($beforeModule, $module->fetch(PDO::FETCH_ASSOC));
    }

    public function testCliOffersOldSamplesAndKeepsUntrackedData(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT id,?,street,city,zip,country_id,\'demo@example.invalid\',default_currency_id FROM supplier WHERE id=?');
        $stmt->execute(['Demo stará ukázka', $this->supplierId]);
        $sampleId = (int) $pdo->lastInsertId();
        $stmt->execute(['Demo vlastní klient', $this->supplierId]);
        $ownId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO sample_data_entries (supplier_id,entity_type,entity_id) VALUES (?,'client',?)")->execute([$this->supplierId, $sampleId]);

        [$exit, $output] = $this->runSampleCli(['--yes'], "NE\n");
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Smazat původní ukázková data před novým generováním?', $output);
        self::assertTrue($this->service->hasSampleData($this->supplierId));
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id={$this->supplierId}")->fetchColumn());

        [$exit, $output] = $this->runSampleCli(['--replace', '--yes'], '');
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('Původní ukázková data byla odebrána.', $output);
        self::assertStringContainsString('Vlastní ani neevidovaná data se automaticky nemažou.', $output);
        self::assertFalse($this->service->hasSampleData($this->supplierId));
        self::assertSame([$ownId], array_map('intval', $pdo->query("SELECT id FROM clients WHERE supplier_id={$this->supplierId}")->fetchAll(PDO::FETCH_COLUMN)));

        $pdo->prepare("INSERT INTO sample_data_entries (supplier_id,entity_type,entity_id) VALUES (?,'client',?)")->execute([$this->supplierId, $ownId]);
        [$exit, $output] = $this->runSampleCli([], "ANO\nNE\n");
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Původní ukázková data byla odebrána.', $output);
        self::assertStringContainsString('Pokračovat? (ANO): Zrušeno.', $output);
        self::assertFalse($this->service->hasSampleData($this->supplierId));
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id={$this->supplierId}")->fetchColumn());
    }

    private function runSampleCli(array $args, string $input): array
    {
        $root = dirname(__DIR__, 4);
        $process = proc_open([PHP_BINARY, $root . '/api/bin/sample.php', '--supplier=' . $this->supplierId, ...$args], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes, $root);
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
