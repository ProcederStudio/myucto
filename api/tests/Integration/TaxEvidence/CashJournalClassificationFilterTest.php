<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\TaxEvidence\CashJournalAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Service\Pdf\TaxEvidenceCashJournalPdfRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Filtr je pohled na hotový deník: nesmí z něj udělat nový finanční výpočet. */
#[Group('integration')]
final class CashJournalClassificationFilterTest extends CashJournalTestCase
{
    protected string $accountA = '1000000005';
    private CashJournalAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(CashJournalAction::class);
    }

    public function testEveryClassificationSelectsDisplayedBucketAndPreservesWholePeriod(): void
    {
        $this->classifiedMovements();
        $all = $this->journal();
        self::assertSame(8, count($all['rows']));
        self::assertSame(8, $all['total_rows']);
        self::assertSame(8, $all['filtered_rows']);
        self::assertNull($all['classification']);
        self::assertSame(1, $all['blocking_unclassified_count']);
        self::assertNotEmpty($all['warnings']);

        foreach ([...MovementClassificationRepository::TAX_BUCKETS, 'nezarazeno'] as $bucket) {
            $filtered = $this->journal(['classification' => $bucket]);
            self::assertSame($bucket, $filtered['classification']);
            self::assertSame(8, $filtered['total_rows']);
            self::assertSame(1, $filtered['filtered_rows']);
            self::assertSame(array_values(array_filter($all['rows'], static fn (array $r): bool => $r['bucket'] === $bucket)), $filtered['rows']);
            foreach (['opening_balance', 'closing_balance', 'balances', 'totals', 'checks', 'warnings', 'blocking_unclassified_count'] as $key) {
                self::assertSame($all[$key], $filtered[$key], $bucket . ': ' . $key);
            }
        }
        self::assertSame($all, $this->journal(['classification' => '']));
    }

    public static function invalidClassifications(): iterable
    {
        yield 'unknown' => ['all'];
        yield 'array' => [['private']];
        yield 'integer' => [1];
        yield 'null' => [null];
        yield 'boolean' => [false];
    }

    #[DataProvider('invalidClassifications')]
    public function testInvalidClassificationIsRejectedForGetAndBothExports(mixed $classification): void
    {
        foreach ([['get', []], ['export', ['format' => 'xlsx']], ['export', ['format' => 'pdf']]] as [$method, $query]) {
            $response = $this->request($method, ['classification' => $classification] + $query);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('validation_failed', json_decode((string) $response->getBody(), true)['error']['code']);
        }
    }

    public function testDateRangeAndEmptyJournalRetainSelectionMetadata(): void
    {
        $empty = $this->journal(['classification' => 'expense_taxable']);
        self::assertSame('expense_taxable', $empty['classification']);
        self::assertSame(0, $empty['total_rows']);
        self::assertSame(0, $empty['filtered_rows']);
        self::assertSame(0, $empty['blocking_unclassified_count']);

        $this->classifiedMovements();
        $result = $this->journal(['from' => self::YEAR . '-06-01', 'to' => self::YEAR . '-06-03', 'classification' => 'income_nontax']);
        self::assertSame(3, $result['total_rows']);
        self::assertSame(1, $result['filtered_rows']);
        self::assertSame('income_nontax', $result['rows'][0]['bucket']);
    }

    public function testMixedAllocationUsesPrimaryDisplayedBucketRatherThanAnyTaxAllocation(): void
    {
        $this->setVatPayer($this->supplierId, false);
        $statement = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($statement, -1000.0, ['match_status' => 'manual']);
        $taxable = $this->purchaseInvoice($this->supplierId, ['without' => 400.0, 'with' => 400.0, 'status' => 'paid']);
        $nontaxable = $this->purchaseInvoice($this->supplierId, ['without' => 600.0, 'with' => 600.0, 'status' => 'paid', 'tax_deductible' => 0]);
        $this->paymentMatch($this->supplierId, $tx, $taxable, 400.0);
        $this->paymentMatch($this->supplierId, $tx, $nontaxable, 600.0);

        $taxableView = $this->journal(['classification' => 'expense_taxable']);
        self::assertCount(1, $taxableView['rows']);
        self::assertSame('expense_taxable', $taxableView['rows'][0]['bucket']);
        self::assertEquals(1000.0, $taxableView['rows'][0]['expense']);
        self::assertEquals(600.0, $taxableView['totals']['vydaj_nedanovy']);
        $nontaxableView = $this->journal(['classification' => 'expense_nontax']);
        self::assertSame([], $nontaxableView['rows']);
        self::assertSame(1, $nontaxableView['total_rows']);
        self::assertSame(0, $nontaxableView['filtered_rows']);
        self::assertSame($taxableView['totals'], $nontaxableView['totals']);
    }

    public function testFilteringRetainsTenantScopeForRowsCountsAndWarnings(): void
    {
        $own = $this->cashDoc('in', 'sale', 100.0);
        $this->classifyOverride($this->supplierId, 'cash', $own, 'private');
        $otherSupplier = $this->cloneSupplier('tax_evidence', false);
        $this->currencyRow($otherSupplier, 'CZK', '1000000005', '0100');
        $otherStatement = $this->statement($otherSupplier, '1000000005', '0100');
        $this->bankTx($otherStatement, 9000.0);

        $result = $this->journal(['classification' => 'private']);
        self::assertSame(1, $result['total_rows']);
        self::assertSame(1, $result['filtered_rows']);
        self::assertSame($own, $result['rows'][0]['source_id']);
        self::assertSame(0, $result['blocking_unclassified_count']);
        self::assertSame([], $result['warnings']);
    }

    public function testExportsUseSameRowsAndKeepPeriodWarningsAndSummaryEvenForEmptySelection(): void
    {
        $this->classifiedMovements();
        foreach (['private', 'nezarazeno'] as $bucket) {
            $data = $this->journal(['classification' => $bucket]);
            $response = $this->request('export', ['format' => 'xlsx', 'classification' => $bucket]);
            self::assertSame(200, $response->getStatusCode());
            $tmp = tempnam(sys_get_temp_dir(), 'cash-filter-');
            try {
                file_put_contents($tmp, (string) $response->getBody());
                $sheet = IOFactory::load($tmp)->getActiveSheet();
                $cells = $sheet->toArray();
                $text = implode('\n', array_map(static fn (array $row): string => implode(' ', array_map(static fn ($v): string => (string) $v, $row)), $cells));
                self::assertStringContainsString('Filtrovaný přehled', $text);
                self::assertStringContainsString('1 z 8', $text);
                self::assertStringContainsString('Souhrn za celé období', $text);
                self::assertStringContainsString('Zůstatky jsou za celý deník.', $text);
                self::assertStringContainsString('Nezařazené pohyby', $text);
                $docs = [];
                $inTable = false;
                foreach ($cells as $row) {
                    if ($row[0] === 'Datum') { $inTable = true; continue; }
                    if ($inTable && $row[0] === null) break;
                    if ($inTable) $docs[] = (string) $row[1];
                }
                self::assertSame(array_column($data['rows'], 'doc_no'), $docs);
                $sheet->getParent()->disconnectWorksheets();
            } finally {
                unlink($tmp);
            }

            $renderer = new TaxEvidenceCashJournalPdfRenderer();
            $html = (new \ReflectionMethod($renderer, 'renderTemplate'))->invoke($renderer, 'cash_journal.twig', $data);
            self::assertStringContainsString('Filtrovaný přehled', $html);
            self::assertStringContainsString('1 z 8', $html);
            self::assertStringContainsString('Souhrn za celé období', $html);
            foreach ($data['rows'] as $row) self::assertStringContainsString($row['doc_no'], $html);
            $pdf = $this->request('export', ['format' => 'pdf', 'classification' => $bucket]);
            self::assertSame(200, $pdf->getStatusCode());
            self::assertStringStartsWith('%PDF', (string) $pdf->getBody());
        }

        $this->db->pdo()->prepare("DELETE FROM de_movement_classification WHERE supplier_id = ? AND tax_bucket = 'private'")->execute([$this->supplierId]);
        $empty = $this->journal(['classification' => 'private']);
        self::assertSame([], $empty['rows']);
        $html = (new \ReflectionMethod(new TaxEvidenceCashJournalPdfRenderer(), 'renderTemplate'))->invoke(new TaxEvidenceCashJournalPdfRenderer(), 'cash_journal.twig', $empty);
        self::assertStringContainsString('0 z 8', $html);
        self::assertStringContainsString('Souhrn za celé období', $html);
        self::assertStringContainsString('Nezařazené pohyby', $html);
        $response = $this->request('export', ['format' => 'xlsx', 'classification' => 'private']);
        self::assertSame(200, $response->getStatusCode());
        $tmp = tempnam(sys_get_temp_dir(), 'cash-filter-');
        try {
            file_put_contents($tmp, (string) $response->getBody());
            $sheet = IOFactory::load($tmp)->getActiveSheet();
            $text = implode('\n', array_map(static fn (array $row): string => implode(' ', array_map(static fn ($v): string => (string) $v, $row)), $sheet->toArray()));
            self::assertStringContainsString('0 z 8', $text);
            self::assertStringContainsString('Souhrn za celé období', $text);
            self::assertStringContainsString('Nezařazené pohyby', $text);
            $sheet->getParent()->disconnectWorksheets();
        } finally {
            unlink($tmp);
        }
    }

    private function classifiedMovements(): void
    {
        foreach (MovementClassificationRepository::TAX_BUCKETS as $index => $bucket) {
            $id = $this->cashDoc('in', 'sale', 100.0 + $index, [], null, self::YEAR . '-06-' . sprintf('%02d', $index + 1));
            $this->classifyOverride($this->supplierId, 'cash', $id, $bucket);
        }
        $statement = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($statement, 50.0);
    }

    private function journal(array $query = []): array
    {
        $response = $this->request('get', $query);
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function request(string $method, array $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/tax-evidence/cash-journal')
            ->withQueryParams($query + ['year' => (string) self::YEAR])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']);
        return $this->action->{$method}($request, new Response());
    }
}
