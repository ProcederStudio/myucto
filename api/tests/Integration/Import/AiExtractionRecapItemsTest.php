<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AiExtractionRecapItemsTest extends StockTestCase
{
    #[DataProvider('lineAmounts')]
    public function testExplicitLineAmountsAreKeptWhenTheyMatchRecap(?float $secondAmount, bool $keepItems): void
    {
        $sid = $this->createSupplier();
        $vendor = $this->client($sid, 'Fixture recap vendor');
        $extractor = $this->container->get(AiPdfExtractor::class);
        $id = (new \ReflectionMethod($extractor, 'createDraft'))->invoke($extractor, [
            'document_kind' => 'invoice', 'vendor_invoice_number' => 'FIXTURE-RECAP-ITEMS',
            'currency' => 'CZK', 'issue_date' => '2026-09-01', 'tax_date' => '2026-09-01', 'due_date' => '2026-09-15',
            'items' => [
                ['description' => 'Design test service', 'quantity' => 6, 'unit_price_without_vat' => 6000, 'line_total_without_vat' => 6000, 'vat_rate' => 21],
                ['description' => 'Hosting test service', 'quantity' => 4, 'unit_price_without_vat' => 4000, 'line_total_without_vat' => $secondAmount, 'vat_rate' => 21],
            ],
            'unit_prices_stated' => false,
            'unit_prices_include_vat' => false,
            'total_without_vat' => 10000,
            'total_with_vat' => 12100,
            'vat_recap' => [['rate' => 21, 'base' => 10000, 'vat' => 2100]],
        ], $sid, $this->userId, $vendor, true);

        $stmt = $this->db->pdo()->prepare('SELECT description, quantity, unit_price_without_vat FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index');
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($keepItems) {
            self::assertSame(['Design test service', 'Hosting test service'], array_column($items, 'description'));
            self::assertSame([1.0, 1.0], array_map('floatval', array_column($items, 'quantity')));
            self::assertSame([6000.0, 4000.0], array_map('floatval', array_column($items, 'unit_price_without_vat')));
        } else {
            self::assertCount(1, $items);
            self::assertStringContainsString('rekapitulace', $items[0]['description']);
        }
        $stmt = $this->db->pdo()->prepare('SELECT total_without_vat, total_vat, total_with_vat FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        self::assertSame([10000.0, 2100.0, 12100.0], array_map('floatval', array_values($stmt->fetch(\PDO::FETCH_ASSOC))));
    }

    public static function lineAmounts(): iterable
    {
        yield 'consistent explicit rows' => [4000.0, true];
        yield 'inconsistent explicit rows' => [5000.0, false];
        yield 'missing explicit amount' => [null, false];
    }
}
