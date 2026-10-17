<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Codebook;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Codebook\UnitPrecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Počet desetinných míst množství podle měrné jednotky (migrace 2036).
 *
 * Kontrola nesmí nic zaokrouhlovat, jen říct, že zadané množství má víc míst,
 * než jednotka dovoluje. Jednotka bez nastavení a jednotka mimo číselník
 * nejsou omezené vůbec.
 */
final class UnitPrecisionTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?int}> */
    public static function places(): iterable
    {
        yield 'int'                    => [3, 0];
        yield 'float celé'             => [2.0, 0];
        yield 'float 1 místo'          => [1.5, 1];
        yield 'float 3 místa'          => [0.125, 3];
        yield 'float šum sčítání'      => [0.1 + 0.2, 1];
        yield 'záporné'                => [-1.25, 2];
        yield 'řetězec s nulami'       => ['1.500', 1];
        yield 'řetězec s čárkou'       => ['2,75', 2];
        yield 'řetězec celý'           => ['10', 0];
        yield 'řetězec 4 místa'        => ['1.2345', 4];
        yield 'řetězec s mezerou'      => [' 4.0 ', 0];
        yield 'nečíselné'              => ['abc', null];
        yield 'prázdné'                => ['', null];
        yield 'null'                   => [null, null];
    }

    #[DataProvider('places')]
    public function testDecimalPlaces(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, UnitPrecision::decimalPlaces($value));
    }

    public function testViolationsReportIndexUnitAndAllowedPlaces(): void
    {
        $map = ['ks' => 0, 'kg' => 2];
        $items = [
            ['quantity' => 1, 'unit' => 'ks'],
            ['quantity' => 1.5, 'unit' => 'ks'],
            ['quantity' => '0.125', 'unit' => 'KG'],
            ['quantity' => '0.12', 'unit' => 'kg'],
            ['quantity' => 2.5, 'unit' => 'h'],
        ];

        self::assertSame([
            ['index' => 1, 'unit' => 'ks', 'decimals' => 0],
            ['index' => 2, 'unit' => 'KG', 'decimals' => 2],
        ], UnitPrecision::violations($items, $map));
    }

    public function testUnitWithoutSettingOrOutsideCodebookIsUnlimited(): void
    {
        self::assertSame([], UnitPrecision::violations([
            ['quantity' => '1.234', 'unit' => 'm'],
            ['quantity' => '1.234', 'unit' => 'neznámá'],
            ['quantity' => '1.234', 'unit' => ''],
        ], ['ks' => 0]));
    }

    public function testTimeRowsAndGeneratedDiscountRowsAreSkipped(): void
    {
        self::assertSame([], UnitPrecision::violations([
            // Hodiny počítané z minut (20 min = 0,333 h) uživatel nepíše jako číslo.
            ['quantity' => 0.333, 'unit' => 'h', 'duration_minutes' => 20],
            ['quantity' => 0.5, 'unit' => 'ks', 'item_kind' => 'discount'],
            // Hodinová jednotka bez karty je v editoru vždy časový řádek (zadává se h:mm).
            ['quantity' => 0.333, 'unit' => 'hod'],
        ], ['h' => 2, 'hod' => 2, 'ks' => 0]));
        // Hodinová jednotka na skladové kartě je obyčejné množství.
        self::assertSame(
            [['index' => 0, 'unit' => 'h', 'decimals' => 2]],
            UnitPrecision::violations([['quantity' => 0.333, 'unit' => 'h', 'stock_item_id' => 7]], ['h' => 2]),
        );
    }

    public function testCustomKeysForStockLines(): void
    {
        self::assertSame(
            [['index' => 0, 'unit' => 'ks', 'decimals' => 0]],
            UnitPrecision::violations([['qty' => '1.500', 'unit' => 'ks']], ['ks' => 0], 'qty'),
        );
    }

    public function testPrintDecimalsIsFixedOnlyWithSettingAndNeverHidesStoredPlaces(): void
    {
        self::assertNull(UnitPrecision::printDecimals('1.5', null), 'Bez nastavení adaptivní tisk.');
        self::assertSame(2, UnitPrecision::printDecimals('1.5', 2));
        self::assertSame(0, UnitPrecision::printDecimals('3.000', 0));
        self::assertSame(3, UnitPrecision::printDecimals('2.125', 0), 'Starý řádek se při tisku nezaokrouhlí.');
    }

    /**
     * BEZ OPRAVY PADÁ: řádek bez klíče `unit` kontrolu obešel, repozitář ho ale
     * uloží s výchozí jednotkou. Prázdný řetězec je jiná jednotka (mimo číselník).
     */
    public function testMissingUnitKeyMeansDefaultUnitLikeRepository(): void
    {
        self::assertSame(
            [['index' => 0, 'unit' => InvoiceRepository::DEFAULT_ITEM_UNIT, 'decimals' => 0]],
            UnitPrecision::violations([['quantity' => 1.5]], [InvoiceRepository::DEFAULT_ITEM_UNIT => 0]),
        );
        self::assertSame(
            [['index' => 0, 'unit' => InvoiceRepository::DEFAULT_ITEM_UNIT, 'decimals' => 0]],
            UnitPrecision::violations([['quantity' => 1.5, 'unit' => null]], [InvoiceRepository::DEFAULT_ITEM_UNIT => 0]),
        );
        self::assertSame([], UnitPrecision::violations([['quantity' => 1.5, 'unit' => '']], [InvoiceRepository::DEFAULT_ITEM_UNIT => 0]));
    }

    /**
     * BEZ OPRAVY PADÁ: PUT kontroloval i řádky poslané beze změny, takže doklad
     * s dřív uloženým 2,125 kg nešel po nastavení jednotky uložit vůbec.
     */
    public function testUnchangedStoredRowsArePairedByIdOrByUnitAndQuantity(): void
    {
        $stored = [
            ['id' => 10, 'quantity' => '2.125', 'unit' => 'kg'],
            ['id' => 11, 'quantity' => '1.500', 'unit' => 'ks'],
        ];
        $sent = [
            ['quantity' => 2.125, 'unit' => 'KG'],         // beze změny, bez id (editor id neposílá)
            ['quantity' => '2.125', 'unit' => 'kg'],       // druhá kopie: uložený řádek už je spárovaný
            ['id' => 11, 'quantity' => '1.25', 'unit' => 'ks'], // id sedí, množství se změnilo
            ['quantity' => '1.5', 'unit' => 'ks'],         // jako uložený řádek 11, ten ale patří id výše
        ];

        self::assertSame([0 => true], UnitPrecision::unchangedIndexes($sent, $stored));
        self::assertSame([], UnitPrecision::unchangedIndexes([['quantity' => '2.1251', 'unit' => 'kg']], $stored));
        self::assertSame([0 => true], UnitPrecision::unchangedIndexes([['id' => 10, 'quantity' => '2,125', 'unit' => 'kg']], $stored));
        // Bez klíče unit = výchozí jednotka, stejně jako při ukládání.
        self::assertSame([0 => true], UnitPrecision::unchangedIndexes([['quantity' => '1.5']], [['id' => 3, 'quantity' => '1.500', 'unit' => 'ks']]));
    }

    public function testKeyIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame('kg', UnitPrecision::key(' KG '));
        self::assertSame('m²', UnitPrecision::key('M²'));
    }
}
