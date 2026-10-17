<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Validation\InvoiceValidation;
use PHPUnit\Framework\TestCase;

final class InvoiceValidationTest extends TestCase
{
    public function testAccrualPeriodNeedsBothValidDatesInOrder(): void
    {
        $base = [
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'issue_date'   => '2026-09-28',
            'due_date'     => '2026-10-12',
            'tax_date'     => '2026-09-28',
        ];
        $item = ['description' => 'Roční předplatné', 'quantity' => 1, 'unit_price_without_vat' => 12900, 'vat_rate_id' => 1];

        $ok = $base + ['items' => [$item + ['accrual_from' => '2026-09-28', 'accrual_to' => '2027-09-28']]];
        self::assertSame([], InvoiceValidation::invoice($ok));

        $half = $base + ['items' => [$item + ['accrual_from' => '2026-09-28']]];
        self::assertArrayHasKey('items.0.accrual_from', InvoiceValidation::invoice($half));

        $reversed = $base + ['items' => [$item + ['accrual_from' => '2027-09-28', 'accrual_to' => '2026-09-28']]];
        self::assertArrayHasKey('items.0.accrual_to', InvoiceValidation::invoice($reversed));
    }

    public function testValidInvoicePasses(): void
    {
        $data = [
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'issue_date'   => '2026-04-15',
            'due_date'     => '2026-04-30',
            'tax_date'     => '2026-04-15',
            'items' => [
                ['description' => 'Konzultace', 'quantity' => 2, 'unit_price_without_vat' => 1500, 'vat_rate_id' => 1],
            ],
        ];
        self::assertSame([], InvoiceValidation::invoice($data));
    }

    public function testInvalidTypeRejected(): void
    {
        $err = InvoiceValidation::invoice(['invoice_type' => 'fake', 'client_id' => 1]);
        self::assertArrayHasKey('invoice_type', $err);
    }

    public function testMissingClientId(): void
    {
        $err = InvoiceValidation::invoice(['invoice_type' => 'invoice']);
        self::assertArrayHasKey('client_id', $err);
    }

    public function testInvalidCurrencyIdRejected(): void
    {
        $err = InvoiceValidation::invoice(['client_id' => 1, 'currency_id' => 0]);
        self::assertArrayHasKey('currency_id', $err);
    }

