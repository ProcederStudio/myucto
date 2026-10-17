<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Kategorie a parametry skladových karet pro integrační testy sestav
 * (materialized path jako v aplikaci: '/<id rodiče>/<id>/').
 * Používá `$this->db` ze {@see \MyInvoice\Tests\Integration\Stock\StockTestCase}.
 */
trait StockCategoryFixtureTrait
{
    private function category(int $supplierId, string $code, ?int $parentId = null, int $displayOrder = 0, bool $archived = false): int
    {
        $pdo = $this->db->pdo();
        $parentPath = '/';
        $depth = 0;
        if ($parentId !== null) {
            $stmt = $pdo->prepare('SELECT path, depth FROM stock_categories WHERE supplier_id = ? AND id = ?');
            $stmt->execute([$supplierId, $parentId]);
            $parent = $stmt->fetch(\PDO::FETCH_ASSOC);
            $parentPath = (string) $parent['path'];
            $depth = (int) $parent['depth'] + 1;
        }
        $pdo->prepare('INSERT INTO stock_categories (supplier_id, parent_id, code, name, path, depth, display_order, archived)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $parentId, $code, 'Kategorie ' . $code, '/', $depth, $displayOrder, (int) $archived]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE stock_categories SET path = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$parentPath . $id . '/', $supplierId, $id]);
        return $id;
    }

    private function assignCategory(int $supplierId, int $itemId, int $categoryId, bool $primary = false, int $displayOrder = 0): void
    {
        $this->db->pdo()->prepare('INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id, is_primary, display_order)
            VALUES (?, ?, ?, ?, ?)')
            ->execute([$supplierId, $itemId, $categoryId, (int) $primary, $displayOrder]);
    }

    private function attribute(int $supplierId, string $code, string $type, ?string $unit = null): int
    {
        $this->db->pdo()->prepare('INSERT INTO stock_attributes (supplier_id, code, name, data_type, unit, is_multivalue)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $code, 'Parametr ' . $code, $type, $unit, (int) ($type === 'enum')]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function attributeOption(int $supplierId, int $attributeId, string $code, string $label, int $displayOrder = 0): int
    {
        $this->db->pdo()->prepare('INSERT INTO stock_attribute_options (supplier_id, attribute_id, code, label, display_order)
            VALUES (?, ?, ?, ?, ?)')
            ->execute([$supplierId, $attributeId, $code, $label, $displayOrder]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array{option_id?:int, value_text?:string, value_num?:string, value_bool?:bool} $value */
    private function attributeValue(int $supplierId, int $itemId, int $attributeId, array $value, int $displayOrder = 0): void
    {
        $this->db->pdo()->prepare('INSERT INTO stock_item_attribute_values
                (supplier_id, stock_item_id, attribute_id, option_id, value_text, value_num, value_bool, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $itemId, $attributeId, $value['option_id'] ?? null, $value['value_text'] ?? null,
                $value['value_num'] ?? null, isset($value['value_bool']) ? (int) $value['value_bool'] : null, $displayOrder]);
    }
}
