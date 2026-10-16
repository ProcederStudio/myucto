<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use PHPUnit\Framework\TestCase;

final class PurchaseInvoiceValidationTest extends TestCase
{
    private function validBase(): array
    {
        return [
            'vendor_id'              => 1,
            'vendor_invoice_number'  => 'FA-2026-001',
            'issue_date'             => '2026-05-20',
            'due_date'               => '2026-06-20',
            'tax_date'               => '2026-05-20',
            'currency_id'            => 1,
            'items' => [
                ['description' => 'Hosting květen', 'quantity' => 1, 'unit_price_without_vat' => 1500, 'vat_rate_id' => 1],
            ],
        ];
    }

    public function testValidPurchaseInvoicePasses(): void
    {
        self::assertSame([], PurchaseInvoiceValidation::invoice($this->validBase(), [1 => 21.0]));
    }

    public function testMissingVendorIdRejected(): void
    {
        $data = $this->validBase();
        unset($data['vendor_id']);
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('vendor_id', $err);
    }

    public function testEmptyVendorInvoiceNumberRejected(): void
    {
        $data = $this->validBase();
        $data['vendor_invoice_number'] = '';
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('vendor_invoice_number', $err);
    }

    public function testTooLongVendorInvoiceNumberRejected(): void
    {
        $data = $this->validBase();
        $data['vendor_invoice_number'] = str_repeat('X', 51);
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('vendor_invoice_number', $err);
    }

    public function testControlCharactersInVendorInvoiceNumberRejected(): void
    {
        // Anti-injection: kontrolní znaky v poli které jde do logu / HTML
        $data = $this->validBase();
        $data['vendor_invoice_number'] = "FA-001\x00\x07";
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('vendor_invoice_number', $err);
    }

    public function testInvalidDocumentKindRejected(): void
    {
        $data = $this->validBase();
        $data['document_kind'] = 'fake_kind';
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('document_kind', $err);
    }

    public function testValidDocumentKindsAccepted(): void
    {
        foreach (PurchaseInvoiceValidation::ALLOWED_DOC_KINDS as $kind) {
            $data = $this->validBase();
            $data['document_kind'] = $kind;
            $err = PurchaseInvoiceValidation::invoice($data, [1 => 21.0]);
            self::assertArrayNotHasKey('document_kind', $err, "Kind $kind should be accepted");
        }
    }

    public function testInvalidDateFormat(): void
    {
        $data = $this->validBase();
        $data['issue_date'] = '2026/05/20';  // wrong separator
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('issue_date', $err);
    }

    public function testMissingDueDateRejected(): void
    {
        $data = $this->validBase();
        unset($data['due_date']);
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('due_date', $err);
    }

    public function testVarsymbolTooLongRejected(): void
    {
        $data = $this->validBase();
        $data['varsymbol'] = str_repeat('Z', 21);
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('varsymbol', $err);
    }

    public function testVarsymbolControlCharsRejected(): void
    {
        $data = $this->validBase();
        $data['varsymbol'] = "PF-001\x01";
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('varsymbol', $err);
    }

    public function testNegativeAdvancePaidRejected(): void
    {
        $data = $this->validBase();
        $data['advance_paid_amount'] = -500;
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('advance_paid_amount', $err);
    }

    public function testExchangeRateOutOfRangeRejected(): void
    {
        $data = $this->validBase();
        $data['exchange_rate'] = -1.5;
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('exchange_rate', $err);

        $data['exchange_rate'] = 1_000_000;  // unreasonable
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('exchange_rate', $err);
    }

    public function testPaymentExchangeRateOutOfRangeRejected(): void
    {
        $data = $this->validBase();
        $data['payment_exchange_rate'] = 0;  // 0 = invalid
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('payment_exchange_rate', $err);
    }

    public function testInvalidPaymentCurrencyIdRejected(): void
    {
        $data = $this->validBase();
        $data['payment_currency_id'] = -1;
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('payment_currency_id', $err);
    }

    public function testUnknownVatRateRejected(): void
    {
        $data = $this->validBase();
        $data['items'][0]['vat_rate_id'] = 999;  // not in map
        $err = PurchaseInvoiceValidation::invoice($data, [1 => 21.0, 2 => 12.0]);
        self::assertArrayHasKey('items.0.vat_rate_id', $err);
    }

    public function testHugeNotesRejected(): void
    {
        // DoS protection: > 64 KB note
        $data = $this->validBase();
        $data['note_above_items'] = str_repeat('A', 70000);
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('note_above_items', $err);
    }

    public function testItemsNotArrayRejected(): void
    {
        $data = $this->validBase();
        $data['items'] = 'not-an-array';
        $err = PurchaseInvoiceValidation::invoice($data);
        self::assertArrayHasKey('items', $err);
    }

    // --- warnings() — non-blocking, vyhodnocuje se nad uloženou fakturou (issue #35) ---

    public function testCreditNoteWithNegativeTotalHasNoWarning(): void
    {
        // Správný dobropis: záporné částky (qty −1 × 1000 → base −1000).
        $invoice = ['document_kind' => 'credit_note', 'total_without_vat' => -1000.0];
        self::assertSame([], PurchaseInvoiceValidation::warnings($invoice));
    }

    public function testCreditNoteWithPositiveTotalWarns(): void
    {
        // Dvojí negace (−1 ks × −1000) → base +1000 → ve výkazech by se přičetlo.
        $invoice = ['document_kind' => 'credit_note', 'total_without_vat' => 1000.0];
        self::assertSame(['credit_note_positive_total'], PurchaseInvoiceValidation::warnings($invoice));
    }

    public function testRegularInvoiceWithPositiveTotalHasNoWarning(): void
    {
        // Běžná faktura s kladným součtem je v pořádku — warning je jen pro dobropis.
        $invoice = ['document_kind' => 'invoice', 'total_without_vat' => 1000.0];
        self::assertSame([], PurchaseInvoiceValidation::warnings($invoice));
    }
    /**
     * Přijatá faktura textový řádek nezná a uloží ho jako běžnou položku. item_kind
     * 'text' proto nesmí obejít kontroly položky (tady zákaz záporného množství
     * i ceny zároveň), ani v SetPurchaseInvoiceItemsAction, která volá totéž pravidlo.
     */
    public function testTextKindDoesNotBypassItemRules(): void
    {
        $data = $this->validBase();
        $data['items'][] = ['item_kind' => 'text', 'description' => 'Obejití', 'quantity' => -5,
            'unit_price_without_vat' => -100, 'vat_rate_id' => 1];

        $err = PurchaseInvoiceValidation::invoice($data, [1 => 21.0]);

        self::assertArrayHasKey('items.1.quantity', $err);
        self::assertArrayHasKey('items.1.unit_price_without_vat', $err);
    }
}
