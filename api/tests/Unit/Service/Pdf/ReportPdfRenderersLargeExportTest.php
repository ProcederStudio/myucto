<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use MyInvoice\Service\Logbook\FuelingExportService;
use MyInvoice\Service\Logbook\TripExportService;
use MyInvoice\Service\Pdf\AccountStatementPdfRenderer;
use MyInvoice\Service\Pdf\DphBookPdfRenderer;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\SaldoPdfRenderer;
use MyInvoice\Service\Pdf\StockItemMovementsPdfRenderer;
use MyInvoice\Service\Pdf\StockStatusPdfRenderer;
use MyInvoice\Service\Pdf\StockValuationPdfRenderer;
use MyInvoice\Service\Pdf\TaxEvidenceCashJournalPdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sestavy s dlouhou tabulkou (opis účtu, peněžní deník, saldokonto, kniha DPH, sklad,
 * kniha jízd, tankování) posílaly celé HTML jedním WriteHTML(): nad ~1 MB HTML padaly
 * na pcre.backtrack_limit a jedna otevřená tabulka držela v paměti celý rok.
 * Test stáhne pcre.backtrack_limit tak, že jedno volání spadne už na pár stovkách řádků,
 * a ověří, že renderer sestavu vysází po kusech (viz ChunkedHtmlWriter).
 */
final class ReportPdfRenderersLargeExportTest extends TestCase
{
    private const ROWS = 450;

    private ?string $originalBacktrackLimit = null;

    protected function tearDown(): void
    {
        if ($this->originalBacktrackLimit !== null) {
            ini_set('pcre.backtrack_limit', $this->originalBacktrackLimit);
        }
    }

    /** @return iterable<string,array{\Closure():string,\Closure():string}> [HTML sestavy, render PDF] */
    public static function reports(): iterable
    {
        $base = fn (object $r, string $tpl, array $data) => fn () => (string) (new \ReflectionMethod($r, 'renderTemplate'))->invoke($r, $tpl, $data);
        $r = new AccountStatementPdfRenderer();
        yield 'opis účtu' => [$base($r, 'account_statement.twig', self::accountStatement()), fn () => $r->render(self::accountStatement())];
        $r = new TaxEvidenceCashJournalPdfRenderer();
        yield 'peněžní deník' => [$base($r, 'cash_journal.twig', self::cashJournal()), fn () => $r->render(self::cashJournal())];
        $r = new SaldoPdfRenderer();
        yield 'saldokonto' => [$base($r, 'saldo.twig', self::saldo()), fn () => $r->render(self::saldo())];
        yield 'saldokonto, členění po splatnosti' => [$base($r, 'saldo.twig', self::saldoAging()), fn () => $r->render(self::saldoAging())];
        $d = new DphBookPdfRenderer();
        yield 'kniha DPH' => [
            fn () => (string) (new \ReflectionMethod($d, 'twig'))->invoke($d)->render('dph_book.twig', self::dphBook()),
            fn () => $d->render(self::dphBook()),
        ];
        $r = new StockItemMovementsPdfRenderer();
        yield 'skladová karta' => [$base($r, 'stock_item_movements.twig', self::stockMovements()), fn () => $r->render(self::stockMovements())];
        $r = new StockStatusPdfRenderer();
        yield 'stav zásob' => [$base($r, 'stock_status.twig', self::stockItems()), fn () => $r->render(self::stockItems())];
        $r = new StockValuationPdfRenderer();
        yield 'ocenění zásob' => [$base($r, 'stock_valuation.twig', self::stockItems()), fn () => $r->render(self::stockItems())];
        yield 'kniha jízd' => [fn () => self::callPrivate(TripExportService::class, 'pdfHtml', self::trips()), fn () => self::callPrivate(TripExportService::class, 'pdf', self::trips())];
        yield 'tankování' => [fn () => self::callPrivate(FuelingExportService::class, 'pdfHtml', self::fuelings()), fn () => self::callPrivate(FuelingExportService::class, 'pdf', self::fuelings())];
    }

