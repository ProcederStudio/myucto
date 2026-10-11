<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Issue #8 — souhrnný doklad za období (typicky měsíční vyúčtování palivových karet)
 * NEUVÁDÍ jednotkovou cenu. Má množství, „Základ daně po slevě", pod položkami
 * samostatný blok „Sleva" s PŘEDSLEVOVÝMI částkami a nakonec „Daňovou rekapitulaci
 * (po slevě)". Model si jednotkovou cenu dopočítával a sahal přitom po předslevových
 * číslech, takže se doklad rozešel s rekapitulací a uživateli nezbylo než všechny
 * částky přepsat ručně.
 *
 * Řešení: takový doklad se NESKLÁDÁ z položek, ale zakládá ZE SOUHRNNÉ REKAPITULACE —
 * jeden řádek na sazbu, `1 ks × základ daně`. Součet pak sedí na doklad z definice.
 *
 * Fixtura je SYNTETICKÁ (vymyšlené IČO, DIČ i částky), jen struktura odpovídá reálnému
 * dokladu: dvě DIČ v hlavičce, částky před slevou i po slevě, žádná jednotková cena.
 */
final class AiPdfExtractorRecapOnlyLinesTest extends TestCase
{
    /**
     * Extrakce tak, jak ji má vrátit model podle upraveného promptu: položky bez
     * jednotkové ceny (řádková částka po slevě), souhrnný blok slevy NENÍ položka,
     * totály a rekapitulace jsou po slevě.
     *
     * @return array<string,mixed>
     */
    private function fixture(): array
    {
        return [
            'vendor' => [
                'company_name' => 'BENZINA test s.r.o. — odštěpný závod',
                'ic'           => '69900012',
                'dic'          => 'CZ69900012',
                'vat_dic'      => 'CZ699000123',
                'is_vat_payer' => true,
            ],
            'customer'              => ['company_name' => 'Testovací odběratel s.r.o.', 'ic' => '12345678', 'dic' => 'CZ12345678'],
            'vendor_invoice_number' => 'TEST-0001',
            'document_kind'         => 'invoice',
            'issue_date'            => '2026-08-02',
            'tax_date'              => '2026-07-31',
            'currency'              => 'CZK',
            'items' => [
                // quantity=1, protože doklad jednotkovou cenu neuvádí; množství je v popisu.
                ['description' => 'Verva 100 (30,00 L)', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1200.00, 'line_total_without_vat' => 1200.00, 'vat_rate' => 21.0],
                ['description' => 'Efecta 95 (150,00 L)', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 4800.00, 'line_total_without_vat' => 4800.00, 'vat_rate' => 21.0],
            ],
            'unit_prices_include_vat' => false,
            'unit_prices_stated'      => false,
            'total_without_vat'       => 6000.00,
            'total_with_vat'          => 7260.00,
            'vat_recap'               => [['rate' => 21.0, 'base' => 6000.00, 'vat' => 1260.00]],
            'already_paid'            => false,
        ];
    }

    public function testDocumentWithoutUnitPricesIsBuiltFromVatRecap(): void
    {
        $rates = AiPdfExtractor::recapOnlyRates($this->fixture(), false);

        self::assertIsArray($rates, 'Doklad bez jednotkových cen se musí založit ze souhrnné rekapitulace.');
        self::assertCount(1, $rates);
        self::assertSame(21.0, $rates[0]['rate']);
        self::assertSame(6000.00, $rates[0]['base']);
        self::assertSame(1260.00, $rates[0]['vat']);
    }

    public function testConsistentExplicitAmountsPreserveDescriptionsWithoutInventingUnitPrices(): void
    {
        $data = $this->fixture();
        $data['items'][0]['quantity'] = 30;
        $data['items'][1]['quantity'] = 150;
        $items = AiPdfExtractor::recapMatchedItems($data, false);

        self::assertIsArray($items);
        self::assertSame(array_column($data['items'], 'description'), array_column($items, 'description'));
        self::assertSame([1.0, 1.0], array_column($items, 'quantity'));
        self::assertSame([1200.0, 4800.0], array_column($items, 'unit_price_without_vat'));
    }

