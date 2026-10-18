<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoiceItemKind;
use PHPUnit\Framework\TestCase;

final class InvoiceItemKindTest extends TestCase
{
    public function testKindDefaultsToStandard(): void
    {
        self::assertSame('standard', InvoiceItemKind::of([]));
        self::assertSame('standard', InvoiceItemKind::of(['item_kind' => null]));
        self::assertSame('standard', InvoiceItemKind::of(['item_kind' => 'nesmysl']));
        self::assertSame('discount', InvoiceItemKind::of(['item_kind' => 'discount']));
        self::assertSame('text', InvoiceItemKind::of(['item_kind' => 'text']));
        self::assertTrue(InvoiceItemKind::isText(['item_kind' => 'text']));
        self::assertFalse(InvoiceItemKind::isText(['item_kind' => 'standard']));
        self::assertFalse(InvoiceItemKind::isText('text'));
    }

    public function testNormalizeClearsEverythingButDescriptionAndRate(): void
    {
        $item = InvoiceItemKind::normalize([
            'item_kind' => 'text',
            'description' => "Zakázka 12\nmontáž",
            'quantity' => 5,
            'duration_minutes' => 30,
            'unit' => 'hod',
            'unit_price_without_vat' => 100,
            'vat_rate_id' => 3,
            'vat_classification_code' => '1',
            'stock_item_id' => 7,
            'warehouse_id' => 2,
            'tracking_allocations' => [['quantity' => 1, 'serial_number' => 'X']],
            'small_asset_id' => 4,
            'asset_id' => 5,
            'accrual_from' => '2026-01-01',
            'accrual_to' => '2026-12-31',
            'revenue_account_code' => '604',
            'oss_applicable' => true,
            'oss_consumer_country' => 'DE',
            'oss_rate_type' => 'standard',
            'oss_needs_manual_review' => true,
        ]);

        self::assertSame('text', $item['item_kind']);
        self::assertSame("Zakázka 12\nmontáž", $item['description']);
        self::assertSame(3, $item['vat_rate_id']);
        self::assertSame(0.0, $item['quantity']);
        self::assertNull($item['duration_minutes']);
        self::assertSame('', $item['unit']);
        self::assertSame(0.0, $item['unit_price_without_vat']);
        self::assertNull($item['vat_classification_code']);
        foreach (['stock_item_id', 'warehouse_id', 'small_asset_id', 'asset_id', 'accrual_from', 'accrual_to',
            'revenue_account_code', 'oss_consumer_country', 'oss_rate_type'] as $key) {
            self::assertNull($item[$key], $key);
        }
        self::assertSame([], $item['tracking_allocations']);
        self::assertFalse($item['oss_applicable']);
        self::assertFalse($item['oss_needs_manual_review']);
    }

    public function testNormalizeLeavesOtherKindsUntouched(): void
    {
        $item = ['description' => 'Licence', 'quantity' => 2, 'unit_price_without_vat' => 50, 'vat_rate_id' => 1];
        self::assertSame($item, InvoiceItemKind::normalize($item));
        $discount = $item + ['item_kind' => 'discount'];
        self::assertSame($discount, InvoiceItemKind::normalize($discount));
    }

    public function testAmountLinesDropTextRowsAndKeepOrder(): void
    {
        $items = [
            ['description' => 'A'],
            ['description' => 'Nadpis', 'item_kind' => 'text'],
            ['description' => 'Sleva', 'item_kind' => 'discount'],
            'není pole',
        ];
        self::assertSame(
            [['description' => 'A'], ['description' => 'Sleva', 'item_kind' => 'discount']],
            InvoiceItemKind::amountLines($items),
        );
        self::assertTrue(InvoiceItemKind::hasAmountLine($items));
        self::assertFalse(InvoiceItemKind::hasAmountLine([['item_kind' => 'text']]));
        self::assertFalse(InvoiceItemKind::hasAmountLine([]));
    }

    public function testSqlConditionUsesAlias(): void
    {
        self::assertSame("ii.item_kind <> 'text'", InvoiceItemKind::sqlAmountLine('ii'));
        self::assertSame("item_kind <> 'text'", InvoiceItemKind::sqlAmountLine());
    }
}
