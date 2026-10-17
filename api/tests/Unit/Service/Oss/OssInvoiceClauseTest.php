<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Service\Oss\OssInvoiceClause;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doložka o odvodu daně v režimu jednoho správního místa na dokladu.
 *
 * Rozhodnutí „nese doklad doložku a za které státy" musí být na JEDNOM místě —
 * tiskne ho PDF šablona i veřejný HTML náhled a ty se nesmí rozejít. Testy hlídají
 * hlavně dvě věci, u kterých by tichá chyba znamenala nepravdivý doklad:
 * smíšený doklad se nesmí tvářit, že je celý v OSS, a výčet států spotřeby musí být
 * buď úplný, nebo žádný.
 */
#[Group('unit')]
final class OssInvoiceClauseTest extends TestCase
{
    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function item(array $overrides = []): array
    {
        return $overrides + [
            'description'          => 'Položka',
            'item_kind'            => 'standard',
            'oss_applicable'       => true,
            'oss_consumer_country' => 'PL',
        ];
    }

    /** @return array<string,array{name_cs:string,name_en:string}> */
    private static function names(): array
    {
        return [
            'PL' => ['name_cs' => 'Polsko', 'name_en' => 'Poland'],
            'SK' => ['name_cs' => 'Slovensko', 'name_en' => 'Slovakia'],
        ];
    }

    public function testDocumentWithoutOssItemsHasNoClause(): void
    {
        $items = [
            ['description' => 'Konzultace', 'item_kind' => 'standard', 'oss_applicable' => false],
            ['description' => 'Sleva', 'item_kind' => 'discount'],
        ];

        self::assertNull(OssInvoiceClause::build($items, self::names()));
    }

    /** Textový řádek není plnění: z čistě OSS dokladu neudělá smíšený. */
    public function testTextRowDoesNotMakeOssDocumentMixed(): void
    {
        $clause = OssInvoiceClause::build([
            self::item(),
            ['description' => 'Zásilka 1/2', 'item_kind' => 'text', 'oss_applicable' => false],
        ], self::names());

        self::assertNotNull($clause);
        self::assertTrue($clause['all_items']);
    }

    public function testFullyOssDocumentNamesTheStateOfConsumption(): void
    {
        $clause = OssInvoiceClause::build([
            ['item_kind' => 'standard', 'oss_applicable' => true, 'oss_consumer_country' => 'PL'],
            ['item_kind' => 'standard', 'oss_applicable' => true, 'oss_consumer_country' => 'pl'],
        ], self::names());

        self::assertNotNull($clause);
        self::assertTrue($clause['all_items']);
        self::assertSame(
            [['iso2' => 'PL', 'name_cs' => 'Polsko', 'name_en' => 'Poland']],
            $clause['countries'],
        );
    }

    /**
     * Smíšený doklad (část plnění tuzemská) NESMÍ tvrdit, že daň je odvedena ve státě
     * spotřeby — platí to jen o části řádků a šablona podle toho volí formulaci.
     */
    public function testMixedDocumentIsNotMarkedAsFullyOss(): void
    {
        $clause = OssInvoiceClause::build([
            ['item_kind' => 'standard', 'oss_applicable' => true, 'oss_consumer_country' => 'PL'],
            ['item_kind' => 'standard', 'oss_applicable' => false],
        ], self::names());

        self::assertNotNull($clause);
        self::assertFalse($clause['all_items']);
    }

    /**
     * Slevový řádek nemá vlastní plnění — nesmí ze zcela OSS dokladu udělat smíšený.
     */
    public function testDiscountLineDoesNotMakeDocumentMixed(): void
    {
        $clause = OssInvoiceClause::build([
            ['item_kind' => 'standard', 'oss_applicable' => true, 'oss_consumer_country' => 'PL'],
            ['item_kind' => 'discount', 'oss_applicable' => false],
        ], self::names());

        self::assertNotNull($clause);
        self::assertTrue($clause['all_items']);
    }

    public function testSeveralStatesAreListedDeduplicatedAndSorted(): void
    {
        $clause = OssInvoiceClause::build([
            self::item(['oss_consumer_country' => 'SK']),
            self::item(['oss_consumer_country' => 'PL']),
            self::item(['oss_consumer_country' => 'SK']),
        ], self::names());

        self::assertNotNull($clause);
        self::assertSame(['PL', 'SK'], array_column($clause['countries'], 'iso2'));
        self::assertSame(['Polsko', 'Slovensko'], array_column($clause['countries'], 'name_cs'));
    }

    /**
     * Neúplný výčet by na dokladu lhal — když jednomu OSS řádku chybí země spotřeby,
     * doložka státy nejmenuje vůbec (šablona pak tiskne obecnou větu).
     */
    public function testRowWithoutConsumerCountrySuppressesTheWholeList(): void
    {
        $clause = OssInvoiceClause::build([
            self::item(['oss_consumer_country' => 'PL']),
            self::item(['oss_consumer_country' => '']),
        ], self::names());

        self::assertNotNull($clause);
        self::assertSame([], $clause['countries']);
        self::assertTrue($clause['all_items']);
    }

    public function testUnknownCountryFallsBackToIsoCode(): void
    {
        $clause = OssInvoiceClause::build([self::item(['oss_consumer_country' => 'HU'])], self::names());

        self::assertNotNull($clause);
        self::assertSame(
            [['iso2' => 'HU', 'name_cs' => 'HU', 'name_en' => 'HU']],
            $clause['countries'],
        );
    }

    public function testConsumerCountryCodesFeedTheCountryLookup(): void
    {
        $codes = OssInvoiceClause::consumerCountryCodes([
            self::item(['oss_consumer_country' => 'sk']),
            self::item(['oss_consumer_country' => 'PL']),
            self::item(['oss_consumer_country' => 'SK']),
            ['item_kind' => 'standard', 'oss_applicable' => false, 'oss_consumer_country' => 'DE'],
            ['item_kind' => 'standard', 'oss_applicable' => true, 'oss_consumer_country' => 'XYZ'],
        ]);

        sort($codes);
        self::assertSame(['PL', 'SK'], $codes);
    }
}
