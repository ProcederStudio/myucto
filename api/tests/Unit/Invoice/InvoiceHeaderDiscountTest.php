<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Invoice;

use MyInvoice\Service\Invoice\InvoiceHeaderDiscount;
use MyInvoice\Service\Invoice\InvoiceMath;
use PHPUnit\Framework\TestCase;

final class InvoiceHeaderDiscountTest extends TestCase
{
    public function testGroupsByVatRateClassificationAndOssTupleAndRoundsPerGroup(): void
    {
        $nonOss = [0, null, null, null, null, null, null, null, null, 0];
        $ossDeA = [1, 'DE', 'standard', 'goods', 1.0, '2026-01-01', 10.0, 2.1, '2026Q1', 0];
        $ossDeB = [1, 'DE', 'standard', 'goods', 1.0, '2026-01-01', 20.0, 4.2, '2026Q1', 0];
        $ossFr = [1, 'FR', 'standard', 'goods', 1.0, '2026-01-01', 10.0, 2.1, '2026Q1', 0];
        $items = [
            self::item(1, 21.0, '1', 0.03, $nonOss),
            self::item(1, 21.0, '1', 0.03, $nonOss),
            self::item(1, 21.0, '2', 0.03, $nonOss),
            self::item(2, 12.0, '1', 0.03, $nonOss),
            self::item(1, 21.0, null, 0.03, $ossDeA),
            self::item(1, 21.0, null, 0.03, $ossDeB),
            self::item(1, 21.0, null, 0.03, $ossFr),
            self::item(1, 21.0, '1', -500.0, $nonOss, 'discount'),
        ];

        $groups = InvoiceHeaderDiscount::groups($items, true);
        $discounts = InvoiceHeaderDiscount::discountGroups($groups, 50.0);

        self::assertCount(5, $discounts);
        self::assertSame([0.03, 0.02, 0.02, 0.03, 0.02], array_column($discounts, 'discount'));
        self::assertSame(30.0, $discounts[3]['oss'][6]);
        self::assertEqualsWithDelta(6.3, $discounts[3]['oss'][7], 0.000001);
    }

    public function testHeaderOnlyPreviewDropsStaleGeneratedDiscountAndUsesCurrentPercent(): void
    {
        $items = [
            self::item(1, 21.0, '1', 10000.0),
            self::item(1, 21.0, '1', -1000.0, [], 'discount'),
        ];

        $groups = InvoiceHeaderDiscount::groups($items, false);
        $discountRows = InvoiceHeaderDiscount::calculationItems($groups, 20.0);
        $sourceItems = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['item_kind'] !== 'discount',
        ));
        $withoutCurrentDiscount = InvoiceMath::compute($sourceItems)['totals']['with_vat'];
        $calculated = InvoiceMath::compute(array_merge($sourceItems, $discountRows));

        self::assertSame(12100.0, $withoutCurrentDiscount, 'Bez nových slevových řádků legacy preview překročí limit.');
        self::assertCount(1, $discountRows);
        self::assertSame(-2000.0, $discountRows[0]['unit_price_without_vat']);
        self::assertSame(9680.0, $calculated['totals']['with_vat']);
    }

    public function testZeroHeaderDiscountProducesNoCalculationRows(): void
    {
        $groups = InvoiceHeaderDiscount::groups([self::item(1, 21.0, '1', 10000.0)], false);

        self::assertSame([], InvoiceHeaderDiscount::calculationItems($groups, 0.0));
    }

    /** @return array<string,mixed> */
    private static function item(int $rateId, float $rate, ?string $code, float $amount, array $oss = [], string $kind = 'standard'): array
    {
        return [
            'quantity' => 1.0,
            'unit_price_without_vat' => $amount,
            'vat_rate_id' => $rateId,
            'vat_rate_snapshot' => $rate,
            'vat_classification_code' => $code,
            'item_kind' => $kind,
            '_header_discount_oss_params' => $oss,
        ];
    }
}
