<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rozdělení jména fyzické osoby (OSVČ) na jmeno/prijmeni pro EPO VetaP, vč. odstranění
 * akademických titulů (issue #200 — „MUDr. Josef Novák" nesmí dát jmeno="MUDr.").
 */
final class EpoSupplierBlockBuilderTest extends TestCase
{
    /**
     * @return iterable<string, array{0:string, 1:string, 2:string}>
     */
    public static function names(): iterable
    {
        yield 'prostý' => ['Josef Novák', 'Josef', 'Novák'];
        yield 'vedoucí titul' => ['MUDr. Josef Novák', 'Josef', 'Novák'];
        yield 'více vedoucích titulů' => ['prof. Ing. Jan Svoboda', 'Jan', 'Svoboda'];
        yield 'koncový titul za čárkou' => ['Ing. Jan Svoboda, CSc.', 'Jan', 'Svoboda'];
        yield 'vedoucí i koncový titul' => ['MUDr. Josef Novák, Ph.D.', 'Josef', 'Novák'];
        yield 'koncový titul bez tečky' => ['Jan Svoboda, MBA', 'Jan', 'Svoboda'];
        yield 'víceslovné příjmení' => ['Josef Karel Novák', 'Josef', 'Karel Novák'];
        yield 'jen jedno slovo' => ['Novák', 'Novák', 'Novák'];
        yield 'jen titul + jméno' => ['Bc. Anna', 'Anna', 'Anna'];
        yield 'extra mezery' => ['  Ing.   Petr   Dvořák ', 'Petr', 'Dvořák'];
        yield 'prázdné' => ['', '', ''];
    }

    #[DataProvider('names')]
    public function testSplitPersonNameStripsTitles(string $full, string $expJmeno, string $expPrijmeni): void
    {
        [$jmeno, $prijmeni] = EpoSupplierBlockBuilder::splitPersonName($full);
        $this->assertSame($expJmeno, $jmeno, "jmeno pro „{$full}\"");
        $this->assertSame($expPrijmeni, $prijmeni, "prijmeni pro „{$full}\"");
    }

    /** Fyzická osoba bez strukturovaných polí → fallback split company_name bez titulu (#200). */
    public function testFyzickaOsobaFallbackFromCompanyName(): void
    {
        $v = $this->fillFor(['taxpayer_type' => 'fo', 'company_name' => 'MUDr. Josef Novák']);
        $this->assertSame('Josef', $v->getAttribute('jmeno'));
        $this->assertSame('Novák', $v->getAttribute('prijmeni'));
        $this->assertSame('', $v->getAttribute('zkrobchjm'), 'fyzická osoba nemá zkrobchjm');
    }

    /** Strukturovaná pole (jako u jednatele s.r.o.) mají přednost před parsováním jména. */
    public function testFyzickaOsobaPrefersStructuredNames(): void
    {
        $v = $this->fillFor([
            'taxpayer_type' => 'fo',
            'company_name'  => 'MUDr. Josef Novák',
            'opr_jmeno'     => 'Josef',
            'opr_prijmeni'  => 'Novák',
        ]);
        $this->assertSame('Josef', $v->getAttribute('jmeno'));
        $this->assertSame('Novák', $v->getAttribute('prijmeni'));
    }

    public function testNaturalPersonTitleIsExportedSeparatelyFromName(): void
    {
        $v = $this->fillFor([
            'taxpayer_type' => 'fo', 'company_name' => 'MUDr. Josef Novák',
            'person_title' => ' Ing. ', 'opr_jmeno' => 'Josef', 'opr_prijmeni' => 'Novák',
        ]);
        self::assertSame('Ing.', $v->getAttribute('titul'));
        self::assertSame('Josef', $v->getAttribute('jmeno'));
        self::assertSame('Novák', $v->getAttribute('prijmeni'));
    }

    #[DataProvider('titlesToOmit')]
    public function testTitleIsOmittedForEmptyFieldOrLegalPerson(array $fields): void
    {
        self::assertFalse($this->fillFor($fields)->hasAttribute('titul'));
    }

    public static function titlesToOmit(): iterable
    {
        yield 'nevyplněný' => [['person_title' => null]];
        yield 'mezery' => [['person_title' => '   ']];
        yield 'bez odhadu z názvu' => [['company_name' => 'MUDr. Josef Novák']];
        yield 'právnická osoba' => [['taxpayer_type' => 'po', 'person_title' => 'Ing.']];
    }

    #[DataProvider('vatSchemas')]
    public function testNaturalPersonTitlePassesEverySharedVatSchema(string $schema, array $vetaD): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->appendChild($dom->createElement('Pisemnost'));
        $form = $root->appendChild($dom->createElement(strtoupper($schema)));
        $d = $form->appendChild($dom->createElement('VetaD'));
        foreach ($vetaD as $key => $value) {
            $d->setAttribute($key, $value);
        }
        $v = $form->appendChild($dom->importNode($this->fillFor([
            'person_title' => 'Ing. Ph.D.', 'company_name' => 'Jan Novák',
        ], includeContact: $schema !== 'dphshv'), true));
        self::assertSame('Ing. Ph.D.', $v->getAttribute('titul'));
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $valid = $dom->schemaValidate(dirname(__DIR__, 4) . '/xsd/' . $schema . '.xsd');
            $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
            self::assertTrue($valid, implode("\n", $errors));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function vatSchemas(): iterable
    {
        yield 'DPH' => ['dphdp3', ['k_uladis' => 'DPH', 'dokument' => 'DP3', 'rok' => '2025', 'mesic' => '7', 'dapdph_forma' => 'B', 'typ_platce' => 'P']];
        yield 'KH' => ['dphkh1', ['k_uladis' => 'DPH', 'dokument' => 'KH1', 'rok' => '2025', 'mesic' => '7', 'khdph_forma' => 'B']];
        yield 'SH' => ['dphshv', ['k_uladis' => 'DPH', 'dokument' => 'SHV', 'rok' => '2025', 'mesic' => '7', 'shvies_forma' => 'R']];
    }

    /** Právnická osoba → celý název do zkrobchjm, jmeno/prijmeni prázdné. */
    public function testPravnickaOsobaUsesCompanyName(): void
    {
        $v = $this->fillFor(['taxpayer_type' => 'po', 'company_name' => 'ACME s.r.o.']);
        $this->assertSame('ACME s.r.o.', $v->getAttribute('zkrobchjm'));
        $this->assertSame('', $v->getAttribute('jmeno'));
    }

    /** #201 — `stat` musí být NÁZEV státu z číselníku (naz_zeme_c25), ne ISO2 kód „CZ". */
    public function testStatUsesCountryNameNotIsoCode(): void
    {
        $v = $this->fillFor(['country_iso2' => 'CZ']);
        $this->assertSame('ČESKÁ REPUBLIKA', $v->getAttribute('stat'));
    }

    /** Prázdný country_iso2 → default ČR. */
    public function testStatDefaultsToCzechRepublic(): void
    {
        $v = $this->fillFor(['country_iso2' => '']);
        $this->assertSame('ČESKÁ REPUBLIKA', $v->getAttribute('stat'));
    }

    /** Zahraniční daňový subjekt → číselníkový název dané země. */
    public function testStatForForeignCountry(): void
    {
        $v = $this->fillFor(['country_iso2' => 'SK']);
        $this->assertSame('SLOVENSKO', $v->getAttribute('stat'));
    }

    /** Země mimo mapu → atribut `stat` se vynechá (je optional), ne neplatná hodnota. */
    public function testStatOmittedForUnknownCountry(): void
    {
        $v = $this->fillFor(['country_iso2' => 'XX']);
        $this->assertFalse($v->hasAttribute('stat'), 'neznámá země nesmí nastavit stat');
    }

    /**
     * @return iterable<string, array{0:string, 1:?string}>
     */
    public static function countries(): iterable
    {
        yield 'CZ' => ['CZ', 'ČESKÁ REPUBLIKA'];
        yield 'lowercase cz' => ['cz', 'ČESKÁ REPUBLIKA'];
        yield 'prázdné → CZ' => ['', 'ČESKÁ REPUBLIKA'];
        yield 'SK' => ['SK', 'SLOVENSKO'];
        yield 'DE' => ['DE', 'NĚMECKO'];
        yield 'GR (ISO, ne EL)' => ['GR', 'ŘECKO'];
        yield 'GB' => ['GB', 'VELKÁ BRITÁNIE'];
        yield 'neznámá' => ['XX', null];
    }

    #[DataProvider('countries')]
    public function testCountryName(string $iso2, ?string $expected): void
    {
        $this->assertSame($expected, EpoSupplierBlockBuilder::countryName($iso2));
    }

    /**
     * typ_ds musí vycházet z `taxpayer_type` (typ daňového subjektu), ne z
     * `data_box_type` (typ datové schránky). U s.r.o. s nevyplněnou datovkou
     * dřív padalo "F" → EPO odmítlo podání kvůli kmenové části DIČ.
     */
    public function testTypDsPodleTypuDanovehoSubjektu(): void
    {
        $po = $this->fillFor(['taxpayer_type' => 'po', 'company_name' => 'Vzorová firma s.r.o.', 'data_box_type' => null]);
        $this->assertSame('P', $po->getAttribute('typ_ds'));
        $this->assertSame('Vzorová firma s.r.o.', $po->getAttribute('zkrobchjm'));

        // data_box_type se do typ_ds nesmí promítnout ani když je vyplněný
        $poSDatovkou = $this->fillFor(['taxpayer_type' => 'po', 'company_name' => 'Firma s.r.o.', 'data_box_type' => 'OVM']);
        $this->assertSame('P', $poSDatovkou->getAttribute('typ_ds'));

        $fo = $this->fillFor(['taxpayer_type' => 'fo', 'company_name' => 'Josef Novák', 'data_box_type' => 'PO']);
        $this->assertSame('F', $fo->getAttribute('typ_ds'));

        $neznamy = $this->fillFor(['taxpayer_type' => null, 'company_name' => 'Josef Novák']);
        $this->assertSame('F', $neznamy->getAttribute('typ_ds'));
    }

    /**
     * DPHDP3/DPHKH1 znají email/c_telef, DPHSHV NE — souhrnné hlášení volá
     * s `includeContact: false` a XSD by kontaktní atributy odmítlo (#200).
     */
    public function testContactAttributesEmittedByDefault(): void
    {
        $v = $this->fillFor(['email' => 'a@b.cz', 'phone' => '+420 601 002 003']);
        $this->assertSame('a@b.cz', $v->getAttribute('email'));
        $this->assertSame('601002003', $v->getAttribute('c_telef'));
    }

    public function testContactAttributesSuppressedForSouhrnneHlaseni(): void
    {
        $v = $this->fillFor(['email' => 'a@b.cz', 'phone' => '+420 601 002 003'], includeContact: false);
        $this->assertFalse($v->hasAttribute('email'), 'DPHSHV VetaP nesmí mít email');
        $this->assertFalse($v->hasAttribute('c_telef'), 'DPHSHV VetaP nesmí mít c_telef');
        // Ostatní atributy zůstávají vyplněné (kontrola, že jsme neshodili celý blok).
        $this->assertSame('ČESKÁ REPUBLIKA', $v->getAttribute('stat'));
    }

    /** Adresa se v DPHSHV rozdělí na ulice/c_pop (#200 — dřív „Nová 158" celé v ulice). */
    public function testAddressSplitAppliesForSouhrnneHlaseni(): void
    {
        $v = $this->fillFor(['street' => 'Nová 158'], includeContact: false);
        $this->assertSame('Nová', $v->getAttribute('ulice'));
        $this->assertSame('158', $v->getAttribute('c_pop'));
    }

    /**
     * Základní fixtura je ÚPLNÝ řádek dodavatele s prázdnými hodnotami — přesně to, co
     * vrátí `EpoSupplierBlockBuilder::loadSupplier()` u nevyplněné firmy.
     *
     * Rozdíl proti dřívějšku je věcný, ne kosmetický: `fillVetaP()` od fáze F1 rozlišuje
     * chybějící KLÍČ (chyba volajícího — zapomenutý sloupec v SELECTu, dřív tichá
     * degradace podání) od prázdné HODNOTY (legitimní stav, dodavatel nemá telefon).
     * Fixtura proto musí být úplná, jinak by testy ověřovaly právě tu volnost, kterou
     * fáze F1 odstranila.
     *
     * @param array<string,mixed> $overrides
     */
    private function fillFor(array $overrides, bool $includeContact = true): \DOMElement
    {
        $supplier = array_merge(
            array_fill_keys(EpoSupplierBlockBuilder::REQUIRED_SUPPLIER_KEYS, ''),
            [
                'financial_office_code' => '451', 'dic' => 'CZ1234567890', 'data_box_type' => 'F',
                'taxpayer_type' => 'fo', 'company_name' => '', 'street' => 'Hlavní 1', 'city' => 'Praha',
                'zip' => '11000', 'country_iso2' => 'CZ',
            ],
            $overrides,
        );
        $dom = new \DOMDocument();
        $v = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($v, $supplier, $includeContact);
        return $v;
    }
}