    #[DataProvider('reports')]
    public function testLongReportRendersInPieces(\Closure $html, \Closure $render): void
    {
        // Limit pod velikostí celé sestavy, ale nad jednou dávkou řádků: jedno WriteHTML()
        // na celé HTML by spadlo (mPDF porovnává délku HTML s limitem), dávky projdou.
        $source = $html();
        $limit = (int) (strlen($source) * 0.95);
        $this->originalBacktrackLimit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) $limit);

        $threw = false;
        try {
            (new Mpdf(['mode' => 'utf-8', 'tempDir' => sys_get_temp_dir()] + MpdfFontConfig::options()))->WriteHTML($source);
        } catch (MpdfException) {
            $threw = true;
        }
        self::assertTrue($threw, 'Jedno WriteHTML() na celou sestavu musí za tohoto limitu spadnout, jinak test nic neověřuje.');

        $pdf = $render();

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), 'Sestava musí mít víc stran.');
    }

    /** @param list<array<string,mixed>> $rows */
    private static function callPrivate(string $class, string $method, array $rows): string
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        return (string) (new \ReflectionMethod($service, $method))->invoke($service, $rows, '01.01.2024 – 31.12.2024', 'Testovací firma s.r.o.');
    }

    /** @return array<string,mixed> */
    private static function accountStatement(): array
    {
        $items = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $items[] = ['entry_date' => '2024-05-01', 'document_no' => 'FP' . $i, 'description' => 'Syntetický pohyb ' . $i,
                'side' => $i % 2 ? 'debit' : 'credit', 'amount' => $i * 3.21, 'balance' => $i * 1.5];
        }
        return ['account' => ['code' => '321', 'name' => 'Dodavatelé'], 'from' => '2024-01-01', 'to' => '2024-12-31',
            'opening_balance' => 0.0, 'items' => $items, 'turnover_md' => 1.0, 'turnover_d' => 2.0, 'closing_balance' => 3.0];
    }

    /** @return array<string,mixed> */
    private static function cashJournal(): array
    {
        $rows = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $rows[] = ['date' => '2024-03-01', 'doc_no' => 'BV' . $i, 'partner' => 'Syntetický partner ' . $i,
                'description' => 'Platba faktury ' . $i, 'income' => $i % 2 ? 100.0 : null, 'expense' => $i % 2 ? null : 50.0,
                'running_balance' => 1000.0 + $i, 'bucket' => $i % 7 ? 'income_taxable' : 'nezarazeno', 'unclassified' => $i % 7 === 0];
        }
        return ['year' => 2024, 'from' => '2024-01-01', 'to' => '2024-12-31', 'warnings' => [], 'rows' => $rows,
            'opening_balance' => 0.0, 'closing_balance' => 1.0, 'totals' => []];
    }

    /** @return array<string,mixed> */
    private static function saldo(): array
    {
        $partners = [];
        for ($p = 1; $p <= intdiv(self::ROWS, 5); $p++) {
            $items = [];
            for ($i = 1; $i <= 5; $i++) {
                $items[] = ['doc_no' => 'FV' . $p . '-' . $i, 'issue_date' => '2024-02-01', 'due_date' => '2024-03-01',
                    'days_overdue' => $i * 3, 'booked_czk' => 1000.0, 'currency_code' => 'CZK', 'amount_foreign' => 0.0,
                    'paid_czk' => 0.0, 'remaining_czk' => 1000.0];
            }
            $partners[] = ['partner_name' => 'Syntetický odběratel ' . $p, 'total_remaining' => 5000.0, 'items' => $items];
        }
        return ['entity' => ['name' => 'Testovací firma s.r.o.', 'ico' => '12345678', 'address' => 'Testovací 1', 'prepared_at' => '01.01.2025'],
            'as_of' => '2024-12-31', 'period' => ['fiscal_year' => 2024],
            'accounts' => [['account' => ['code' => '311', 'name' => 'Odběratelé'], 'note' => null, 'gl_balance' => 1.0,
                'open_items_total' => 1.0, 'matches' => true, 'difference' => 0.0, 'partners' => $partners]]];
    }

    /** Členění bez rozpisu: dlouhá je tu tabulka partnerů, ne dokladů. @return array<string,mixed> */
    private static function saldoAging(): array
    {
        $data = self::saldo();
        $partners = [];
        for ($p = 1; $p <= self::ROWS; $p++) {
            $partners[] = ['partner_name' => 'Syntetický odběratel ' . $p, 'total_remaining' => 1000.0,
                'items' => [['days_overdue' => $p % 200, 'remaining_czk' => 1000.0]]];
        }
        $data['accounts'][0]['partners'] = $partners;
        $data['aging'] = \MyInvoice\Service\Accounting\Reports\SaldoAgingBuckets::build($data, [30, 60, 120]);
        $data['show_detail'] = false;
        return $data;
    }

    /** @return array<string,mixed> */
    private static function dphBook(): array
    {
        $rows = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $rows[] = ['is_draft' => false, 'tax_date' => '2024-06-10', 'accounting_date' => '2024-06-11', 'direction' => 'issued',
                'doc_number' => '2024' . $i, 'description' => 'Syntetické plnění ' . $i, 'base' => 1000.0, 'vat' => 210.0,
                'total' => 1210.0, 'counterparty_name' => 'Partner ' . $i, 'counterparty_dic' => 'CZ12345678', 'kh_section' => 'A.4'];
        }
        return ['period' => ['year' => 2024, 'month' => 6, 'quarter' => null, 'label' => '06/2024'],
            'supplier' => ['company_name' => 'Testovací firma s.r.o.'],
            'sections' => [['key' => '01 ř.001', 'direction' => 'VYSTAVENÁ', 'label' => 'sazba 21 %', 'rows' => $rows,
                'subtotal_base' => 1.0, 'subtotal_vat' => 1.0, 'subtotal_total' => 1.0]]];
    }

    /** @return array<string,mixed> */
    private static function stockMovements(): array
    {
        $items = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $items[] = ['doc_date' => '2024-04-01', 'doc_number' => 'PR' . $i, 'doc_type' => 'receipt', 'invoice_number' => 'FV' . $i,
                'partner' => ['name' => 'Partner ' . $i], 'warehouse_code' => 'HL', 'qty_signed' => 5, 'unit_cost' => 12.5,
                'value_total' => 62.5, 'balance_after' => $i * 5];
        }
        return ['item' => ['sku' => 'SKU-1', 'name' => 'Syntetická položka', 'unit' => 'ks'],
            'movements' => ['opening_balance' => 0, 'items' => $items]];
    }

    /** @return array<string,mixed> */
    private static function stockItems(): array
    {
        $items = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $items[] = ['warehouse_code' => 'HL', 'sku' => 'SKU-' . $i, 'name' => 'Syntetická skladová položka ' . $i,
                'item_type' => 'goods', 'unit' => 'ks', 'qty' => $i, 'min_qty' => null, 'avg_unit_cost' => 10.0, 'value_total' => 10.0 * $i];
        }
        return ['date' => '2024-12-31', 'items' => $items, 'totals' => ['count' => self::ROWS, 'value_total' => 1.0]];
    }

    /** @return list<array<string,mixed>> */
    private static function trips(): array
    {
        $rows = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $rows[] = ['trip_date' => '2024-03-01', 'car_registration' => $i <= self::ROWS / 2 ? '1AB 0001' : '1AB 0002',
                'origin' => 'Praha', 'destination' => 'Brno', 'purpose' => 'Jednání ' . $i, 'category_label' => 'Služební',
                'odometer_start' => 1000 + $i * 10, 'odometer_end' => 1010 + $i * 10, 'distance_km' => 10.0];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function fuelings(): array
    {
        $rows = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $rows[] = ['fueled_date' => '2024-03-01', 'car_registration' => '1AB 0001', 'fuel_type' => 'diesel', 'quantity' => 40.0,
                'unit' => 'l', 'unit_price' => 36.9, 'amount_without_vat' => 1219.83, 'amount_with_vat' => 1476.0,
                'currency' => 'CZK', 'odometer' => 10000 + $i * 500, 'station' => 'Čerpací stanice ' . $i];
        }
        return $rows;
    }
}
