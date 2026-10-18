<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Invoice;

use MyInvoice\Service\Invoice\LineDiscount;
use PHPUnit\Framework\TestCase;

/**
 * Ceníková cena a sleva řádku faktury (migrace 2035): informační údaj k řádku,
 * částky dokladu dál určuje `unit_price_without_vat`.
 *
 * @see \MyInvoice\Service\Invoice\LineDiscount
 */
final class LineDiscountTest extends TestCase
{
    public function testListPriceIsPositiveNumberWithSixDecimals(): void
    {
        self::assertSame('1000.000000', LineDiscount::listPrice('1000'));
        self::assertSame('99.900000', LineDiscount::listPrice(99.9));
        self::assertSame('0.123456', LineDiscount::listPrice('0.1234564'));
        self::assertNull(LineDiscount::listPrice(null));
        self::assertNull(LineDiscount::listPrice(''));
        self::assertNull(LineDiscount::listPrice('abc'));
        self::assertNull(LineDiscount::listPrice(0));
        self::assertNull(LineDiscount::listPrice('-5'));
        self::assertNull(LineDiscount::listPrice('10000000000'), 'Mimo DECIMAL(16,6) se neukládá.');
    }

    public function testPercentFromListAndUnitPrice(): void
    {
        self::assertSame('10.000', LineDiscount::percent('1000', 900));
        self::assertSame('12.500', LineDiscount::percent('800.00', '700.00'));
        self::assertSame('33.333', LineDiscount::percent('300', '200'));
        self::assertSame('66.667', LineDiscount::percent('300', '100'), 'Zaokrouhlení půl nahoru na tři místa.');
        self::assertSame('100.000', LineDiscount::percent('500', 0));
        self::assertSame('-5.000', LineDiscount::percent('1000', '1050'), 'Přirážka je záporná sleva.');
    }

    public function testPercentIsNullWithoutMeaningfulDiscount(): void
    {
        self::assertNull(LineDiscount::percent(null, 900), 'Bez ceníkové ceny není sleva.');
        self::assertNull(LineDiscount::percent('1000', 1000), 'Shoda s ceníkem není sleva.');
        self::assertNull(LineDiscount::percent('1000', '999.9999999'), 'Rozdíl pod tisícinou procenta je shoda.');
        self::assertNull(LineDiscount::percent('1000', -100), 'Záporná cena (odpočet) se se slevou nepočítá.');
        self::assertNull(LineDiscount::percent('1000', 'abc'));
        self::assertNull(LineDiscount::percent('0.01', '5000'), 'Přirážka mimo DECIMAL(7,3) se neukládá.');
    }

    public function testColumnsForStoredRow(): void
    {
        self::assertSame(['1000.000000', '10.000'], LineDiscount::columns(['list_unit_price' => 1000, 'unit_price_without_vat' => 900]));
        self::assertSame(['1000.000000', null], LineDiscount::columns(['list_unit_price' => '1000', 'unit_price_without_vat' => '1000.00']));
        self::assertSame([null, null], LineDiscount::columns(['unit_price_without_vat' => 900, 'line_discount_pct' => '50']), 'Procento posílané klientem se ignoruje.');
    }
}
