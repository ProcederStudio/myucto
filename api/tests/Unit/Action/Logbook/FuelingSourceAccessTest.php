<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Logbook;

use MyInvoice\Action\Logbook\FuelingSourceAccess;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Logbook\FuelingDocumentRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class FuelingSourceAccessTest extends TestCase
{
    public static function extractedSources(): iterable
    {
        yield 'bank requires bank, invoice read is insufficient' => ['bank_transaction', ['purchase_invoices' => 1], false];
        yield 'bank read exposes its extracted text' => ['bank_transaction', ['bank' => 1], true];
        yield 'journal requires accounting, invoice read is insufficient' => ['journal_entry', ['purchase_invoices' => 1], false];
        yield 'accounting read exposes journal text' => ['journal_entry', ['accounting' => 1], true];
        yield 'document has no identifiable source' => ['document', ['purchase_invoices' => 1, 'documents' => 1], false];
        yield 'invoice read exposes invoice text' => ['purchase_invoice', ['purchase_invoices' => 1], true];
        yield 'cash read exposes cash text' => ['cash_document', ['cash' => 1], true];
    }

    #[DataProvider('extractedSources')]
    public function testExtractedTextRequiresItsActualSource(string $type, array $permissions, bool $visible): void
    {
        $ref = FuelingDocumentRef::of($type, 17);
        $row = ['source' => $ref->fuelingSource(), 'raw_text' => 'Syntetický úplný text zdrojového dokladu'];
        if ($ref->linkColumn() !== null) $row[$ref->linkColumn()] = $ref->id;
        $redacted = $this->redact($permissions, $row);
        self::assertSame($visible ? $row['raw_text'] : null, $redacted['raw_text']);
        if (!$visible && $ref->linkColumn() !== null) self::assertNull($redacted[$ref->linkColumn()]);
    }

    public function testUnknownImportDoesNotInheritPurchaseInvoiceAccess(): void
    {
        $row = ['source' => 'import', 'raw_text' => 'Syntetický text neznámého původu'];
        self::assertNull($this->redact(['purchase_invoices' => 1], $row)['raw_text']);
    }

    public function testAllLinkedSourcesMustBeReadableBeforeTextIsExposed(): void
    {
        $row = ['source' => 'invoice', 'source_purchase_invoice_id' => 17,
            'source_bank_transaction_id' => 18, 'raw_text' => 'Syntetický text'];
        $restricted = $this->redact(['purchase_invoices' => 1], $row);
        self::assertNull($restricted['source_bank_transaction_id']);
        self::assertNull($restricted['raw_text'], 'Původ se posuzuje před skrytím vazeb.');
        self::assertSame($row['raw_text'], $this->redact(['purchase_invoices' => 1, 'bank' => 1], $row)['raw_text']);
    }

    private function redact(array $permissions, array $row): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/logbook/fuelings')
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Vlastní role', 'staff', true, $permissions));
        return FuelingSourceAccess::redact($request, $row);
    }
}
