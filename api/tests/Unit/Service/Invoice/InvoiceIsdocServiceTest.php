<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceIsdocService;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use MyInvoice\Tests\Support\StandaloneIsdocTestTrait;
use PHPUnit\Framework\TestCase;

final class InvoiceIsdocServiceTest extends TestCase
{
    use StandaloneIsdocTestTrait;

    protected function setUp(): void { $this->setupIsdocDatabase(); }

    public function testSupplierPreferencesAreIndependentAndDefaultOff(): void
    {
        $invoice = $this->isdocInvoice();
        self::assertFalse($this->delivery->publicEnabled($invoice));
        self::assertFalse($this->delivery->emailEnabled($invoice));
        $this->pdo->exec('UPDATE supplier SET public_isdoc_enabled = 1 WHERE id = 41');
        self::assertTrue($this->delivery->publicEnabled($invoice));
        self::assertFalse($this->delivery->emailEnabled($invoice));
        self::assertFalse($this->delivery->publicEnabled($this->isdocInvoice(['supplier_id' => 42])));
        $this->pdo->exec('UPDATE supplier SET email_attach_isdoc = 1 WHERE id = 41');
        self::assertTrue($this->delivery->emailEnabled($invoice));
        foreach (['draft', 'cancelled'] as $status) {
            self::assertFalse($this->delivery->publicEnabled($this->isdocInvoice(['status' => $status])));
            self::assertFalse($this->delivery->emailEnabled($this->isdocInvoice(['status' => $status])));
        }
    }

    public function testSharedExportValidatesAndUsesSupplierSigningContext(): void
    {
        $signing = $this->createMock(PdfSigningService::class);
        $signing->expects(self::once())->method('signIsdocIfEnabled')
            ->with(self::callback(function (string $xml): bool { $this->assertIsdocSchema($xml); return true; }), ['id' => 41], 91, 7)
            ->willReturn('signed document');
        self::assertSame('signed document', $this->isdocService(signing: $signing)->document($this->isdocInvoice(), 7));
        self::assertSame('Faktura-____.isdoc', InvoiceIsdocService::filename(['varsymbol' => '../"']));
    }

    public function testForeignCurrencyUsesStoredRateAndProducesValidIsdoc(): void
    {
        $rates = $this->createMock(ExchangeRateApplier::class);
        $rates->expects(self::never())->method('ensureRate');
        $xml = $this->isdocService(rates: $rates)->document($this->isdocInvoice(['currency' => 'EUR', 'exchange_rate' => 25]));
        $this->assertIsdocSchema($xml);
        self::assertStringContainsString('<ForeignCurrencyCode>EUR</ForeignCurrencyCode>', $xml);
        self::assertStringContainsString('<TaxInclusiveAmountCurr>121.00</TaxInclusiveAmountCurr>', $xml);
        self::assertStringContainsString('<TaxInclusiveAmount>3025.00</TaxInclusiveAmount>', $xml);
    }

    public function testMissingRateReloadsInvoiceAfterBackfill(): void
    {
        $rates = $this->createMock(ExchangeRateApplier::class);
        $rates->expects(self::once())->method('ensureRate')->with(91, true)->willReturn(null);
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('find')->with(91)->willReturn($this->isdocInvoice(['currency' => 'EUR', 'exchange_rate' => 25]));
        $this->assertIsdocSchema($this->isdocService($repo, $rates)->document($this->isdocInvoice(['currency' => 'EUR'])));
    }

    public function testDemoResolvesRateWithoutPersistence(): void
    {
        $invoice = $this->isdocInvoice(['currency' => 'EUR']);
        $resolved = ['rate' => 25];
        $rates = $this->createMock(ExchangeRateApplier::class);
        $rates->expects(self::once())->method('ensureRate')->with(91, false)->willReturn($resolved);
        $rates->expects(self::once())->method('applyResolvedToInvoiceData')->with($invoice, $resolved)
            ->willReturn($invoice + ['exchange_rate' => 25]);
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::never())->method('find');
        $this->assertIsdocSchema($this->isdocService($repo, $rates)->document($invoice, persistRate: false));
    }
}
