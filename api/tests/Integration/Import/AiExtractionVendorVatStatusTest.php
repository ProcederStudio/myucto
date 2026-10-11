<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AiExtractionVendorVatStatusTest extends StockTestCase
{
    #[DataProvider('vendorStatuses')]
    public function testDocumentVatIsPreservedWithoutGrantingNonpayerDeduction(?bool $documentStatus, ?bool $resolvedStatus, bool $liveStatus, bool $expectedStatus, bool $hasVat, bool $useRecap = false): void
    {
        $sid = $this->createSupplier();
        $vendor = $this->client($sid, 'Fixture VAT status vendor');
        $this->db->pdo()->prepare('UPDATE clients SET is_vat_payer = ? WHERE id = ?')->execute([(int) $liveStatus, $vendor]);
        $extractor = $this->container->get(AiPdfExtractor::class);
        $id = (new \ReflectionMethod($extractor, 'createDraft'))->invoke($extractor, [
            'vendor' => ['company_name' => 'Fixture VAT status vendor', 'is_vat_payer' => $documentStatus],
            'document_kind' => 'invoice', 'vendor_invoice_number' => 'FIXTURE-VAT-STATUS',
            'currency' => 'CZK', 'issue_date' => '2026-09-01', 'tax_date' => '2026-09-01', 'due_date' => '2026-09-15',
            'items' => [
                ['description' => 'Hosting test service', 'quantity' => 1, 'unit_price_without_vat' => 8000, 'vat_rate' => 21],
                ['description' => 'Domain test service', 'quantity' => 1, 'unit_price_without_vat' => 2000, 'vat_rate' => 21],
            ],
            'total_without_vat' => 10000,
            'total_with_vat' => $hasVat ? 12100 : 10000,
            'vat_recap' => $useRecap ? [['rate' => 21, 'base' => 10000, 'vat' => 2100]] : [],
            'unit_prices_include_vat' => false,
        ], $sid, $this->userId, $vendor, $resolvedStatus);

        $stmt = $this->db->pdo()->prepare('SELECT vendor_is_vat_payer, vat_deduction, total_without_vat, total_vat, total_with_vat, extraction_warning FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertEqualsWithDelta(10000, (float) $invoice['total_without_vat'], 0.001);
        self::assertEqualsWithDelta($hasVat ? 2100 : 0, (float) $invoice['total_vat'], 0.001);
        self::assertEqualsWithDelta($hasVat ? 12100 : 10000, (float) $invoice['total_with_vat'], 0.001);
        self::assertSame($expectedStatus, (bool) $invoice['vendor_is_vat_payer']);
        self::assertSame($expectedStatus ? 'full' : 'none', $invoice['vat_deduction']);

        $stmt = $this->db->pdo()->prepare('SELECT vr.rate_percent FROM purchase_invoice_items pii JOIN vat_rates vr ON vr.id = pii.vat_rate_id WHERE pii.purchase_invoice_id = ? ORDER BY pii.order_index');
        $stmt->execute([$id]);
        self::assertSame([$hasVat ? 21.0 : 0.0, $hasVat ? 21.0 : 0.0], array_map('floatval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
        if ($expectedStatus) {
            self::assertStringNotContainsString('neplátce', (string) $invoice['extraction_warning']);
        } else {
            self::assertStringContainsString('neplátce', (string) $invoice['extraction_warning']);
            self::assertStringContainsString($hasVat ? 'zachována v částce závazku bez nároku na odpočet' : 'Sazby byly nastaveny na 0 %', (string) $invoice['extraction_warning']);
        }
    }

    public static function vendorStatuses(): iterable
    {
        yield 'nonpayer with documented VAT recap' => [true, false, false, false, true, true];
        yield 'nonpayer with documented VAT totals' => [true, false, false, false, true];
        yield 'unsubstantiated rate is removed' => [true, false, false, false, false];
        yield 'registry payer before document nonpayer' => [false, true, true, true, true];
        yield 'resolved payer before live nonpayer' => [null, true, false, true, true];
        yield 'resolved nonpayer before live payer' => [null, false, true, false, false];
        yield 'live payer when status unknown' => [null, null, true, true, true];
        yield 'live nonpayer when status unknown' => [null, null, false, false, false];
    }
}
