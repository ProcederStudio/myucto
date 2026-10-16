<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class StockCatalogListTest extends StockTestCase
{
    private StockItemAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(StockItemAction::class);
    }

    public function testPageContainsCompleteFiveWarehouseAggregateAndUsesGlobalQuantitySort(): void
    {
        $sid = $this->createSupplier();
        $warehouses = [];
        for ($i = 1; $i <= 5; $i++) {
            $warehouses[] = $this->warehouse($sid, 'KAT-' . $i, $i === 1);
        }

        $larger = $this->item($sid, 'KAT-LARGER');
        $smaller = $this->item($sid, 'KAT-SMALLER');
        foreach ($warehouses as $warehouseId) {
            $this->levels->setLevel($sid, $warehouseId, $larger, 1_000, 2_000);
            $this->levels->setLevel($sid, $warehouseId, $smaller, 200, 300);
        }
        for ($i = 1; $i <= 48; $i++) {
            $itemId = $this->item($sid, 'KAT-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            foreach ($warehouses as $warehouseId) {
                $this->levels->setLevel($sid, $warehouseId, $itemId, 400 + $i, 800 + $i);
            }
        }

        [$rows, $total] = $this->itemsRepo->listPaged($sid, [
            'sort' => 'qty',
            'direction' => 'asc',
        ], 1, 0);

        self::assertSame(50, $total);
        self::assertCount(1, $rows);
        self::assertSame($smaller, $rows[0]['id']);
        self::assertSame('1.000', $rows[0]['qty']);
        self::assertSame('15.00', $rows[0]['value_total']);
    }

    public function testWarehouseAggregateIsScopedBeforeBelowMinimumFilter(): void
    {
        $sid = $this->createSupplier();
        $firstWarehouse = $this->warehouse($sid, 'KAT-W1');
        $secondWarehouse = $this->warehouse($sid, 'KAT-W2', false);
        $item = $this->item($sid, 'KAT-WAREHOUSE');
        $this->db->pdo()->prepare('UPDATE stock_items SET min_qty = 5 WHERE supplier_id = ? AND id = ?')
            ->execute([$sid, $item]);
        $this->levels->setLevel($sid, $firstWarehouse, $item, 2_000, 2_000);
        $this->levels->setLevel($sid, $secondWarehouse, $item, 10_000, 10_000);

        [$rows, $total] = $this->itemsRepo->listPaged($sid, [
            'warehouse_id' => $firstWarehouse,
            'only_below_min' => true,
        ], 50, 0);

        self::assertSame(1, $total);
        self::assertSame($item, $rows[0]['id']);
        self::assertSame('2.000', $rows[0]['qty']);
    }

    public function testCatalogFiltersCoverCategorySubtreeVendorTagAndTypedAttribute(): void
    {
        $sid = $this->createSupplier();
        $matching = $this->item($sid, 'KAT-MATCH');
        $other = $this->item($sid, 'KAT-OTHER');
        $manufacturerId = $this->insertManufacturer($sid, 'KAT-M');
        $vendorId = $this->client($sid, 'Katalogový dodavatel');
        $rootId = $this->insertCategory($sid, null, 'KAT-ROOT', '/');
        $this->db->pdo()->prepare('UPDATE stock_categories SET path = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['/' . $rootId . '/', $sid, $rootId]);
        $childId = $this->insertCategory($sid, $rootId, 'KAT-CHILD', '/' . $rootId . '/');
        $this->db->pdo()->prepare('UPDATE stock_categories SET path = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['/' . $rootId . '/' . $childId . '/', $sid, $childId]);
        $tagId = $this->insertTag($sid, 'KAT-TAG');
        $attributeId = $this->insertAttribute($sid, 'KAT-NUM');

        $this->db->pdo()->prepare('UPDATE stock_items SET manufacturer_id = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$manufacturerId, $sid, $matching]);
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id, is_primary, display_order)
             VALUES (?, ?, ?, 1, 0)'
        )->execute([$sid, $matching, $childId]);
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_tags (supplier_id, stock_item_id, tag_id) VALUES (?, ?, ?)'
        )->execute([$sid, $matching, $tagId]);
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_attribute_values
                (supplier_id, stock_item_id, attribute_id, value_num, display_order)
             VALUES (?, ?, ?, 12.5, 0)'
        )->execute([$sid, $matching, $attributeId]);
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_vendors
                (supplier_id, stock_item_id, client_id, currency_code, availability_state, data_source, is_active)
             VALUES (?, ?, ?, "CZK", "in_stock", "manual", 1)'
        )->execute([$sid, $matching, $vendorId]);

        [$rows, $total] = $this->itemsRepo->listPaged($sid, [
            'manufacturer_id' => $manufacturerId,
            'category_id' => $rootId,
            'vendor_id' => $vendorId,
            'tag_ids' => [$tagId],
            'attribute_filters' => [[
                'attribute_id' => $attributeId,
                'value_num_min' => 12,
                'value_num_max' => 13,
            ]],
        ], 50, 0);

        self::assertSame(1, $total);
        self::assertSame([$matching], array_column($rows, 'id'));
        self::assertNotContains($other, array_column($rows, 'id'));
        $facets = $this->itemsRepo->facets($sid, ['category_id' => $rootId], 1);
        self::assertSame(1, $facets['total']);
        self::assertSame($manufacturerId, $facets['manufacturers']['items'][0]['id']);
        self::assertSame($vendorId, $facets['vendors']['items'][0]['id']);
        self::assertSame($tagId, $facets['tags']['items'][0]['id']);
        self::assertSame(1, $facets['categories']['items'][0]['count']);
        self::assertTrue($facets['categories']['truncated']);
        self::assertSame(1, $facets['availability'][1]['count']);
    }

    public function testMissingDataAndAvailabilityFiltersAreAppliedOnServer(): void
    {
        $sid = $this->createSupplier();
        $warehouseId = $this->warehouse($sid, 'KAT-MISS');
        $incomplete = $this->item($sid, 'KAT-INCOMPLETE');
        $complete = $this->item($sid, 'KAT-COMPLETE');
        $manufacturerId = $this->insertManufacturer($sid, 'KAT-COMPLETE-M');
        $this->db->pdo()->prepare(
            'UPDATE stock_items SET manufacturer_id = ?, ean = "1234567890123", sale_price_without_vat = 10
              WHERE supplier_id = ? AND id = ?'
        )->execute([$manufacturerId, $sid, $complete]);
        $this->levels->setLevel($sid, $warehouseId, $complete, 1_000, 1_000);

        [$rows, $total] = $this->itemsRepo->listPaged($sid, [
            'missing' => ['manufacturer', 'ean', 'price'],
            'availability' => 'out_of_stock',
        ], 50, 0);

        self::assertSame(1, $total);
        self::assertSame([$incomplete], array_column($rows, 'id'));
    }

    public function testListCanProjectOneAttributeAsDisplayColumn(): void
    {
        $sid = $this->createSupplier();
        $numeric = $this->item($sid, 'KAT-PARAM-NUM');
        $multi = $this->item($sid, 'KAT-PARAM-ENUM');
        $empty = $this->item($sid, 'KAT-PARAM-NONE');
        $numberAttribute = $this->insertAttribute($sid, 'KAT-PARAM-N');
        $this->db->pdo()->prepare(
            'INSERT INTO stock_attributes (supplier_id, code, name, data_type, is_multivalue)
             VALUES (?, "KAT-PARAM-E", "Barva", "enum", 1)'
        )->execute([$sid]);
        $enumAttribute = (int) $this->db->pdo()->lastInsertId();
        $options = [];
        foreach (['Modrá' => 20, 'Bílá' => 10] as $label => $order) {
            $this->db->pdo()->prepare(
                'INSERT INTO stock_attribute_options (supplier_id, attribute_id, code, label, display_order) VALUES (?, ?, ?, ?, ?)'
            )->execute([$sid, $enumAttribute, 'OPT-' . $order, $label, $order]);
            $options[$label] = (int) $this->db->pdo()->lastInsertId();
        }
        $value = $this->db->pdo()->prepare(
            'INSERT INTO stock_item_attribute_values
                (supplier_id, stock_item_id, attribute_id, option_id, value_num, display_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $value->execute([$sid, $numeric, $numberAttribute, null, '12.500000', 0]);
        $value->execute([$sid, $multi, $enumAttribute, $options['Modrá'], null, 1]);
        $value->execute([$sid, $multi, $enumAttribute, $options['Bílá'], null, 0]);

        $byId = static fn (array $rows): array => array_column($rows, 'display_attribute_value', 'id');

        [$numberRows] = $this->itemsRepo->listPaged($sid, ['display_attribute_id' => $numberAttribute], 50, 0);
        self::assertSame('12.5', $byId($numberRows)[$numeric]);
        self::assertNull($byId($numberRows)[$empty]);

        $api = $this->callList($sid, ['display_attribute_id' => (string) $enumAttribute, 'sort' => 'sku']);
        self::assertSame(200, $api['status']);
        self::assertSame('Bílá, Modrá', $byId($api['body']['data'])[$multi]);
        self::assertNull($byId($api['body']['data'])[$numeric]);

        $plain = $this->callList($sid, []);
        self::assertArrayNotHasKey('display_attribute_value', $plain['body']['data'][0]);

        $invalid = $this->callList($sid, ['display_attribute_id' => 'abc']);
        self::assertSame(400, $invalid['status']);

        // Parametr jiné firmy hodnotu nevrátí.
        $foreignAttribute = $this->insertAttribute($this->createSupplier(), 'KAT-PARAM-FOREIGN');
        $this->db->pdo()->prepare('UPDATE stock_item_attribute_values SET attribute_id = ? WHERE supplier_id = ? AND stock_item_id = ?')
            ->execute([$foreignAttribute, $sid, $numeric]);
        $foreign = $this->callList($sid, ['display_attribute_id' => (string) $foreignAttribute]);
        self::assertSame(200, $foreign['status']);
        self::assertNull($byId($foreign['body']['data'])[$numeric]);

        // Parametry spravuje e-shop: bez práva ho číst se sloupec ignoruje.
        $stockOnly = new EffectiveRole(0, 'Skladník', 'staff', true, ['stock' => AccessLevel::READ->value]);
        $withoutEshop = $this->callList($sid, ['display_attribute_id' => (string) $enumAttribute], $stockOnly);
        self::assertSame(200, $withoutEshop['status']);
        self::assertArrayNotHasKey('display_attribute_value', $withoutEshop['body']['data'][0]);
    }

    public function testApiRejectsUnknownSortAndMalformedFilters(): void
    {
        $sid = $this->createSupplier();

        $unknownSort = $this->callList($sid, ['sort' => 'unknown']);
        self::assertSame(400, $unknownSort['status']);
        self::assertSame('validation_failed', $unknownSort['body']['error']['code']);

        $badAttributes = $this->callList($sid, ['attribute_filters' => '{bad']);
        self::assertSame(400, $badAttributes['status']);
        self::assertSame('validation_failed', $badAttributes['body']['error']['code']);

        $badTagIds = $this->callList($sid, ['tag_ids' => '1,nope,2']);
        self::assertSame(400, $badTagIds['status']);
        self::assertSame('validation_failed', $badTagIds['body']['error']['code']);
    }

    /** @param array<string,mixed> $query @return array{status:int,body:array<string,mixed>} */
    private function callList(int $supplierId, array $query, ?EffectiveRole $role = null): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/stock/items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }
        $response = $this->action->list($request, new Psr7Response());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($body) ? $body : [],
        ];
    }

    private function insertManufacturer(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO manufacturers (supplier_id, code, name) VALUES (?, ?, ?)'
        )->execute([$supplierId, $code, 'Výrobce ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertCategory(int $supplierId, ?int $parentId, string $code, string $path): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_categories (supplier_id, parent_id, code, name, path)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, $parentId, $code, 'Kategorie ' . $code, $path]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertTag(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_tags (supplier_id, code, name) VALUES (?, ?, ?)'
        )->execute([$supplierId, $code, 'Štítek ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertAttribute(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_attributes (supplier_id, code, name, data_type, is_filterable)
             VALUES (?, ?, ?, "number", 1)'
        )->execute([$supplierId, $code, 'Atribut ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
