<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Stock\CycleCountService;

final class CycleCountTest extends StockTestCase
{
    public function testDraftCreatedBeforeCutoffAndPostedAfterItIsExcludedFromSnapshot(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-DRAFT');
        $cycles = $this->container->get(CycleCountService::class);

        $lateReceipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-01', 'description' => 'Koncept před cutoffem',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '10']],
        ], $this->userId);
        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $lateReceipt['id'], $this->userId);

        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);

        self::assertSame('counting', $cycle['status']);
        self::assertSame('0.000', $cycle['lines'][0]['expected_qty']);
    }

    public function testDurableSnapshotUsesCutoffAndCloseReconcilesAgainstCurrentStock(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-1');
        $cycles = $this->container->get(CycleCountService::class);
        $jobs = $this->container->get(CatalogJobService::class);

        $receipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-01', 'description' => 'Počáteční stav',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '10']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $receipt['id'], $this->userId);

        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        self::assertSame('queued', $cycle['status']);
        self::assertNotNull($cycle['preparation_job_id']);

        $issue = $this->documents->create($supplierId, [
            'doc_type' => 'issue', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-02', 'description' => 'Pohyb po cut-off',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '2']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $issue['id'], $this->userId);

        $job = $cycles->tick($supplierId);
        self::assertSame('completed', $job['status']);
        self::assertSame('completed', $jobs->find($supplierId, (int) $cycle['preparation_job_id'])['status']);

        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        self::assertSame('counting', $cycle['status']);
        self::assertSame('5.000', $cycle['lines'][0]['expected_qty']);

        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '4', 'surplus_unit_cost' => '10',
        ]]);
        $closed = $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        self::assertSame('closed', $closed['status']);
        self::assertArrayHasKey('receipt', $closed['documents']);
        $stmt = $this->db->pdo()->prepare('SELECT qty FROM stock_levels WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?');
        $stmt->execute([$supplierId, $warehouseId, $itemId]);
        self::assertSame('4.000', $stmt->fetchColumn());
    }

    public function testRepeatedSaveOfSameCountDoesNotResetReferenceAfterMovement(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-SAVED');
        $cycles = $this->container->get(CycleCountService::class);

        $receipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-01', 'description' => 'Počáteční stav',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '10']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $receipt['id'], $this->userId);
        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '4', 'surplus_unit_cost' => '10',
        ]]);

        $lateReceipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-02', 'description' => 'Pohyb po uložení počtu',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '2', 'unit_cost' => '10']],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $lateReceipt['id'], $this->userId);
        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '4', 'surplus_unit_cost' => '10',
        ]]);
        $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        $stmt = $this->db->pdo()->prepare('SELECT qty FROM stock_levels WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?');
        $stmt->execute([$supplierId, $warehouseId, $itemId]);
        self::assertSame('6.000', $stmt->fetchColumn());
    }

    public function testWholeWarehouseTrackedDifferenceUsesRealSourceLocations(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-LOC');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'lot' WHERE supplier_id = ? AND id = ?")
            ->execute([$supplierId, $itemId]);
        $locationInsert = $this->db->pdo()->prepare('INSERT INTO warehouse_locations (supplier_id, warehouse_id, code, name) VALUES (?, ?, ?, ?)');
        $locationInsert->execute([$supplierId, $warehouseId, 'A-01', 'Lokace A']);
        $locationA = (int) $this->db->pdo()->lastInsertId();
        $locationInsert->execute([$supplierId, $warehouseId, 'B-01', 'Lokace B']);
        $locationB = (int) $this->db->pdo()->lastInsertId();
        $cycles = $this->container->get(CycleCountService::class);

        $receipt = $this->documents->create($supplierId, [
            'doc_type' => 'receipt', 'warehouse_id' => $warehouseId,
            'doc_date' => '2099-09-01', 'description' => 'Šarže ve dvou lokacích',
            'lines' => [['stock_item_id' => $itemId, 'qty' => '5', 'unit_cost' => '10', 'tracking_allocations' => [
                ['lot_code' => 'LOT-CYCLE', 'quantity' => '3', 'location_id' => $locationA],
                ['lot_code' => 'LOT-CYCLE', 'quantity' => '2', 'location_id' => $locationB],
            ]]],
        ], $this->userId);
        $this->documents->post($supplierId, (int) $receipt['id'], $this->userId);
        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '1',
        ]]);
        $closed = $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        $lineId = (int) $closed['documents']['issue']['lines'][0]['id'];
        $stmt = $this->db->pdo()->prepare('SELECT location_id, direction, quantity FROM stock_tracking_allocations WHERE supplier_id = ? AND stock_document_line_id = ? ORDER BY location_id');
        $stmt->execute([$supplierId, $lineId]);
        $allocations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $allocations);
        self::assertSame([$locationA, $locationB], array_map('intval', array_column($allocations, 'location_id')));
        self::assertSame(['out', 'out'], array_column($allocations, 'direction'));
        self::assertSame(['3.000', '1.000'], array_column($allocations, 'quantity'));
    }

    public function testCountsCannotBeChangedAfterClose(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'CYCLE-CLOSED');
        $cycles = $this->container->get(CycleCountService::class);
        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'item_ids' => [$itemId],
        ], $this->userId);
        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
            'id' => $cycle['lines'][0]['id'], 'counted_qty' => '0',
        ]]);
        $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        try {
            $cycles->updateCounts($supplierId, (int) $cycle['id'], [[
                'id' => $cycle['lines'][0]['id'], 'counted_qty' => '9',
            ]]);
            self::fail('Uzavřenou inventuru nesmí být možné změnit.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame('cycle_count_conflict', $e->errorCode);
        }
        $closed = $cycles->get($supplierId, (int) $cycle['id']);
        self::assertSame('0.000', $closed['lines'][0]['counted_qty']);
    }

    public function testWholeWarehouseCountSkipsItemsThatWereNeverInTheWarehouse(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $otherWarehouse = $this->warehouse($supplierId, 'OTHER', false);
        $here = $this->item($supplierId, 'CYCLE-HERE');
        $elsewhere = $this->item($supplierId, 'CYCLE-ELSEWHERE');
        $never = $this->item($supplierId, 'CYCLE-NEVER');
        $this->receiveStock($supplierId, $warehouseId, $here, '2.000', 10.0, '2099-09-01');
        $this->receiveStock($supplierId, $otherWarehouse, $elsewhere, '2.000', 10.0, '2099-09-01');
        $cycles = $this->container->get(CycleCountService::class);

        $cycle = $cycles->create($supplierId, ['warehouse_id' => $warehouseId, 'take_date' => '2099-09-02'], $this->userId);
        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        self::assertSame('counting', $cycle['status']);
        self::assertSame([$here], array_map(static fn (array $l): int => (int) $l['stock_item_id'], $cycle['lines']),
            'Celý sklad = karty, které na něm byly; ne karta cizího skladu ani nikdy nenaskladněná.');

        $explicit = $cycles->create($supplierId, [
            'warehouse_id' => $otherWarehouse, 'take_date' => '2099-09-02', 'item_ids' => [$never],
        ], $this->userId);
        $cycles->tick($supplierId);
        self::assertCount(1, $cycles->get($supplierId, (int) $explicit['id'])['lines'], 'Výslovně vybraná karta řádek dostane.');
    }

    public function testCategoryScopeCountsSubtreeAndCloseLeavesOtherItemsUntouched(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $root = $this->category($supplierId, 'CC-ROOT');
        $child = $this->category($supplierId, 'CC-CHILD', $root);
        $other = $this->category($supplierId, 'CC-OTHER');
        $inRoot = $this->item($supplierId, 'CC-IN-ROOT');
        $inChild = $this->item($supplierId, 'CC-IN-CHILD');
        $inOther = $this->item($supplierId, 'CC-IN-OTHER');
        $uncategorized = $this->item($supplierId, 'CC-NONE');
        $this->assignCategory($supplierId, $inRoot, $root);
        $this->assignCategory($supplierId, $inChild, $child);
        $this->assignCategory($supplierId, $inOther, $other);
        foreach ([$inRoot, $inChild, $inOther, $uncategorized] as $itemId) {
            $this->receiveStock($supplierId, $warehouseId, $itemId, '5.000', 10.0, '2099-09-01');
        }
        $cycles = $this->container->get(CycleCountService::class);

        $cycle = $cycles->create($supplierId, ['warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'category_id' => $root], $this->userId);
        self::assertSame($root, $cycle['category_id']);
        self::assertSame('Kategorie CC-ROOT', $cycle['category_name']);
        $cycles->tick($supplierId);
        $cycle = $cycles->get($supplierId, (int) $cycle['id']);
        self::assertSame('counting', $cycle['status']);
        $lineItems = array_map(static fn (array $l): int => (int) $l['stock_item_id'], $cycle['lines']);
        sort($lineItems);
        self::assertSame([$inRoot, $inChild], $lineItems, 'Rozsah = kategorie včetně podkategorií, nic jiného.');
        $listed = array_values(array_filter($cycles->list($supplierId), static fn (array $c): bool => $c['id'] === $cycle['id']));
        self::assertSame($root, $listed[0]['category_id']);
        self::assertSame('Kategorie CC-ROOT', $listed[0]['category_name']);

        $cycles->updateCounts($supplierId, (int) $cycle['id'], array_map(static fn (array $l): array => [
            'id' => $l['id'], 'counted_qty' => (int) $l['stock_item_id'] === $inRoot ? '3' : '7', 'surplus_unit_cost' => '10',
        ], $cycle['lines']));
        $cycles->close($supplierId, (int) $cycle['id'], $this->userId);

        self::assertSame('3.000', $this->qty($supplierId, $warehouseId, $inRoot));
        self::assertSame('7.000', $this->qty($supplierId, $warehouseId, $inChild));
        self::assertSame('5.000', $this->qty($supplierId, $warehouseId, $inOther), 'Karta mimo rozsah se nesmí změnit.');
        self::assertSame('5.000', $this->qty($supplierId, $warehouseId, $uncategorized), 'Karta bez kategorie se nesmí změnit.');
        $touched = $this->db->pdo()->prepare("SELECT COUNT(*) FROM stock_document_lines l JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id WHERE l.supplier_id = ? AND d.origin = 'inventory' AND l.stock_item_id IN (?, ?)");
        $touched->execute([$supplierId, $inOther, $uncategorized]);
        self::assertSame(0, (int) $touched->fetchColumn());
    }

    public function testCategoryScopeNarrowsExplicitItemIds(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $root = $this->category($supplierId, 'CCX-ROOT');
        $inside = $this->item($supplierId, 'CCX-IN');
        $outside = $this->item($supplierId, 'CCX-OUT');
        $this->assignCategory($supplierId, $inside, $root);
        $cycles = $this->container->get(CycleCountService::class);

        $cycle = $cycles->create($supplierId, [
            'warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'category_id' => $root, 'item_ids' => [$inside, $outside],
        ], $this->userId);
        $cycles->tick($supplierId);

        self::assertSame([$inside], array_map(static fn (array $l): int => (int) $l['stock_item_id'], $cycles->get($supplierId, (int) $cycle['id'])['lines']),
            'Výslovně vybraná karta mimo kategorii do inventury nepatří.');
    }

    public function testCategoryOfAnotherCompanyIsRejected(): void
    {
        $supplierId = $this->createSupplier();
        $foreignSupplier = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $foreignCategory = $this->category($foreignSupplier, 'CCF-ROOT');
        $cycles = $this->container->get(CycleCountService::class);

        foreach ([$foreignCategory, 999999999, 'abc'] as $categoryId) {
            try {
                $cycles->create($supplierId, ['warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'category_id' => $categoryId], $this->userId);
                self::fail('Cizí nebo neexistující kategorie musí být odmítnuta.');
            } catch (\MyInvoice\Service\Stock\StockException $e) {
                self::assertSame('invalid_category', $e->errorCode);
                self::assertSame(422, $e->httpStatus);
            }
        }
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_cycle_counts WHERE supplier_id = ?');
        $count->execute([$supplierId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testWithoutCategoryTheCycleHasNoScope(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $cycles = $this->container->get(CycleCountService::class);

        $cycle = $cycles->create($supplierId, ['warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'category_id' => null], $this->userId);

        self::assertNull($cycle['category_id']);
        self::assertNull($cycle['category_name']);
    }

    public function testDeletingTheCategoryKeepsTheCycleCount(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $root = $this->category($supplierId, 'CCD-ROOT');
        $cycles = $this->container->get(CycleCountService::class);
        $cycle = $cycles->create($supplierId, ['warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'category_id' => $root], $this->userId);

        $this->db->pdo()->prepare('DELETE FROM stock_categories WHERE supplier_id = ? AND id = ?')->execute([$supplierId, $root]);

        self::assertNull($cycles->get($supplierId, (int) $cycle['id'])['category_id'], 'Smazání kategorie inventuru neblokuje ani nesmaže.');
    }

    public function testCategoryScopeDoesNotMatchSiblingWhosePathSharesTheIdPrefix(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $root = $this->category($supplierId, 'CCP-ROOT');
        $scope = $this->category($supplierId, 'CCP-SCOPE', $root);
        // Sourozenec s id začínajícím stejnými číslicemi: /root/12/ nesmí pokrýt /root/123/.
        $pdo = $this->db->pdo();
        $prefixed = null;
        for ($digit = 0; $digit <= 9 && $prefixed === null; $digit++) {
            $candidate = (int) ($scope . $digit);
            $exists = $pdo->prepare('SELECT 1 FROM stock_categories WHERE id = ?');
            $exists->execute([$candidate]);
            if ($exists->fetchColumn() === false) $prefixed = $candidate;
        }
        self::assertNotNull($prefixed);
        $pdo->prepare('INSERT INTO stock_categories (id, supplier_id, parent_id, code, name, path, depth) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([$prefixed, $supplierId, $root, 'CCP-PREFIXED', 'Kategorie CCP-PREFIXED', '/' . $root . '/' . $prefixed . '/']);
        $inScope = $this->item($supplierId, 'CCP-IN');
        $inPrefixed = $this->item($supplierId, 'CCP-PREFIXED-ITEM');
        $this->assignCategory($supplierId, $inScope, $scope);
        $this->assignCategory($supplierId, $inPrefixed, $prefixed);
        $this->receiveStock($supplierId, $warehouseId, $inScope, '1.000', 10.0, '2099-09-01');
        $this->receiveStock($supplierId, $warehouseId, $inPrefixed, '1.000', 10.0, '2099-09-01');
        $cycles = $this->container->get(CycleCountService::class);

        $cycle = $cycles->create($supplierId, ['warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'category_id' => $scope], $this->userId);
        $cycles->tick($supplierId);

        self::assertSame([$inScope], array_map(static fn (array $l): int => (int) $l['stock_item_id'], $cycles->get($supplierId, (int) $cycle['id'])['lines']),
            'Podstrom se porovnává po celých segmentech cesty, ne podle prefixu id.');
    }

    public function testCategoryScopeSelectsTheSameItemsAsWholeWarehouseRestrictedToTheCategory(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $otherWarehouse = $this->warehouse($supplierId, 'OTHER', false);
        $category = $this->category($supplierId, 'CCS-ROOT');
        $oldStock = $this->item($supplierId, 'CCS-OLD');
        $elsewhere = $this->item($supplierId, 'CCS-ELSEWHERE');
        $never = $this->item($supplierId, 'CCS-NEVER');
        foreach ([$oldStock, $elsewhere, $never] as $itemId) $this->assignCategory($supplierId, $itemId, $category);
        // Zásoba z dávného příjmu bez dalších pohybů; druhá karta má zásobu jen na jiném skladu.
        $this->receiveStock($supplierId, $warehouseId, $oldStock, '4.000', 10.0, '2090-01-10');
        $this->receiveStock($supplierId, $otherWarehouse, $elsewhere, '2.000', 10.0, '2099-09-01');
        $cycles = $this->container->get(CycleCountService::class);

        $whole = $cycles->create($supplierId, ['warehouse_id' => $warehouseId, 'take_date' => '2099-09-02'], $this->userId);
        $cycles->tick($supplierId);
        $wholeLines = $cycles->get($supplierId, (int) $whole['id'])['lines'];
        $cycles->close($supplierId, (int) $whole['id'], $this->userId);

        $scoped = $cycles->create($supplierId, ['warehouse_id' => $warehouseId, 'take_date' => '2099-09-02', 'category_id' => $category], $this->userId);
        $cycles->tick($supplierId);
        $scopedLines = $cycles->get($supplierId, (int) $scoped['id'])['lines'];

        $project = static fn (array $lines): array => array_map(static fn (array $l): array => [(int) $l['stock_item_id'], $l['expected_qty']], $lines);
        self::assertSame([[$oldStock, '4.000']], $project($scopedLines));
        self::assertSame($project(array_values(array_filter($wholeLines, static fn (array $l): bool => in_array((int) $l['stock_item_id'], [$oldStock, $elsewhere, $never], true)))), $project($scopedLines),
            'Kategorie jen zužuje výběr celého skladu, pravidla zařazení karet zůstávají stejná.');
    }

    private function qty(int $supplierId, int $warehouseId, int $itemId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT qty FROM stock_levels WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?');
        $stmt->execute([$supplierId, $warehouseId, $itemId]);
        return (string) $stmt->fetchColumn();
    }

    private function category(int $supplierId, string $code, ?int $parentId = null): int
    {
        $pdo = $this->db->pdo();
        $parentPath = '/';
        if ($parentId !== null) {
            $stmt = $pdo->prepare('SELECT path FROM stock_categories WHERE id = ?');
            $stmt->execute([$parentId]);
            $parentPath = (string) $stmt->fetchColumn();
        }
        $pdo->prepare('INSERT INTO stock_categories (supplier_id, parent_id, code, name, path) VALUES (?, ?, ?, ?, ?)')
            ->execute([$supplierId, $parentId, $code, 'Kategorie ' . $code, '']);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE stock_categories SET path = ? WHERE id = ?')->execute([$parentPath . $id . '/', $id]);
        return $id;
    }

    private function assignCategory(int $supplierId, int $itemId, int $categoryId): void
    {
        $this->db->pdo()->prepare('INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id, is_primary) VALUES (?, ?, ?, 1)')
            ->execute([$supplierId, $itemId, $categoryId]);
    }
}
