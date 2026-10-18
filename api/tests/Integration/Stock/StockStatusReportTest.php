<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Pdf\StockStatusPdfRenderer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Service\Stock\StockReportXlsxExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sestava „Stav zásob“: filtr kategorie (včetně podkategorií) a volitelné sloupce
 * (prodejní cena, poznámka, vybraný parametr; průměrnou cenu a hodnotu lze skrýt).
 * Bez nových parametrů zůstává výstup beze změny.
 */
#[Group('integration')]
final class StockStatusReportTest extends StockTestCase
{
    use \MyInvoice\Tests\Support\StockCategoryFixtureTrait;

    private function report(): StockReportService
    {
        return $this->container->get(StockReportService::class);
    }

    public function testDefaultCallKeepsTheOriginalShape(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'ST-DEFAULT');
        $this->levels->setLevel($sid, $wh, $item, 2_000, 3_000);

        $data = $this->report()->status($sid, StockReportService::normalizeStatusFilters([]));

        self::assertSame(['items', 'totals'], array_keys($data));
        self::assertSame([
            'warehouse_id', 'stock_item_id', 'qty', 'value_total', 'avg_unit_cost', 'sku', 'name', 'unit',
            'item_type', 'min_qty', 'is_active', 'warehouse_code', 'warehouse_name',
        ], array_keys($data['items'][0]));
        self::assertSame(['value_total' => '30.00', 'count' => 1], $data['totals']);
    }

    public function testCategoryFilterIncludesSubcategoriesOnly(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $root = $this->category($sid, 'ST-ROOT');
        $child = $this->category($sid, 'ST-CHILD', $root);
        $grandchild = $this->category($sid, 'ST-GRAND', $child);
        $other = $this->category($sid, 'ST-OTHER');
        $inRoot = $this->item($sid, 'ST-IN-ROOT');
        $inGrand = $this->item($sid, 'ST-IN-GRAND');
        $inBoth = $this->item($sid, 'ST-IN-BOTH');
        $outside = $this->item($sid, 'ST-OUTSIDE');
        $none = $this->item($sid, 'ST-NONE');
        $this->assignCategory($sid, $inRoot, $root, true);
        $this->assignCategory($sid, $inGrand, $grandchild, true);
        $this->assignCategory($sid, $inBoth, $child, true);
        $this->assignCategory($sid, $inBoth, $grandchild);
        $this->assignCategory($sid, $outside, $other, true);
        foreach ([$inRoot, $inGrand, $inBoth, $outside, $none] as $item) {
            $this->levels->setLevel($sid, $wh, $item, 1_000, 1_000);
        }

        $all = $this->report()->status($sid, StockReportService::normalizeStatusFilters(['category_id' => (string) $root]));
        self::assertSame(['ST-IN-BOTH', 'ST-IN-GRAND', 'ST-IN-ROOT'], array_column($all['items'], 'sku'));
        self::assertSame(['value_total' => '30.00', 'count' => 3], $all['totals']);
        self::assertSame(['items', 'totals'], array_keys($all), 'Samotný filtr nemění tvar odpovědi.');

        $sub = $this->report()->status($sid, StockReportService::normalizeStatusFilters(['category_id' => (string) $child]));
        self::assertSame(['ST-IN-BOTH', 'ST-IN-GRAND'], array_column($sub['items'], 'sku'), 'Karta ve dvou podkategoriích je jen jednou.');
    }

    public function testCategoryOfAnotherCompanyFindsNothing(): void
    {
        $sid = $this->createSupplier();
        $foreign = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $foreignCategory = $this->category($foreign, 'ST-FOREIGN');
        $item = $this->item($sid, 'ST-MINE');
        $this->levels->setLevel($sid, $wh, $item, 1_000, 1_000);

        $data = $this->report()->status($sid, StockReportService::normalizeStatusFilters(['category_id' => (string) $foreignCategory]));
        self::assertSame([], $data['items']);
    }

    public function testOptionalColumnsAddSalePriceNoteAndParameter(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $first = $this->item($sid, 'ST-COL-1');
        $second = $this->item($sid, 'ST-COL-2');
        $this->db->pdo()->prepare('UPDATE stock_items SET sale_price_without_vat = ?, note = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['1250.50', 'Vitrína u vchodu', $sid, $first]);
        $colors = $this->attribute($sid, 'BARVA', 'enum');
        $red = $this->attributeOption($sid, $colors, 'RED', 'Červená', 2);
        $black = $this->attributeOption($sid, $colors, 'BLACK', 'Černá', 1);
        $this->attributeValue($sid, $first, $colors, ['option_id' => $red], 1);
        $this->attributeValue($sid, $first, $colors, ['option_id' => $black], 0);
        $this->levels->setLevel($sid, $wh, $first, 1_000, 1_000);
        $this->levels->setLevel($sid, $wh, $second, 1_000, 1_000);

        $data = $this->report()->status($sid, StockReportService::normalizeStatusFilters([
            'columns' => 'sale_price,note', 'attribute_id' => (string) $colors,
        ]));

        self::assertSame(['sale_price', 'note', 'attribute'], $data['columns']);
        self::assertSame(['id' => $colors, 'code' => 'BARVA', 'name' => 'Parametr BARVA', 'unit' => null, 'data_type' => 'enum'], $data['attribute']);
        $bySku = array_column($data['items'], null, 'sku');
        self::assertSame('1250.50', $bySku['ST-COL-1']['sale_price']);
        self::assertSame('Vitrína u vchodu', $bySku['ST-COL-1']['note']);
        self::assertSame('Černá, Červená', $bySku['ST-COL-1']['attribute_value'], 'Více hodnot v pořadí karty, popisky voleb.');
        self::assertNull($bySku['ST-COL-2']['sale_price']);
        self::assertNull($bySku['ST-COL-2']['note']);
        self::assertNull($bySku['ST-COL-2']['attribute_value']);
        self::assertSame('20.00', $data['totals']['value_total'], 'Skryté sloupce nemění součet.');
    }

    public function testParameterColumnFormatsTextNumberAndBool(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $text = $this->attribute($sid, 'VIN', 'text');
        $number = $this->attribute($sid, 'OBJEM', 'number', 'ccm');
        $bool = $this->attribute($sid, 'NOVY', 'bool');
        $item = $this->item($sid, 'ST-FMT');
        $this->attributeValue($sid, $item, $text, ['value_text' => 'ZDM1234567890']);
        $this->attributeValue($sid, $item, $number, ['value_num' => '1100.500000']);
        $this->attributeValue($sid, $item, $bool, ['value_bool' => true]);
        $this->levels->setLevel($sid, $wh, $item, 1_000, 1_000);

        $read = fn (int $attributeId): ?string => $this->report()
            ->status($sid, StockReportService::normalizeStatusFilters(['attribute_id' => (string) $attributeId]))['items'][0]['attribute_value'];

        self::assertSame('ZDM1234567890', $read($text));
        self::assertSame('1100.5', $read($number));
        self::assertSame('true', $read($bool), 'API vrací neutrální hodnotu, překlad dělá UI a export.');
    }

    public function testParameterColumnMatchesCatalogListFormat(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'ST-SSOT');
        $colors = $this->attribute($sid, 'SSOT-BARVA', 'enum');
        $red = $this->attributeOption($sid, $colors, 'RED', 'Červená', 2);
        $black = $this->attributeOption($sid, $colors, 'BLACK', 'Černá', 1);
        // Shodné pořadí na kartě: rozhoduje pořadí volby, ne pořadí zápisu.
        $this->attributeValue($sid, $item, $colors, ['option_id' => $red]);
        $this->attributeValue($sid, $item, $colors, ['option_id' => $black]);
        $bool = $this->attribute($sid, 'SSOT-NOVY', 'bool');
        $this->attributeValue($sid, $item, $bool, ['value_bool' => true]);
        $this->levels->setLevel($sid, $wh, $item, 1_000, 1_000);

        $items = $this->container->get(\MyInvoice\Repository\StockItemRepository::class);
        foreach ([$colors => 'Černá, Červená', $bool => 'true'] as $attributeId => $expected) {
            $status = $this->report()->status($sid, StockReportService::normalizeStatusFilters(['attribute_id' => (string) $attributeId]));
            [$listed] = $items->listPaged($sid, ['display_attribute_id' => $attributeId], 50, 0);
            self::assertSame($expected, $status['items'][0]['attribute_value']);
            self::assertSame($expected, $listed[0]['display_attribute_value'], 'Seznam karet a Stav zásob mají jeden formát.');
        }
    }

    public function testParameterAndCategoryGroupsNeedEshopPermission(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'ST-PERM');
        $vin = $this->attribute($sid, 'PERM-VIN', 'text');
        $this->attributeValue($sid, $item, $vin, ['value_text' => 'TAJNY-VIN']);
        $this->assignCategory($sid, $item, $this->category($sid, 'PERM-KAT'), true);
        $this->levels->setLevel($sid, $wh, $item, 1_000, 1_000);

        $action = $this->container->get(\MyInvoice\Action\Stock\StockReportAction::class);
        $call = function (string $method, array $query, array $permissions) use ($action, $sid): array {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/stock/reports/' . $method)
                ->withQueryParams($query)
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(0, 'Skladník', 'staff', true, $permissions));
            $response = $action->{$method}($request, new \Slim\Psr7\Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        };
        $stockOnly = ['stock' => \MyInvoice\Security\AccessLevel::READ->value];
        $withEshop = $stockOnly + ['eshop' => \MyInvoice\Security\AccessLevel::READ->value];

        $status = $call('status', ['attribute_id' => (string) $vin], $withEshop);
        self::assertSame('TAJNY-VIN', $status['items'][0]['attribute_value']);
        $status = $call('status', ['attribute_id' => (string) $vin], $stockOnly);
        self::assertSame(['items', 'totals'], array_keys($status), 'Bez práva e-shop se parametr ignoruje.');
        self::assertArrayNotHasKey('attribute_value', $status['items'][0]);

        self::assertArrayHasKey('category_groups', $call('valuation', ['group_by' => 'category'], $withEshop));
        self::assertArrayNotHasKey('category_groups', $call('valuation', ['group_by' => 'category'], $stockOnly),
            'Názvy kategorií spravuje e-shop, bez práva se souhrn vynechá.');
    }

    public function testColumnsCanHideCostAndValue(): void
    {
        $sid = $this->createSupplier();
        $data = $this->report()->status($sid, StockReportService::normalizeStatusFilters(['columns' => '']));
        self::assertSame([], $data['columns']);
        self::assertNull($data['attribute']);
        self::assertSame([], $this->report()->status($sid, StockReportService::normalizeStatusFilters(['columns' => 'none']))['columns']);
        $data = $this->report()->status($sid, StockReportService::normalizeStatusFilters(['columns' => 'value_total']));
        self::assertSame(['value_total'], $data['columns']);
    }

    public function testInvalidParametersAreRejected(): void
    {
        foreach ([['columns' => 'avg_unit_cost,secret'], ['category_id' => 'abc'], ['attribute_id' => '0']] as $input) {
            try {
                StockReportService::normalizeStatusFilters($input);
                self::fail('Očekávána chyba validace pro ' . json_encode($input));
            } catch (StockException $e) {
                self::assertSame(422, $e->httpStatus);
            }
        }
        $sid = $this->createSupplier();
        $foreign = $this->createSupplier();
        $foreignAttribute = $this->attribute($foreign, 'CIZI', 'text');
        $this->expectException(StockException::class);
        $this->report()->status($sid, StockReportService::normalizeStatusFilters(['attribute_id' => (string) $foreignAttribute]));
    }

    public function testAttributeOfAnotherCompanyDoesNotLeakValues(): void
    {
        $sid = $this->createSupplier();
        $foreign = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'ST-LEAK');
        $this->levels->setLevel($sid, $wh, $item, 1_000, 1_000);
        $foreignAttribute = $this->attribute($foreign, 'TAJNE', 'text');
        $foreignItem = $this->item($foreign, 'ST-LEAK-FOREIGN');
        $this->attributeValue($foreign, $foreignItem, $foreignAttribute, ['value_text' => 'CIZI-HODNOTA']);
        // Hodnota cizí firmy zapsaná omylem u vlastní karty se nesmí ukázat ani tak.
        $this->attributeValue($sid, $item, $foreignAttribute, ['value_text' => 'CIZI-U-MNE']);

        try {
            $this->report()->status($sid, StockReportService::normalizeStatusFilters(['attribute_id' => (string) $foreignAttribute]));
            self::fail('Parametr jiné firmy musí být odmítnut.');
        } catch (StockException $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertStringNotContainsString('TAJNE', $e->getMessage());
        }

        $action = $this->container->get(\MyInvoice\Action\Stock\StockReportAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/stock/reports/status')
            ->withQueryParams(['attribute_id' => (string) $foreignAttribute])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = $action->status($request, new \Slim\Psr7\Response());
        self::assertSame(422, $response->getStatusCode());
        self::assertStringNotContainsString('CIZI', (string) $response->getBody());

        $own = $this->attribute($sid, 'VLASTNI', 'text');
        $data = $this->report()->status($sid, StockReportService::normalizeStatusFilters(['attribute_id' => (string) $own]));
        self::assertNull($data['items'][0]['attribute_value'], 'Hodnoty jiného parametru se do sloupce nepřimíchají.');
    }

    public function testExportTranslatesBoolParameter(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $bool = $this->attribute($sid, 'NOVY', 'bool');
        $item = $this->item($sid, 'ST-BOOL');
        $this->attributeValue($sid, $item, $bool, ['value_bool' => false]);
        $this->levels->setLevel($sid, $wh, $item, 1_000, 1_000);
        $data = $this->report()->status($sid, StockReportService::normalizeStatusFilters(['attribute_id' => (string) $bool]));
        self::assertSame('false', $data['items'][0]['attribute_value']);
        $rows = $this->sheetRows($this->container->get(StockReportXlsxExporter::class)->status($data)['bytes']);
        self::assertSame('ne', $rows[4][3]);
    }

    public function testExportsUseDynamicHeader(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->item($sid, 'ST-EXPORT');
        $vin = $this->attribute($sid, 'VIN', 'text');
        $this->attributeValue($sid, $item, $vin, ['value_text' => 'VIN-001']);
        $this->db->pdo()->prepare('UPDATE stock_items SET sale_price_without_vat = 99, note = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['Poznámka karty', $sid, $item]);
        $this->levels->setLevel($sid, $wh, $item, 1_000, 1_000);
        $xlsx = $this->container->get(StockReportXlsxExporter::class);

        $default = $this->sheetRows($xlsx->status($this->report()->status($sid, StockReportService::normalizeStatusFilters([])))['bytes']);
        self::assertSame(['Sklad', 'SKU', 'Název', 'Typ', 'MJ', 'Množství', 'Prům. cena', 'Hodnota'], $default[3]);

        $data = $this->report()->status($sid, StockReportService::normalizeStatusFilters([
            'columns' => 'sale_price,note', 'attribute_id' => (string) $vin,
        ]));
        $rows = $this->sheetRows($xlsx->status($data)['bytes']);
        self::assertSame(['Sklad', 'SKU', 'Název', 'Parametr VIN', 'Typ', 'MJ', 'Množství', 'Prodejní cena', 'Poznámka'], $rows[3]);
        self::assertSame(['HLAVNI', 'ST-EXPORT', 'Karta ST-EXPORT', 'VIN-001', 'goods', 'ks', 1.0, 99.0, 'Poznámka karty'], $rows[4]);
        self::assertSame('CELKEM (1 položek)', $rows[5][0]);

        $pdf = $this->container->get(StockStatusPdfRenderer::class)->render($data);
        self::assertStringStartsWith('%PDF', $pdf);
    }

    /** @return array<int,list<mixed>> */
    private function sheetRows(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stockstatus_') . '.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            $sheet = IOFactory::load($tmp)->getActiveSheet();
            $rows = [];
            foreach ($sheet->toArray(null, false, false, false) as $index => $row) {
                $rows[$index + 1] = array_values(array_filter($row, static fn ($v) => $v !== null));
            }
            return $rows;
        } finally {
            @unlink($tmp);
        }
    }
}
