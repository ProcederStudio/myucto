<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use PDO;

/**
 * Doplní výčet nedostatku (`insufficient_stock`, A3) o sklad a jednotku karty:
 * `warehouse_id`, `warehouse_name` a `unit` (základní jednotka, v ní jsou
 * `requested` i `available`). Klíče jsou aditivní, stávající volající je ignorují;
 * okno nedostatku v aplikaci z nich skládá řádek „SKU, název, sklad, MJ".
 *
 * Volá se jen na chybové cestě těsně před vyhozením výjimky, dva malé dotazy
 * na výčet nevadí. Uvnitř transakce vystavení je to obyčejné čtení.
 */
final class StockShortageDetails
{
    /**
     * @param list<array<string,mixed>> $shortages řádky se `stock_item_id`
     * @return list<array<string,mixed>>
     */
    public static function enrich(PDO $pdo, int $supplierId, int $warehouseId, array $shortages): array
    {
        if ($shortages === []) {
            return $shortages;
        }
        $warehouse = $pdo->prepare('SELECT name FROM warehouses WHERE supplier_id = ? AND id = ?');
        $warehouse->execute([$supplierId, $warehouseId]);
        $warehouseName = $warehouse->fetchColumn();

        $ids = array_values(array_unique(array_map(
            static fn (array $s): int => (int) ($s['stock_item_id'] ?? 0),
            $shortages,
        )));
        $units = [];
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            $stmt = $pdo->prepare(
                'SELECT id, unit FROM stock_items WHERE supplier_id = ? AND id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$ids]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $units[(int) $row['id']] = (string) $row['unit'];
            }
        }

        foreach ($shortages as $i => $s) {
            $shortages[$i]['warehouse_id'] = $warehouseId;
            $shortages[$i]['warehouse_name'] = $warehouseName !== false ? (string) $warehouseName : null;
            $shortages[$i]['unit'] = $units[(int) ($s['stock_item_id'] ?? 0)] ?? null;
        }
        return $shortages;
    }
}
