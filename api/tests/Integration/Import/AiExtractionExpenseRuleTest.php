<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Issue #148: pravidlo nákladů „vše od dodavatele je materiál na 501.100" se při AI importu
 * neuplatnilo, kontrola vytěžení nabízela jen návrh AI. Automatické pravidlo se teď na řádek
 * zapíše hned po importu, pravidlo „Jen navrhovat" zůstává návrhem.
 */
#[Group('integration')]
final class AiExtractionExpenseRuleTest extends StockTestCase
{
    public function testAutomaticRuleIsWrittenOnImportedItems(): void
    {
        [$sid, $vendor] = $this->supplierWithVendor();
        $this->rule($sid, $vendor, 'auto');

        $id = $this->createDraft($sid, $vendor);

        $row = $this->importedItem($id);
        self::assertSame('material', $row['expense_kind']);
        self::assertSame('501.100', $row['expense_account_code']);
        self::assertSame('rule', $row['expense_classification_source']);
        $review = json_decode((string) $this->db->pdo()->query('SELECT extraction_review FROM purchase_invoices WHERE id = ' . $id)->fetchColumn(), true);
        self::assertSame([], $review['expense_kinds'] ?? [], 'Řádek vyřešený pravidlem nesmí čekat na potvrzení návrhu AI.');
    }

    public function testSuggestOnlyRuleLeavesItemForReview(): void
    {
        [$sid, $vendor] = $this->supplierWithVendor();
        $this->rule($sid, $vendor, 'suggest');

        $id = $this->createDraft($sid, $vendor);

        $row = $this->importedItem($id);
        self::assertNull($row['expense_kind']);
        self::assertNull($row['expense_account_code']);
    }

    /** @return array{int,int} */
    private function supplierWithVendor(): array
    {
        $sid = $this->createSupplier();
        return [$sid, $this->client($sid, 'Fixture materiál s.r.o.')];
    }

    private function rule(int $supplierId, int $vendorId, string $mode): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO expense_classification_rules
                (supplier_id, name, vendor_client_id, expense_kind, target_account_code, application_mode)
             VALUES (?, 'Fixture materiál', ?, 'material', '501.100', ?)"
        )->execute([$supplierId, $vendorId, $mode]);
    }

    private function createDraft(int $supplierId, int $vendorId): int
    {
        $extractor = $this->container->get(AiPdfExtractor::class);
        return (new \ReflectionMethod($extractor, 'createDraft'))->invoke($extractor, [
            'document_kind' => 'invoice', 'vendor_invoice_number' => 'FIXTURE-RULE-1',
            'currency' => 'CZK', 'issue_date' => '2026-08-31', 'tax_date' => '2026-08-31', 'due_date' => '2026-09-15',
            'items' => [[
                'description' => 'Šroub M8 pozink', 'quantity' => 100, 'unit_price_without_vat' => 10, 'vat_rate' => 21,
                'expense_kind' => 'material', 'expense_kind_confidence' => 0.9, 'expense_kind_reasoning' => 'Spojovací materiál',
            ]],
            'total_without_vat' => 1000.0, 'total_with_vat' => 1210.0, 'unit_prices_include_vat' => false,
        ], $supplierId, $this->userId, $vendorId, true);
    }

    /** @return array<string,mixed> */
    private function importedItem(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT expense_kind, expense_account_code, expense_classification_source
               FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