    public function testContradictoryOrMissingAmountsUseRecapFallback(): void
    {
        $data = $this->fixture();
        $data['items'][0]['line_total_without_vat'] = 1500;
        self::assertNull(AiPdfExtractor::recapMatchedItems($data, false));
        $data = $this->fixture();
        unset($data['items'][0]['line_total_without_vat']);
        self::assertNull(AiPdfExtractor::recapMatchedItems($data, false));
        $data = $this->fixture();
        unset($data['total_without_vat']);
        self::assertNull(AiPdfExtractor::recapMatchedItems($data, false));
        $data = $this->fixture();
        $data['total_with_vat'] = 8000;
        self::assertNull(AiPdfExtractor::recapMatchedItems($data, false));
    }

    public function testMatchingOverallBaseDoesNotHideDifferentVatRates(): void
    {
        $data = $this->fixture();
        $data['items'][0]['vat_rate'] = 12;
        self::assertNull(AiPdfExtractor::recapMatchedItems($data, false));
    }

    public function testMultipleRatesAndZeroRateKeepTheirExplicitRows(): void
    {
        $data = $this->fixture();
        $data['items'][1]['vat_rate'] = 12;
        $data['items'][] = ['description' => 'Fixture zero-rated item', 'quantity' => 1, 'unit_price_without_vat' => 500, 'line_total_without_vat' => 500, 'vat_rate' => 0];
        $data['vat_recap'] = [
            ['rate' => 21, 'base' => 1200, 'vat' => 252],
            ['rate' => 12, 'base' => 4800, 'vat' => 576],
            ['rate' => 0, 'base' => 500, 'vat' => 0],
        ];
        $data['total_without_vat'] = 6500;
        $data['total_with_vat'] = 7328;
        $items = AiPdfExtractor::recapMatchedItems($data, false);

        self::assertIsArray($items);
        self::assertCount(3, $items);
        self::assertSame([21.0, 12, 0], array_column($items, 'vat_rate'));
    }

    public function testExplicitDiscountAndEmptyLinePreserveTheBreakdown(): void
    {
        $data = $this->fixture();
        $data['items'][0]['line_total_without_vat'] = 1300;
        $data['items'][] = ['description' => 'Fixture discount', 'quantity' => 1, 'unit_price_without_vat' => -100, 'line_total_without_vat' => -100, 'vat_rate' => 21];
        $data['items'][] = ['description' => 'Fixture empty line', 'quantity' => 1, 'unit_price_without_vat' => 0, 'line_total_without_vat' => 0, 'vat_rate' => 0];
        $items = AiPdfExtractor::recapMatchedItems($data, false);

        self::assertIsArray($items);
        self::assertCount(3, $items);
        self::assertSame(-100.0, $items[2]['unit_price_without_vat']);
    }

    public function testGrossPricesCreditNotesAndStatedUnitPricesKeepExistingFlow(): void
    {
        $data = $this->fixture();
        $data['unit_prices_include_vat'] = true;
        self::assertNull(AiPdfExtractor::recapMatchedItems($data, false));
        self::assertNull(AiPdfExtractor::recapMatchedItems($this->fixture(), true));
        $data = $this->fixture();
        $data['unit_prices_stated'] = true;
        self::assertNull(AiPdfExtractor::recapMatchedItems($data, false));
    }

    /** Součet řádků odvozených z rekapitulace sedí na rekapitulaci i na „celkem s DPH". */
    public function testDerivedLinesMatchTheDocumentTotals(): void
    {
        $data  = $this->fixture();
        $rates = AiPdfExtractor::recapOnlyRates($data, false);
        self::assertIsArray($rates);

        $base = 0.0;
        $vat  = 0.0;
        foreach ($rates as $r) {
            $base += $r['base'];
            $vat  += $r['vat'];
        }
        self::assertSame($data['total_without_vat'], round($base, 2));
        self::assertSame($data['total_with_vat'], round($base + $vat, 2));
    }

