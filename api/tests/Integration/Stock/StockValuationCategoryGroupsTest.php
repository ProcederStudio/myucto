<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockReportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Pdf\StockValuationPdfRenderer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Service\Stock\StockReportXlsxExporter;
use MyInvoice\Service\Stock\StockValuationCategoryGroups;
use MyInvoice\Service\Stock\StockValuationJobService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Ocenění k datu seskupené podle kategorií: skupiny jsou přímé podkategorie
 * zvolené kategorie (nebo kategorie nejvyšší úrovně), „přímo v kategorii“
 * a „ostatní“. Karta ve více kategoriích se počítá jen jednou (primární, jinak
 * první v pořadí karty) a součet skupin se rovná celkovému ocenění.
 */
#[Group('integration')]
final class StockValuationCategoryGroupsTest extends StockTestCase
{
    use \MyInvoice\Tests\Support\StockCategoryFixtureTrait;

    /** @return array{sid:int, root:int, c1:int, c2:int, c2a:int, other:int, empty:int} */
    private function fixture(): array
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $wh2 = $this->warehouse($sid, 'DRUHY', false);
        $root = $this->category($sid, 'VAL-ROOT', null, 1);
        $other = $this->category($sid, 'VAL-OTHER', null, 2);
        $c2 = $this->category($sid, 'VAL-C2', $root, 1);
        $c1 = $this->category($sid, 'VAL-C1', $root, 0);
        $c2a = $this->category($sid, 'VAL-C2A', $c2);
        $empty = $this->category($sid, 'VAL-EMPTY', $root, 2);
        $this->category($sid, 'VAL-ARCH', $root, 3, true); // archivovaná a prázdná: ve skupinách chybí