    public function testInvalidDateFormat(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'issue_date' => '15.4.2026',
        ]);
        self::assertArrayHasKey('issue_date', $err);
    }

    public function testProformaSkipsTaxDateValidation(): void
    {
        // Proforma nemá DUZP — i kdyby tam bylo nesmyslné, validace ho neřeší
        $err = InvoiceValidation::invoice([
            'invoice_type' => 'proforma',
            'client_id'    => 1,
            'currency_id'  => 1,
            'tax_date'     => 'garbage', // zaplaceno proforma typu — nikdy se nečte
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayNotHasKey('tax_date', $err);
    }

    public function testItemMissingDescription(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'items'     => [['description' => '   ', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayHasKey('items.0.description', $err);
    }

    public function testInvoiceAllowsNegativeQuantityForDiscountLine(): void
    {
        $err = InvoiceValidation::invoice([
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'items'        => [['description' => 'Sleva', 'quantity' => -1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayNotHasKey('items.0.quantity', $err);
        self::assertArrayNotHasKey('items.0.unit_price_without_vat', $err);
    }

    public function testItemZeroQuantityRejected(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'items'     => [['description' => 'Sleva', 'quantity' => 0, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayHasKey('items.0.quantity', $err);
    }

    public function testInvoiceAllowsNegativeUnitPriceForDiscountLine(): void
    {
        $err = InvoiceValidation::invoice([
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'items'        => [['description' => 'Sleva', 'quantity' => 1, 'unit_price_without_vat' => -100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayNotHasKey('items.0.quantity', $err);
        self::assertArrayNotHasKey('items.0.unit_price_without_vat', $err);
    }

    public function testItemWithBothNegativeQuantityAndPriceRejected(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'items'     => [['description' => 'Nejednoznačná sleva', 'quantity' => -1, 'unit_price_without_vat' => -100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayHasKey('items.0.quantity', $err);
        self::assertArrayHasKey('items.0.unit_price_without_vat', $err);
    }

    public function testItemMissingVatRate(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'items'     => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 100]],
        ]);
        self::assertArrayHasKey('items.0.vat_rate_id', $err);
    }

    public function testFinalInvoiceFromProformaAllowsZeroAmountToPay(): void
    {
        $err = InvoiceValidation::invoice([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => 123,
            'client_id' => 1,
            'currency_id' => 1,
            'issue_date' => '2026-04-15',
            'due_date' => '2026-04-15',
            'tax_date' => '2026-04-15',
            'advance_paid_amount' => 1210,
            'items' => [
                ['description' => 'Daňový doklad k záloze', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        self::assertArrayNotHasKey('amount_to_pay', $err);
    }

    public function testOssItemAcceptsPreviousReturnPeriod(): void
    {
        $data = $this->validOssInvoice();

        self::assertSame([], InvoiceValidation::invoice($data));
    }

    public function testOssItemRejectsCurrentReturnPeriod(): void
    {
        $data = $this->validOssInvoice();
        $data['items'][0]['oss_original_period'] = '2026Q3';

        $err = InvoiceValidation::invoice($data);

        self::assertArrayHasKey('items.0.oss_original_period', $err);
    }

    public function testOssItemRequiresIsoConsumerCountry(): void
    {
        $data = $this->validOssInvoice();
        $data['items'][0]['oss_consumer_country'] = 'Slovensko';

        $err = InvoiceValidation::invoice($data);

        self::assertArrayHasKey('items.0.oss_consumer_country', $err);
    }

    public function testForeignVatRateRejectedOnNonOssItem(): void
    {
        $data = $this->validOssInvoice();
        $data['items'][0]['oss_applicable'] = false;
        $data['items'][0]['vat_rate_id'] = 7;

        $err = InvoiceValidation::invoice($data, null, [7 => 'DE']);

        self::assertArrayHasKey('items.0.vat_rate_id', $err);
    }

    public function testForeignVatRateAllowedOnOssItem(): void
    {
        $data = $this->validOssInvoice();
        $data['items'][0]['vat_rate_id'] = 7;

        $err = InvoiceValidation::invoice($data, null, [7 => 'DE']);

        self::assertArrayNotHasKey('items.0.vat_rate_id', $err);
    }

    public function testDomesticVatRateUnaffectedByCountryCheck(): void
    {
        $data = $this->validOssInvoice();
        $data['items'][0]['oss_applicable'] = false;

        $err = InvoiceValidation::invoice($data, null, [1 => 'CZ']);

        self::assertArrayNotHasKey('items.0.vat_rate_id', $err);
    }

    public function testOssRateTypeAllowlistMatchesExportableCodes(): void
    {
        $data = $this->validOssInvoice();
        $data['items'][0]['oss_rate_type'] = 'zero';

        $err = InvoiceValidation::invoice($data);

        self::assertArrayHasKey('items.0.oss_rate_type', $err);
    }

    /**
     * Naimportovaný OSS řádek, u kterého číselník členských států sazbu nezná, přijde
     * s prázdným typem sazby. Kdyby ho validace odmítla, nešel by doklad vůbec uložit
     * a uživatel by neměl co opravovat. Do podání ho nepustí až OssXmlExporter.
     */
    public function testEmptyOssRateTypeIsAcceptedAsNotYetDetermined(): void
    {
        foreach ([null, '', '  '] as $value) {
            $data = $this->validOssInvoice();
            $data['items'][0]['oss_rate_type'] = $value;

            $err = InvoiceValidation::invoice($data);

            self::assertArrayNotHasKey('items.0.oss_rate_type', $err, var_export($value, true));
        }

        $data = $this->validOssInvoice();
        unset($data['items'][0]['oss_rate_type']);
        self::assertArrayNotHasKey('items.0.oss_rate_type', InvoiceValidation::invoice($data));
    }

    /**
     * Uvolnění typu sazby se nesmí přelít na zbytek OSS bloku — země spotřeby
     * a typ plnění zůstávají povinné, bez nich nemá řádek v podání kam patřit.
     */
    public function testEmptyOssRateTypeDoesNotRelaxCountryOrSupplyType(): void
    {
        $data = $this->validOssInvoice();
        $data['items'][0]['oss_rate_type'] = null;
        $data['items'][0]['oss_consumer_country'] = '';
        $data['items'][0]['oss_supply_type'] = '';

        $err = InvoiceValidation::invoice($data);

        self::assertArrayHasKey('items.0.oss_consumer_country', $err);
        self::assertArrayHasKey('items.0.oss_supply_type', $err);
    }

    /**
     * Zrcadlo PurchaseInvoiceValidationTest::testCreditNoteWithPositiveTotalWarns.
     *
     * Vydaný dobropis s kladným součtem se ve výkazech DPH/KH PŘIČTE místo odečtení
     * (VatLedgerService bere znaménko z položek), zatímco deník ho podle invoice_type
     * obrátí — deník a přiznání si pak protiřečí. Dvojí negaci blokuje
     * InvoiceAmountPolicy; tohle je opačný případ, kdy se znaménko neotočilo vůbec.
     */
    public function testCreditNoteWithPositiveTotalWarns(): void
    {
        $invoice = ['invoice_type' => 'credit_note', 'total_without_vat' => 1000.0];
        self::assertSame(['credit_note_positive_total'], InvoiceValidation::warnings($invoice));
    }

    public function testCreditNoteWithNegativeTotalHasNoWarning(): void
    {
        $invoice = ['invoice_type' => 'credit_note', 'total_without_vat' => -1000.0];
        self::assertSame([], InvoiceValidation::warnings($invoice));
    }

    public function testRegularInvoiceWithPositiveTotalHasNoWarning(): void
    {
        $invoice = ['invoice_type' => 'invoice', 'total_without_vat' => 1000.0];
        self::assertSame([], InvoiceValidation::warnings($invoice));
    }

    /** @return array<string,mixed> */
    private function validOssInvoice(): array
    {
        return [
            'invoice_type' => 'invoice',
            'client_id' => 1,
            'currency_id' => 1,
            'issue_date' => '2026-07-15',
            'due_date' => '2026-07-29',
            'tax_date' => '2026-07-15',
            'items' => [[
                'description' => 'Syntetická OSS služba',
                'quantity' => 1,
                'unit_price_without_vat' => 100,
                'vat_rate_id' => 1,
                'oss_applicable' => true,
                'oss_consumer_country' => 'SK',
                'oss_rate_type' => 'standard',
                'oss_supply_type' => 'services',
                'oss_original_period' => '2026Q2',
            ]],
        ];
    }
    /**
     * Textový řádek nese jen popis: množství, cenu ani sazbu nepotřebuje a zahraniční
     * sazba na něm nevadí (nic nevykazuje). Bez popisu ale nedává smysl.
     */
    public function testTextRowNeedsOnlyDescription(): void
    {
        $base = [
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'issue_date'   => '2026-04-15',
            'due_date'     => '2026-04-30',
            'tax_date'     => '2026-04-15',
        ];
        $item = ['description' => 'Konzultace', 'quantity' => 2, 'unit_price_without_vat' => 1500, 'vat_rate_id' => 1];

        $ok = $base + ['items' => [$item, ['item_kind' => 'text', 'description' => 'Oddíl B: montáž', 'vat_rate_id' => 9]]];
        self::assertSame([], InvoiceValidation::invoice($ok, [1 => 21.0, 9 => 19.0], [1 => 'CZ', 9 => 'DE'], 'CZ'));

        $empty = $base + ['items' => [$item, ['item_kind' => 'text', 'description' => '  ']]];
        $errors = InvoiceValidation::invoice($empty);
        self::assertArrayHasKey('items.1.description', $errors);
        self::assertArrayNotHasKey('items.1.quantity', $errors);
        self::assertArrayNotHasKey('items.1.unit_price_without_vat', $errors);
        self::assertArrayNotHasKey('items.1.vat_rate_id', $errors);
    }
}