    /** Vícesazbový doklad → jeden řádek na KAŽDOU sazbu, ne sloučení do jednoho. */
    public function testMultiRateRecapProducesOneLinePerRate(): void
    {
        $data = $this->fixture();
        $data['vat_recap'] = [
            ['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00],
            ['rate' => 12.0, 'base' => 500.00,  'vat' => 60.00],
        ];

        $rates = AiPdfExtractor::recapOnlyRates($data, false);

        self::assertIsArray($rates);
        self::assertCount(2, $rates);
        self::assertSame([21.0, 12.0], array_column($rates, 'rate'));
        self::assertSame([1000.00, 500.00], array_column($rates, 'base'));
    }

    /**
     * Doklad opakuje sazbu i v součtovém řádku („21 %" a pod tím „Celkem 21 %").
     * Kdyby se sčítalo, započítal by se základ dvakrát — platí první výskyt.
     */
    public function testDuplicateRateRowIsNotCountedTwice(): void
    {
        $data = $this->fixture();
        $data['vat_recap'] = [
            ['rate' => 21.0, 'base' => 6000.00, 'vat' => 1260.00],
            ['rate' => 21.0, 'base' => 6000.00, 'vat' => 1260.00], // „Celkem 21,0 %"
        ];

        $rates = AiPdfExtractor::recapOnlyRates($data, false);

        self::assertIsArray($rates);
        self::assertCount(1, $rates);
        self::assertSame(6000.00, $rates[0]['base']);
    }

    /** Prázdné řádky rekapitulace (šablona 0 %/nuly) se ignorují. */
    public function testZeroAndEmptyRecapRowsAreIgnored(): void
    {
        $data = $this->fixture();
        $data['vat_recap'] = [
            ['rate' => 0.0,  'base' => 0.0,     'vat' => 0.0],
            ['rate' => 12.0, 'base' => 0.0,     'vat' => 0.0],
            ['rate' => 21.0, 'base' => 6000.00, 'vat' => 1260.00],
        ];

        $rates = AiPdfExtractor::recapOnlyRates($data, false);

        self::assertIsArray($rates);
        self::assertCount(1, $rates);
        self::assertSame(21.0, $rates[0]['rate']);
    }

    /**
     * NEJDŮLEŽITĚJŠÍ POJISTKA: doklad s normálními položkami a jednotkovými cenami se
     * chová PŘESNĚ jako dosud. Kdyby se cesta přes rekapitulaci spustila plošně,
     * přišly by o rozpad na položky i faktury, kde vytěžení funguje.
     */
    public function testDocumentWithUnitPricesKeepsTodaysBehaviour(): void
    {
        $data = $this->fixture();

        foreach ([true, null] as $stated) {
            $data['unit_prices_stated'] = $stated;
            self::assertNull(
                AiPdfExtractor::recapOnlyRates($data, false),
                'unit_prices_stated=' . var_export($stated, true) . ' nesmí spustit cestu přes rekapitulaci.',
            );
        }

        unset($data['unit_prices_stated']);
        self::assertNull(
            AiPdfExtractor::recapOnlyRates($data, false),
            'Chybějící pole (starší extrakce / jiný provider) nesmí změnit chování.',
        );
    }

    /** Bez rekapitulace není z čeho doklad složit → dnešní tok, ne prázdný doklad. */
    public function testWithoutVatRecapFallsBackToTodaysFlow(): void
    {
        $data = $this->fixture();

        $data['vat_recap'] = [];
        self::assertNull(AiPdfExtractor::recapOnlyRates($data, false));

        unset($data['vat_recap']);
        self::assertNull(AiPdfExtractor::recapOnlyRates($data, false));
    }

    /** Dobropis se neslučuje (znaménka) — stejná výjimka jako u ostatních collapse cest. */
    public function testCreditNoteIsNeverRebuiltFromRecap(): void
    {
        self::assertNull(AiPdfExtractor::recapOnlyRates($this->fixture(), true));
    }

    /**
     * Kontrola, že fixtura odpovídá zadání: souhrnný blok slevy NENÍ mezi položkami
     * (jinak by se sleva odečetla podruhé) a řádkové základy jsou ty PO SLEVĚ.
     */
    public function testFixtureCarriesPostDiscountAmountsOnly(): void
    {
        $data = $this->fixture();

        foreach ($data['items'] as $item) {
            self::assertStringNotContainsStringIgnoringCase('sleva', (string) $item['description']);
            self::assertGreaterThan(0, (float) $item['unit_price_without_vat']);
        }
        $lineSum = array_sum(array_map(
            static fn (array $i): float => (float) $i['line_total_without_vat'],
            $data['items'],
        ));
        self::assertSame($data['total_without_vat'], round($lineSum, 2), 'Řádky musí sedět na rekapitulaci po slevě.');
    }
}
