<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use MyInvoice\Service\Tax\Return\TaxRepresentationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kód podepisující osoby (#124, migrace 1964) a podepisující osoba ve větě P
 * DPH, kontrolního a souhrnného hlášení ({@see EpoSupplierBlockBuilder::fillVetaP()}).
 *
 * Před opravou šlo zvolit jen 4b/4c (odvozeno z typu), `dan_por`/`pln_moc` i
 * prodloužená lhůta § 136 odst. 2 DŘ platily pro jakéhokoli zástupce a DPH/KH/SH
 * zástupce vůbec nenesly. Hodnoty v testu jsou VŽDY vymyšlené.
 */
final class EpoSignerAttributesTest extends TestCase
{
    /** @return array<string,mixed> */
    private function supplier(array $overrides = []): array
    {
        return $overrides + [
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 123/4',
            'city' => 'Vzorov', 'zip' => '100 00', 'country_iso2' => 'CZ',
            'dic' => 'CZ12345678', 'taxpayer_type' => 'po', 'person_title' => null,
            'financial_office_code' => '451', 'workplace_code' => '',
            'email' => '', 'phone' => '',
            'street_number_pop' => '', 'street_number_orient' => '',
            'opr_jmeno' => 'Jan', 'opr_prijmeni' => 'Jednatel', 'opr_postaveni' => 'jednatel',
            'sest_jmeno' => '', 'sest_prijmeni' => '', 'sest_telefon' => '',
        ];
    }

    private function vetaP(): \DOMElement
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $el = $dom->createElement('VetaP');
        $dom->appendChild($el);

        return $el;
    }

    /** @return array<string,mixed> */
    private static function naturalPerson(string $code, ?string $evNumber, ?string $birthDate = null): array
    {
        return [
            'represented' => true, 'type' => 'F', 'code' => $code,
            'first_name' => 'Vzorová', 'last_name' => 'Zmocněnkyně',
            'company_name' => null, 'ico' => null,
            'ev_number' => $evNumber, 'birth_date' => $birthDate,
        ];
    }

    /** @return array<string,mixed> */
    private static function legalPerson(string $code, ?string $signerFirst = 'Petr', ?string $signerLast = 'Podepisující'): array
    {
        return [
            'represented' => true, 'type' => 'P', 'code' => $code,
            'first_name' => null, 'last_name' => null,
            'company_name' => 'Vzorová účetní kancelář s.r.o.', 'ico' => '01234567',
            'ev_number' => null, 'birth_date' => null,
            'signer_first_name' => $signerFirst, 'signer_last_name' => $signerLast,
            'signer_position' => 'jednatel',
        ];
    }

    /** dan_por/pln_moc a lhůta § 136/2 DŘ patří jen daňovému poradci nebo advokátovi. */
    #[DataProvider('advisorCases')]
    public function testOnlyTaxAdvisorCodesSetDanPor(array $representation, string $expectedFlag): void
    {
        self::assertSame($expectedFlag, EpoSupplierBlockBuilder::representationFlag($representation));
        self::assertSame($expectedFlag === 'A', TaxRepresentationService::isTaxAdvisor($representation));
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:string}> */
    public static function advisorCases(): iterable
    {
        yield 'bez zastoupení' => [['represented' => false], 'N'];
        yield '4b poradce FO' => [self::naturalPerson('4b', 'EV-1'), 'A'];
        yield '4c poradenská PO' => [self::legalPerson('4c'), 'A'];
        yield '4a obecný zmocněnec FO' => [self::naturalPerson('4a', null, '1980-05-17'), 'N'];
        yield '4a obecný zmocněnec PO' => [self::legalPerson('4a'), 'N'];
        yield '1 zákonný zástupce' => [self::naturalPerson('1', null, '1970-01-02'), 'N'];
        yield '7a právní nástupce' => [self::legalPerson('7a'), 'N'];
        // Stav bez kódu (ručně složený / před migrací 1964) = poradce podle typu.
        yield 'bez kódu, typ F' => [['represented' => true, 'type' => 'F'], 'A'];
        yield 'bez kódu, typ P' => [['represented' => true, 'type' => 'P'], 'A'];
    }

    public function testCodeIsTakenFromEvidence(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillRepresentationAttributes($vetaP, self::naturalPerson('4a', null, '1980-05-17'));
        self::assertSame('F', $vetaP->getAttribute('zast_typ'));
        self::assertSame('4a', $vetaP->getAttribute('zast_kod'));
        self::assertSame('17.05.1980', $vetaP->getAttribute('zast_dat_nar'));
        self::assertFalse($vetaP->hasAttribute('zast_ev_cislo'));
    }

    public function testCodesForTypeMatchEpoCodebook(): void
    {
        self::assertContains('4b', TaxRepresentationService::codesForType('F'));
        self::assertNotContains('4c', TaxRepresentationService::codesForType('F'));
        self::assertNotContains('7a', TaxRepresentationService::codesForType('F'));
        self::assertContains('4c', TaxRepresentationService::codesForType('P'));
        self::assertContains('7a', TaxRepresentationService::codesForType('P'));
        self::assertNotContains('4b', TaxRepresentationService::codesForType('P'));
        self::assertSame([], TaxRepresentationService::codesForType('X'));
    }

    /** DPH/KH/SH: bez zastoupení beze změny — jednatel v opr_*, žádné zast_*. */
    public function testFillVetaPWithoutRepresentationKeepsOpr(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $this->supplier(['tax_representation' => ['represented' => false]]));
        self::assertSame('Jan', $vetaP->getAttribute('opr_jmeno'));
        self::assertFalse($vetaP->hasAttribute('zast_typ'));

        $legacy = $this->vetaP();
        EpoSupplierBlockBuilder::fillVetaP($legacy, $this->supplier());
        self::assertSame('Jan', $legacy->getAttribute('opr_jmeno'));
        self::assertFalse($legacy->hasAttribute('zast_typ'));
    }

    /** DPH/KH/SH: zástupce FO podepisuje sám, opr_* jednatele se nevyplní. */
    public function testFillVetaPWithNaturalPersonRepresentative(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $this->supplier([
            'tax_representation' => self::naturalPerson('4b', 'EV-0001'),
        ]));
        self::assertSame('F', $vetaP->getAttribute('zast_typ'));
        self::assertSame('4b', $vetaP->getAttribute('zast_kod'));
        self::assertSame('EV-0001', $vetaP->getAttribute('zast_ev_cislo'));
        self::assertFalse($vetaP->hasAttribute('opr_jmeno'));
        self::assertFalse($vetaP->hasAttribute('opr_prijmeni'));
        self::assertFalse($vetaP->hasAttribute('opr_postaveni'));
    }

    /** Zástupce PO: opr_* = osoba podepisující za zástupce, ne jednatel firmy. */
    public function testFillVetaPWithLegalPersonRepresentativeUsesItsSigner(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $this->supplier([
            'tax_representation' => self::legalPerson('4a'),
        ]));
        self::assertSame('P', $vetaP->getAttribute('zast_typ'));
        self::assertSame('4a', $vetaP->getAttribute('zast_kod'));
        self::assertSame('01234567', $vetaP->getAttribute('zast_ic'));
        self::assertSame('Petr', $vetaP->getAttribute('opr_jmeno'));
        self::assertSame('Podepisující', $vetaP->getAttribute('opr_prijmeni'));
        self::assertSame('jednatel', $vetaP->getAttribute('opr_postaveni'));
    }

    /** Řádek zástupce PO bez evidované podepisující osoby: jednatel firmy jako dřív, u DPFO nic. */
    public function testLegalPersonWithoutSignerFallsBackOnlyWhenAllowed(): void
    {
        $vetaP = $this->vetaP();
        EpoSupplierBlockBuilder::fillSignerAttributes($vetaP, $this->supplier(), self::legalPerson('4c', null, null));
        self::assertSame('Jan', $vetaP->getAttribute('opr_jmeno'));

        $dpfo = $this->vetaP();
        EpoSupplierBlockBuilder::fillSignerAttributes($dpfo, $this->supplier(), self::legalPerson('4c', null, null), subjectOprFromSupplier: false);
        self::assertFalse($dpfo->hasAttribute('opr_jmeno'));
        self::assertSame('4c', $dpfo->getAttribute('zast_kod'));

        $notRepresented = $this->vetaP();
        EpoSupplierBlockBuilder::fillSignerAttributes($notRepresented, $this->supplier(), ['represented' => false], subjectOprFromSupplier: false);
        self::assertFalse($notRepresented->hasAttribute('opr_jmeno'));
    }

    /** Věta P se zástupcem projde XSD DPHDP3 (DPHKH1 a DPHSHV mají tytéž zast_* atributy). */
    #[DataProvider('xsdCases')]
    public function testVetaPWithRepresentativeIsXsdValid(array $representation): void
    {
        $xsd = dirname(__DIR__, 4) . '/xsd/dphdp3.xsd';
        if (!is_file($xsd)) {
            self::markTestSkipped('api/xsd/dphdp3.xsd chybí.');
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $pisemnost = $dom->appendChild($dom->createElement('Pisemnost'));
        $dphdp3 = $pisemnost->appendChild($dom->createElement('DPHDP3'));
        $dphdp3->setAttribute('verzePis', '03.01');
        $vetaD = $dphdp3->appendChild($dom->createElement('VetaD'));
        foreach (['k_uladis' => 'DPH', 'dokument' => 'DP3', 'dapdph_forma' => 'B', 'rok' => '2025', 'mesic' => '7', 'typ_platce' => 'P'] as $k => $v) {
            $vetaD->setAttribute($k, $v);
        }
        $vetaP = $dphdp3->appendChild($dom->createElement('VetaP'));
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $this->supplier(['tax_representation' => $representation]));

        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $dom->schemaValidate($xsd);
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        self::assertTrue($valid, "VetaP neprošla XSD:\n  - " . implode("\n  - ", $errors));
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function xsdCases(): iterable
    {
        yield '4b poradce FO' => [self::naturalPerson('4b', 'EV-0001')];
        yield '4a zmocněnec FO s datem narození' => [self::naturalPerson('4a', null, '1980-05-17')];
        yield '6a dědic' => [self::naturalPerson('6a', null, '1990-12-01')];
        yield '4c poradenská PO' => [self::legalPerson('4c')];
        yield '7a právní nástupce PO' => [self::legalPerson('7a')];
    }
}
