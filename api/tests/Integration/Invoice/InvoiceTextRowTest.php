<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Textový řádek mezi položkami vydaného dokladu (item_kind = 'text', migrace 2034).
 *
 * Řádek nese jen popis. Nesmí ovlivnit žádnou částku, rekapitulaci DPH, evidenci DPH
 * ani zaúčtování (žádné nulové řádky v deníku), a přesto musí přežít uložení na svém
 * místě mezi položkami a vytisknout se přes celou šířku tabulky. Kopii a dobropis
 * ověřuje {@see InvoiceTextRowCopyTest} (akce si otevírají vlastní transakci).
 *
 * Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class InvoiceTextRowTest extends TestCase
{
    private const YEAR = 2093;
    private const DATE = '2093-06-15';

    private Connection $db;
    private InvoiceRepository $repo;
    private InvoiceCalculator $calc;
    private PostingService $posting;
    private VatLedgerService $vatLedger;
    private \Psr\Container\ContainerInterface $container;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $rate21 = 0;
    private int $rate12 = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->repo = $this->container->get(InvoiceRepository::class);
            $this->calc = $this->container->get(InvoiceCalculator::class);
            $this->posting = $this->container->get(PostingService::class);
            $this->vatLedger = $this->container->get(VatLedgerService::class);
            $periods = $this->container->get(AccountingPeriodRepository::class);
            $seeder = $this->container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->rate21 = (int) ($pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 AND valid_to IS NULL ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->rate12 = (int) ($pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 12 AND is_reverse_charge = 0 AND valid_to IS NULL ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->currencyId, $this->userId, $this->czId, $this->rate21, $this->rate12], true)) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/user/country/sazby 21 a 12 %) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', is_vat_payer = 1 WHERE id = ?")->execute([$this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testTextRowIsStoredAsZeroLineInItsPlaceAndStaysOutOfTotals(): void
    {
        $id = $this->draft([
            $this->line('Licence', 1000.00, $this->rate21),
            [
                // Cokoli navíc, co sem pošle skript, zápis zahodí.
                'item_kind' => 'text', 'description' => "Oddíl B\nmontáž na místě", 'quantity' => 5,
                'unit' => 'hod', 'unit_price_without_vat' => 99, 'vat_rate_id' => $this->rate12,
                'vat_classification_code' => '2', 'oss_applicable' => true, 'oss_consumer_country' => 'DE',
                'oss_rate_type' => 'standard', 'oss_supply_type' => 'services', 'revenue_account_code' => '604',
                'accrual_from' => self::DATE, 'accrual_to' => '2094-06-14',
            ],
            $this->line('Doprava', 500.00, $this->rate21),
        ]);

        $invoice = $this->repo->find($id);
        self::assertSame(['Licence', "Oddíl B\nmontáž na místě", 'Doprava'], array_column($invoice['items'], 'description'));
        $text = $invoice['items'][1];
        self::assertSame('text', $text['item_kind']);
        self::assertSame(0.0, $text['quantity']);
        self::assertSame('', $text['unit']);
        self::assertSame(0.0, $text['unit_price_without_vat']);
        self::assertSame([0.0, 0.0, 0.0], [$text['total_without_vat'], $text['total_vat'], $text['total_with_vat']]);
        self::assertNull($text['vat_classification_code']);
        self::assertFalse($text['oss_applicable']);
        self::assertNull($text['oss_consumer_country']);
        self::assertNull($text['revenue_account_code']);
        self::assertNull($text['accrual_from']);
        self::assertNull($text['stock_item_id']);

        self::assertSame(1500.0, (float) $invoice['total_without_vat']);
        self::assertSame(1815.0, (float) $invoice['total_with_vat']);
        self::assertCount(1, $invoice['vat_breakdown'], 'Textový řádek nezakládá řádek rekapitulace 12 %.');
        self::assertSame(21.0, $invoice['vat_breakdown'][0]['rate']);
    }

    /**
     * Sazbu textový řádek nést musí (NOT NULL, INNER JOIN), ale vlastní nemá: vezme se
     * z první položky dokladu, ať nezaloží vlastní skupinu ani v dotazech podle sazby.
     */
    public function testTextRowTakesRateOfFirstItem(): void
    {
        $id = $this->draft([
            ['item_kind' => 'text', 'description' => 'Nadpis bez sazby'],
            $this->line('Licence', 1000.00, $this->rate12),
            ['item_kind' => 'text', 'description' => 'Nadpis s jinou sazbou', 'vat_rate_id' => $this->rate21],
        ]);
        $items = $this->repo->find($id)['items'];
        self::assertSame([$this->rate12, $this->rate12, $this->rate12], array_column($items, 'vat_rate_id'));

        $onlyText = $this->draft([['item_kind' => 'text', 'description' => 'Rozpracováno']]);
        self::assertGreaterThan(0, $this->repo->find($onlyText)['items'][0]['vat_rate_id']);
    }

    public function testHeaderDiscountIgnoresTextRow(): void
    {
        $id = $this->draft([
            $this->line('Licence', 1000.00, $this->rate21),
            ['item_kind' => 'text', 'description' => 'Poznámka k řádku', 'vat_rate_id' => $this->rate12],
        ], ['discount_percent' => 10]);

        $invoice = $this->repo->find($id);
        $discounts = array_values(array_filter($invoice['items'], static fn (array $i): bool => $i['item_kind'] === 'discount'));
        self::assertCount(1, $discounts, 'Sleva jen za sazbu skutečné položky.');
        self::assertSame(21.0, $discounts[0]['vat_rate_snapshot']);
        self::assertSame(900.0, (float) $invoice['total_without_vat']);
        self::assertCount(1, $invoice['vat_breakdown']);
    }

    public function testDocumentWithOnlyTextRowsCannotBeIssued(): void
    {
        $id = $this->draft([['item_kind' => 'text', 'description' => 'Jen text']]);

        $res = $this->invoke($this->container->get(IssueInvoiceAction::class), ['id' => (string) $id]);

        self::assertSame(422, $res['status']);
        self::assertSame('no_items', $res['body']['error']['code'] ?? null);
        self::assertSame('draft', $this->repo->find($id)['status']);
    }

    /**
     * Celý doklad jde na účet produktu; textový řádek bez účtu by v rozpadu výnosu
     * založil nulovou nohu na předkontaci (602). V knize DPH by se objevil jako věta
     * s nulovým základem pod kódem z hlavičky.
     */
    public function testTextRowCreatesNoJournalLineAndNoVatLedgerRow(): void
    {
        $product = $this->product('604');
        $id = $this->draft([
            $this->line('Zboží', 1000.00, $this->rate21) + ['stock_item_id' => $product],
            ['item_kind' => 'text', 'description' => 'Dodáno na stavbu', 'vat_rate_id' => $this->rate12],
        ]);
        $this->markIssued($id, 'FV-TXT-1');

        $lines = $this->posting->buildFromInvoice($this->supplierId, $id);
        foreach ($lines as $line) {
            self::assertGreaterThan(0.0, (float) $line['amount'], 'Deník nesmí dostat nulový řádek: ' . json_encode($line));
        }
        $accounts = array_column($lines, 'account_code');
        self::assertContains('604', $accounts);
        self::assertNotContains('602', $accounts);

        $rows = $this->vatLedger->documentRows($this->supplierId, 'sale', $id, self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertCount(1, $rows);
        self::assertNotContains('Dodáno na stavbu', array_column($rows, 'description'));

        // Náhled zdrojového dokladu v deníku: rekapitulace DPH bez nulového řádku 12 %.
        $summary = $this->container->get(\MyInvoice\Service\Accounting\JournalSourceSummaryService::class)
            ->summarize($this->supplierId, ['source_type' => 'invoice', 'source_id' => $id]);
        $recap = array_values(array_filter($summary['blocks'] ?? [], static fn (array $b): bool => ($b['key'] ?? '') === 'vat'));
        self::assertCount(1, $recap);
        self::assertSame([21.0], array_column($recap[0]['rows'], 'vat_rate'));
    }

    /** Popis zápisu v deníku bere text první skutečné položky, ne textového řádku. */
    public function testJournalDescriptionSkipsTextRow(): void
    {
        $id = $this->draft([
            ['item_kind' => 'text', 'description' => 'Oddíl A'],
            $this->line('Licence', 1000.00, $this->rate21),
        ]);
        $this->markIssued($id, 'FV-TXT-DESC');

        $text = $this->container->get(\MyInvoice\Service\Accounting\JournalDescriptionBuilder::class)
            ->forInvoice($this->supplierId, $id);

        self::assertStringContainsString('Licence', (string) $text);
        self::assertStringNotContainsString('Oddíl A', (string) $text);
    }

    public function testAccrualCannotBeSetOnTextRow(): void
    {
        $id = $this->draft([
            $this->line('Předplatné', 1200.00, $this->rate21),
            ['item_kind' => 'text', 'description' => 'Období předplatného', 'vat_rate_id' => $this->rate21],
        ]);
        $this->markIssued($id, 'FV-TXT-ACR');
        $items = $this->repo->find($id)['items'];

        $res = $this->invoke(
            $this->container->get(\MyInvoice\Action\Invoice\SetInvoiceAccrualAction::class),
            ['id' => (string) $id],
            ['items' => [['id' => $items[1]['id'], 'accrual_from' => self::DATE, 'accrual_to' => '2094-06-14']]],
        );

        self::assertSame(400, $res['status'], json_encode($res['body']));
        self::assertArrayHasKey('items.0.accrual_from', $res['body']['error']['fields'] ?? []);
        self::assertNull($this->repo->find($id)['items'][1]['accrual_from']);
    }

    /**
     * Položky s vlastním střediskem se při zaúčtování rozdělí podle svých dimenzí.
     * Textový řádek mezi nimi má nulovou váhu; kdyby vstoupil do rozpadu jako další
     * kombinace dimenzí, rozpad by nešel (nulová váha) a celý výnos by spadl na
     * dimenze hlavičky. Číslování položek pro dimenze ho přitom počítá (editor taky).
     */
    public function testTextRowDoesNotBreakItemDimensionSplit(): void
    {
        $dimensions = $this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $assignments = $this->container->get(\MyInvoice\Repository\DimensionAssignmentRepository::class);
        $journal = $this->container->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $dimensions->setEnabled($this->supplierId, true);
        $centerType = $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $centerA = (int) $dimensions->createValue($this->supplierId, $centerType, ['code' => 'TXT-A', 'name' => 'A'])['id'];
        $centerB = (int) $dimensions->createValue($this->supplierId, $centerType, ['code' => 'TXT-B', 'name' => 'B'])['id'];

        $id = $this->draft([
            $this->line('Práce', 600.00, $this->rate21),
            ['item_kind' => 'text', 'description' => 'Oddíl B', 'vat_rate_id' => $this->rate21],
            $this->line('Materiál', 300.00, $this->rate21),
        ]);
        $this->markIssued($id, 'FV-TXT-DIM');
        $dimensions->saveDocument($this->supplierId, 'invoice', $id, [], [1 => [$centerType => $centerA], 3 => [$centerType => $centerB]]);

        $entryId = $this->posting->postDocument(
            $this->supplierId, 'invoice', $id,
            $this->posting->buildFromInvoice($this->supplierId, $id),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId],
        );
        $dims = $assignments->entryLineDimensions($this->supplierId, $entryId);
        $revenue = [];
        foreach ($journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = (string) $this->db->pdo()->query('SELECT account_code FROM chart_of_accounts WHERE id = ' . (int) $line['account_id'])->fetchColumn();
            if (str_starts_with($code, '6')) {
                $revenue[(int) round((float) $line['amount'] * 100)] = $dims[(int) $line['id']][$centerType] ?? null;
            }
        }
        ksort($revenue);
        self::assertSame([30000 => $centerB, 60000 => $centerA], $revenue);
    }

    public function testPdfPrintsTextRowAcrossTheWholeTable(): void
    {
        $id = $this->draft([
            $this->line('Licence', 1000.00, $this->rate21),
            ['item_kind' => 'text', 'description' => "Oddíl B\nmontáž", 'vat_rate_id' => $this->rate21],
        ]);
        $renderer = $this->container->get(InvoicePdfRenderer::class);

        $html = $renderer->renderHtml($this->repo->find($id), includeCss: false);

        self::assertMatchesRegularExpression('~<tr class="text-row">\s*<td colspan="7">Oddíl B<br />\s*montáž</td>\s*</tr>~u', $html);
    }

    public function testPdfTextRowSpansLineDiscountColumns(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET invoice_pdf_show_line_discount = 1 WHERE id = ?')->execute([$this->supplierId]);
        $id = $this->draft([
            $this->line('Licence', 800.00, $this->rate21) + ['list_unit_price' => 1000.00],
            ['item_kind' => 'text', 'description' => 'Oddíl B', 'vat_rate_id' => $this->rate21],
        ]);
        $renderer = $this->container->get(InvoicePdfRenderer::class);

        $html = $renderer->renderHtml($this->repo->find($id), includeCss: false);

        self::assertStringContainsString('items-ld', $html);
        self::assertMatchesRegularExpression('~<tr class="text-row">\s*<td colspan="9">Oddíl B</td>\s*</tr>~u', $html);
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function line(string $description, float $net, int $rateId): array
    {
        return ['description' => $description, 'quantity' => 1, 'unit' => 'ks',
            'unit_price_without_vat' => $net, 'vat_rate_id' => $rateId];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed>       $header
     */
    private function draft(array $items, array $header = []): int
    {
        $id = $this->repo->createDraft($header + [
            'invoice_type' => 'invoice',
            'client_id'    => $this->client(),
            'issue_date'   => self::DATE,
            'tax_date'     => self::DATE,
            'due_date'     => self::DATE,
            'currency_id'  => $this->currencyId,
            'reverse_charge' => false,
            'language'     => 'cs',
        ], $this->userId);
        $this->repo->replaceItems($id, $items);
        $this->calc->recompute($id);
        return $id;
    }

    private function markIssued(int $id, string $varsymbol): void
    {
        $this->db->pdo()->prepare(
            "UPDATE invoices SET status = 'issued', varsymbol = ?, vat_classification_code = '1' WHERE id = ?"
        )->execute([$varsymbol, $id]);
    }

    private function client(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Odběratel textového řádku", "Test 1", "Praha", "11000", ?, "CZ12345678",
                     "text@example.invalid", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function product(string $revenueAccount): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_items (supplier_id, sku, name, revenue_account_code) VALUES (?, ?, "Produkt textového řádku", ?)'
        )->execute([$this->supplierId, 'TXT-' . bin2hex(random_bytes(3)), $revenueAccount]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invoke(object $action, array $args, array $body = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = ($action)($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