        $cards = [
            // sku => [kategorie [id, primární, pořadí], sklad => hodnota]
            'VAL-1' => [[[$c1, true, 0]], [$wh => 100.0]],
            'VAL-2' => [[[$c2a, true, 0]], [$wh => 200.0, $wh2 => 50.0]],
            'VAL-3' => [[[$root, true, 0]], [$wh => 30.0]],
            'VAL-4' => [[[$c1, false, 0], [$c2, true, 1]], [$wh => 7.0]],
            'VAL-5' => [[[$c1, false, 1], [$c2a, false, 0]], [$wh => 11.0]],
            'VAL-6' => [[[$other, true, 0]], [$wh => 13.0]],
            'VAL-7' => [[], [$wh => 17.0]],
            'VAL-8' => [[[$other, true, 0], [$c1, false, 1]], [$wh => 19.0]],
        ];
        foreach ($cards as $sku => [$categories, $values]) {
            $item = $this->item($sid, $sku);
            foreach ($categories as [$category, $primary, $order]) {
                $this->assignCategory($sid, $item, $category, $primary, $order);
            }
            foreach ($values as $warehouse => $value) {
                $this->receiveStock($sid, $warehouse, $item, '1', $value, '2099-01-05');
            }
        }
        return ['sid' => $sid, 'root' => $root, 'c1' => $c1, 'c2' => $c2, 'c2a' => $c2a, 'other' => $other, 'empty' => $empty];
    }

    /** @param array<string,mixed> $grouping @return array<string,array{0:int,1:string}> */
    private static function byLabel(array $grouping): array
    {
        $out = [];
        foreach ($grouping['groups'] as $g) {
            $out[$g['kind'] . ':' . ($g['category_id'] ?? '-')] = [$g['count'], $g['value_total']];
        }
        return $out;
    }

    private function completedJob(int $sid): int
    {
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31');
        self::assertSame('completed', $jobs->tick($sid)['status']);
        return (int) $job['id'];
    }

    public function testGroupsByDirectSubcategoriesAndSumEqualsTotal(): void
    {
        $f = $this->fixture();
        $jobs = $this->container->get(StockValuationJobService::class);
        $jobId = $this->completedJob($f['sid']);

        $grouping = $jobs->categoryGroups($f['sid'], $jobId, $f['root']);
        $total = $jobs->result($f['sid'], $jobId)['totals']['value_total'];

        self::assertSame(['id' => $f['root'], 'code' => 'VAL-ROOT', 'name' => 'Kategorie VAL-ROOT'], $grouping['parent']);
        self::assertSame([
            'category:' . $f['c1'] => [2, '119.00'],          // VAL-1 + VAL-8 (primární mimo strom, ve stromu jen C1)
            'category:' . $f['c2'] => [4, '268.00'],          // VAL-2 na dvou skladech + VAL-4 (primární C2) + VAL-5 (C2A první v pořadí karty)
            'category:' . $f['empty'] => [0, '0.00'],         // prázdná podkategorie zůstává, archivovaná prázdná ne
            'parent:' . $f['root'] => [1, '30.00'],           // VAL-3 přímo v kořeni
            'uncategorized:-' => [2, '30.00'],                // VAL-6 mimo kořen, VAL-7 bez kategorie
        ], self::byLabel($grouping));
        self::assertSame($total, $grouping['value_total']);
        self::assertSame('447.00', $total);
    }

    public function testCardInOtherCategoryIsCountedInsideTheChosenTree(): void
    {
        $f = $this->fixture();
        $jobs = $this->container->get(StockValuationJobService::class);
        $grouping = $jobs->categoryGroups($f['sid'], $this->completedJob($f['sid']), $f['root']);
        // VAL-8: primární kategorie je mimo zvolený strom, ale kartu má i v C1 → patří do C1.
        $c1 = array_values(array_filter($grouping['groups'], static fn (array $g): bool => $g['category_id'] === $f['c1']))[0];
        self::assertSame(2, $c1['count']);
        self::assertSame('119.00', $c1['value_total']);
    }

    public function testTopLevelGrouping(): void
    {
        $f = $this->fixture();
        $jobs = $this->container->get(StockValuationJobService::class);
        $grouping = $jobs->categoryGroups($f['sid'], $this->completedJob($f['sid']), null);

        self::assertNull($grouping['parent']);
        self::assertSame([
            'category:' . $f['root'] => [6, '398.00'],
            'category:' . $f['other'] => [2, '32.00'],
            'uncategorized:-' => [1, '17.00'],
        ], self::byLabel($grouping));
        self::assertSame('447.00', $grouping['value_total']);
    }

    public function testSynchronousValuationUsesTheSameRules(): void
    {
        $f = $this->fixture();
        $report = $this->container->get(StockReportService::class)->valuation($f['sid'], '2099-01-31', []);
        $grouping = $this->container->get(StockValuationCategoryGroups::class)->summarize($f['sid'], $f['root'], $report['items']);
        $jobs = $this->container->get(StockValuationJobService::class);
        self::assertSame(
            self::byLabel($jobs->categoryGroups($f['sid'], $this->completedJob($f['sid']), $f['root'])),
            self::byLabel($grouping),
        );
        self::assertSame($report['totals']['value_total'], $grouping['value_total']);
    }

    public function testRejectsForeignOrInvalidParent(): void
    {
        $f = $this->fixture();
        $foreign = $this->createSupplier();
        $foreignCategory = $this->category($foreign, 'VAL-FOREIGN');
        $jobs = $this->container->get(StockValuationJobService::class);
        $jobId = $this->completedJob($f['sid']);
        try {
            $jobs->categoryGroups($f['sid'], $jobId, $foreignCategory);
            self::fail('Kategorie jiné firmy musí být odmítnuta.');
        } catch (StockException $e) {
            self::assertSame(422, $e->httpStatus);
        }
        self::assertNull(StockValuationCategoryGroups::normalize([]));
        self::assertSame(['parent_id' => null], StockValuationCategoryGroups::normalize(['group_by' => 'category']));
        self::assertSame(['parent_id' => 5], StockValuationCategoryGroups::normalize(['group_by' => 'category', 'group_parent_id' => '5']));
        foreach ([['group_by' => 'warehouse'], ['group_by' => 'category', 'group_parent_id' => 'x']] as $input) {
            try {
                StockValuationCategoryGroups::normalize($input);
                self::fail('Očekávána chyba validace pro ' . json_encode($input));
            } catch (StockException $e) {
                self::assertSame(422, $e->httpStatus);
            }
        }
    }

    public function testExportsContainCategorySummary(): void
    {
        $f = $this->fixture();
        $jobs = $this->container->get(StockValuationJobService::class);
        $jobId = $this->completedJob($f['sid']);
        $data = $jobs->result($f['sid'], $jobId, 1, 500);
        $plain = $this->container->get(StockReportXlsxExporter::class)->valuation($data);
        self::assertSame(['Ocenění zásob'], $this->sheetNames($plain['bytes']));

        $data['category_groups'] = $jobs->categoryGroups($f['sid'], $jobId, $f['root']);
        $xlsx = $this->container->get(StockReportXlsxExporter::class)->valuation($data);
        self::assertSame(['Ocenění zásob', 'Podle kategorií'], $this->sheetNames($xlsx['bytes']));
        $rows = $this->sheetRows($xlsx['bytes'], 'Podle kategorií');
        self::assertSame(['Kategorie', 'Položek', 'Hodnota'], $rows[3]);
        self::assertSame(['Kategorie VAL-C1', 2, 119.0], $rows[4]);
        $totalRow = array_values(array_filter($rows, static fn (array $r): bool => ($r[0] ?? '') === 'CELKEM'));
        self::assertSame([['CELKEM', 9, 447.0]], $totalRow);

        $pdf = $this->container->get(StockValuationPdfRenderer::class)->render($data);
        self::assertStringStartsWith('%PDF', $pdf);
    }

    public function testApiAddsGroupsOnlyOnRequest(): void
    {
        $f = $this->fixture();
        $jobId = $this->completedJob($f['sid']);
        $action = $this->container->get(StockReportAction::class);

        $plain = $this->json($action->valuationJobResult($this->request($f['sid']), new Response(), ['id' => $jobId]));
        self::assertSame(['job_id', 'date', 'items', 'source_versions', 'totals', 'pagination'], array_keys($plain));

        $query = ['group_by' => 'category', 'group_parent_id' => (string) $f['root'], 'limit' => '2'];
        $grouped = $this->json($action->valuationJobResult($this->request($f['sid'], $query), new Response(), ['id' => $jobId]));
        self::assertCount(2, $grouped['items'], 'Stránkování řádků zůstává.');
        self::assertSame($grouped['totals']['value_total'], $grouped['category_groups']['value_total'], 'Souhrn je za celou úlohu, ne za stránku.');

        $sync = $this->json($action->valuation($this->request($f['sid'], ['date' => '2099-01-31', 'group_by' => 'category']), new Response()));
        self::assertSame($sync['totals']['value_total'], $sync['category_groups']['value_total']);
        self::assertArrayNotHasKey('category_groups', $this->json($action->valuation($this->request($f['sid'], ['date' => '2099-01-31']), new Response())));

        $bad = $action->valuationJobResult($this->request($f['sid'], ['group_by' => 'category', 'group_parent_id' => 'x']), new Response(), ['id' => $jobId]);
        self::assertSame(422, $bad->getStatusCode());

        $export = $action->export($this->request($f['sid'], ['format' => 'xlsx', 'job_id' => (string) $jobId, 'group_by' => 'category']), new Response(), ['name' => 'valuation']);
        self::assertSame(200, $export->getStatusCode());
        self::assertSame(['Ocenění zásob', 'Podle kategorií'], $this->sheetNames((string) $export->getBody()));
    }

    public function testJobOfAnotherCompanyIsNotExposed(): void
    {
        $f = $this->fixture();
        $jobId = $this->completedJob($f['sid']);
        $other = $this->createSupplier();
        $jobs = $this->container->get(StockValuationJobService::class);
        foreach ([fn () => $jobs->categoryGroups($other, $jobId, null), fn () => $jobs->result($other, $jobId)] as $call) {
            try {
                $call();
                self::fail('Úloha jiné firmy musí být nenalezena.');
            } catch (StockException $e) {
                self::assertSame(404, $e->httpStatus);
            }
        }
        $action = $this->container->get(StockReportAction::class);
        $response = $action->valuationJobResult($this->request($other, ['group_by' => 'category']), new Response(), ['id' => $jobId]);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('VAL-', (string) $response->getBody());
        $export = $action->export($this->request($other, ['format' => 'xlsx', 'job_id' => (string) $jobId, 'group_by' => 'category']), new Response(), ['name' => 'valuation']);
        self::assertSame(404, $export->getStatusCode());
        // Kategorie jiné firmy jako kořen seskupení vlastní úlohy: odmítnuto, ne prázdný souhrn.
        $response = $action->valuationJobResult($this->request($other, ['group_by' => 'category', 'group_parent_id' => (string) $f['root']]), new Response(), ['id' => $jobId]);
        self::assertSame(404, $response->getStatusCode());
    }

    /** @param array<string,string> $query */
    private function request(int $supplierId, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/stock/reports/valuation')
            ->withQueryParams($query)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function sheetNames(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stockval_') . '.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            return IOFactory::load($tmp)->getSheetNames();
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array<int,list<mixed>> */
    private function sheetRows(string $bytes, string $sheetName): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stockval_') . '.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            $rows = [];
            foreach (IOFactory::load($tmp)->getSheetByName($sheetName)->toArray(null, true, false, false) as $index => $row) {
                $rows[$index + 1] = array_values(array_filter($row, static fn ($v) => $v !== null));
            }
            return $rows;
        } finally {
            @unlink($tmp);
        }
    }
}
