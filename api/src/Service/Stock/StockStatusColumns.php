<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

/**
 * Sloupce exportu sestavy Stav zásob (PDF i XLSX) podle výstupu
 * {@see StockReportService::status()}. Bez klíče `columns` vrací původních osm
 * sloupců s původními šířkami, takže výchozí export se nemění. Při výběru sloupců
 * mají čísla a kódy pevnou šířku a název, parametr a poznámka si dělí zbytek.
 * Šířky jsou procenta se součtem 100, protože PDF se sází po kusech a kusy musí lícovat.
 */
final class StockStatusColumns
{
    /** Šířky původní sestavy bez výběru sloupců (součet 100). */
    private const DEFAULT_WIDTHS = [
        'warehouse_code' => 7, 'sku' => 12, 'name' => 35, 'item_type' => 9, 'unit' => 5,
        'qty' => 11, 'avg_unit_cost' => 10, 'value_total' => 11,
    ];

    /** Při výběru sloupců mají krátké sloupce pevnou šířku… */
    private const FIXED_WIDTHS = [
        'warehouse_code' => 6, 'sku' => 10, 'item_type' => 6, 'unit' => 3,
        'qty' => 7, 'avg_unit_cost' => 8, 'value_total' => 8, 'sale_price' => 8,
    ];

    /** …a zbytek šířky si dělí textové sloupce v tomto poměru. */
    private const FLEX_WEIGHTS = ['name' => 30, 'attribute_value' => 18, 'note' => 20];

    /**
     * Řádky pro export (XLSX, PDF jsou jen česky): parametr typu ano/ne vrací API
     * neutrálně jako 'true'/'false', export ho zobrazí jako „ano“/„ne“.
     *
     * @param array<string,mixed> $data výstup StockReportService::status()
     * @return list<array<string,mixed>>
     */
    public static function exportItems(array $data): array
    {
        $items = array_values((array) ($data['items'] ?? []));
        if (($data['attribute']['data_type'] ?? null) !== 'bool') {
            return $items;
        }
        foreach ($items as &$item) {
            if (isset($item['attribute_value'])) {
                $item['attribute_value'] = implode(', ', array_map(
                    static fn (string $v): string => match ($v) { 'true' => 'ano', 'false' => 'ne', default => $v },
                    explode(', ', (string) $item['attribute_value']),
                ));
            }
        }
        unset($item);
        return $items;
    }

    /**
     * @param array<string,mixed> $data výstup StockReportService::status()
     * @return list<array{key:string,label:string,numeric:bool,width:float}>
     */
    public static function layout(array $data): array
    {
        $optional = array_key_exists('columns', $data) ? (array) $data['columns'] : ['avg_unit_cost', 'value_total'];
        $attribute = is_array($data['attribute'] ?? null) ? $data['attribute'] : null;
        $attributeLabel = $attribute !== null
            ? (string) $attribute['name'] . (!empty($attribute['unit']) ? ' [' . $attribute['unit'] . ']' : '')
            : '';

        $columns = [
            ['warehouse_code', 'Sklad', false],
            ['sku', 'SKU', false],
            ['name', 'Název', false],
        ];
        if ($attribute !== null && in_array('attribute', $optional, true)) {
            $columns[] = ['attribute_value', $attributeLabel, false];
        }
        array_push($columns, ['item_type', 'Typ', false], ['unit', 'MJ', false], ['qty', 'Množství', true]);
        foreach ([['avg_unit_cost', 'Prům. cena'], ['value_total', 'Hodnota'], ['sale_price', 'Prodejní cena']] as [$key, $label]) {
            if (in_array($key, $optional, true)) {
                $columns[] = [$key, $label, true];
            }
        }
        if (in_array('note', $optional, true)) {
            $columns[] = ['note', 'Poznámka', false];
        }

        $keys = array_column($columns, 0);
        if (!array_key_exists('columns', $data)) {
            $widths = array_map(static fn (string $k): float => (float) self::DEFAULT_WIDTHS[$k], array_combine($keys, $keys));
        } else {
            $fixed = array_intersect_key(self::FIXED_WIDTHS, array_flip($keys));
            $flex = array_intersect_key(self::FLEX_WEIGHTS, array_flip($keys));
            $rest = 100 - array_sum($fixed);
            $widths = [];
            foreach ($keys as $k) {
                $widths[$k] = isset($fixed[$k]) ? (float) $fixed[$k] : round($rest * $flex[$k] / array_sum($flex), 2);
            }
            // Zaokrouhlovací rozdíl dorovná název, aby součet byl přesně 100.
            $widths['name'] = round($widths['name'] + 100 - array_sum($widths), 2);
        }
        return array_map(static fn (array $c): array => [
            'key' => $c[0],
            'label' => $c[1],
            'numeric' => $c[2],
            'width' => $widths[$c[0]],
        ], $columns);
    }
}
