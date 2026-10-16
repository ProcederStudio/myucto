<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Stock;

use MyInvoice\Service\Stock\StockStatusColumns;
use PHPUnit\Framework\TestCase;

/** Šířky sloupců PDF Stav zásob dávají vždy přesně 100 % (kusy sazby musí lícovat). */
final class StockStatusColumnsTest extends TestCase
{
    public function testWidthsSumToExactlyHundred(): void
    {
        $attribute = ['id' => 1, 'code' => 'VIN', 'name' => 'VIN', 'unit' => null, 'data_type' => 'text'];
        $variants = [[]];
        foreach (['avg_unit_cost', 'value_total', 'sale_price', 'note', 'attribute'] as $column) {
            foreach ($variants as $variant) {
                $variants[] = [...$variant, $column];
            }
        }
        foreach ($variants as $columns) {
            $data = ['items' => [], 'columns' => $columns, 'attribute' => in_array('attribute', $columns, true) ? $attribute : null];
            $sum = array_sum(array_column(StockStatusColumns::layout($data), 'width'));
            self::assertSame('100.00', number_format($sum, 2, '.', ''), 'Sloupce: ' . implode(',', $columns));
            self::assertEqualsWithDelta(100.0, $sum, 1e-9, 'Sloupce: ' . implode(',', $columns));
        }
        $default = StockStatusColumns::layout(['items' => []]);
        self::assertSame([7.0, 12.0, 35.0, 9.0, 5.0, 11.0, 10.0, 11.0], array_column($default, 'width'));
    }

    public function testBoolParameterIsTranslatedOnlyForExport(): void
    {
        $data = [
            'items' => [['attribute_value' => 'true'], ['attribute_value' => 'false'], ['attribute_value' => 'true, false'], ['attribute_value' => null]],
            'columns' => ['attribute'],
            'attribute' => ['id' => 1, 'code' => 'NOVY', 'name' => 'Nový', 'unit' => null, 'data_type' => 'bool'],
        ];
        self::assertSame(['ano', 'ne', 'ano, ne', null], array_column(StockStatusColumns::exportItems($data), 'attribute_value'));
        $data['attribute']['data_type'] = 'text';
        self::assertSame(['true', 'false', 'true, false', null], array_column(StockStatusColumns::exportItems($data), 'attribute_value'));
    }
}
