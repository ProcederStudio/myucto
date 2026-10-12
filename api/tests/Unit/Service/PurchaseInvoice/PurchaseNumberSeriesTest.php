<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\PurchaseInvoice;

use MyInvoice\Service\PurchaseInvoice\PurchaseNumberSeries;
use PHPUnit\Framework\TestCase;

final class PurchaseNumberSeriesTest extends TestCase
{
    public function testSharedModeUsesOneSeriesForAllKinds(): void
    {
        $series = PurchaseNumberSeries::all(['purchase_invoice_numbering' => 'shared']);

        self::assertSame([''], array_keys($series));
        self::assertSame('{PP}{YY}{MM}{CCC}', $series['']['template']);
        self::assertSame(PurchaseNumberSeries::KINDS, $series['']['kinds']);
    }

    public function testByKindSeparatesSeriesAndCounterKeys(): void
    {
        $settings = ['purchase_invoice_numbering' => 'by_kind', 'purchase_receipt_number_format' => 'PAR{YYYY}{CCCC}'];

        self::assertSame(['key' => '', 'template' => '{PP}{YY}{MM}{CCC}'], PurchaseNumberSeries::resolve($settings, 'invoice'));
        self::assertSame(['key' => 'receipt', 'template' => 'PAR{YYYY}{CCCC}'], PurchaseNumberSeries::resolve($settings, 'receipt'));
        self::assertSame('DD{YY}{MM}{CCC}', PurchaseNumberSeries::resolve($settings, 'credit_note')['template']);
        self::assertSame('202610', PurchaseNumberSeries::counterKey('', '{PP}{YY}{MM}{CCC}', '202610'));
        self::assertSame('receipt:2026', PurchaseNumberSeries::counterKey('receipt', 'PAR{YYYY}{CCCC}', '202610'));
    }

    public function testInvoicePatternIgnoresOtherTwoLetterPrefixes(): void
    {
        $regex = PurchaseNumberSeries::counterRegex('{PP}{YY}{MM}{CCC}', '202610');

        self::assertSame(1, preg_match($regex, 'NN2610007', $m));
        self::assertSame('007', $m[1]);
        self::assertSame(0, preg_match($regex, 'UC2610007'));
    }

    public function testOverlapsDetectsIndistinguishableSeries(): void
    {
        self::assertSame([], PurchaseNumberSeries::overlaps(['purchase_invoice_numbering' => 'by_kind']));
        self::assertSame([], PurchaseNumberSeries::overlaps([
            'purchase_invoice_numbering' => 'shared', 'purchase_receipt_number_format' => '{PP}{YY}{MM}{CCC}',
        ]), 'Ve společné řadě se šablony typů nepoužijí.');

        $overlaps = PurchaseNumberSeries::overlaps([
            'purchase_invoice_numbering' => 'by_kind', 'purchase_receipt_number_format' => '{PP}{YY}{MM}{CCC}',
        ]);
        self::assertSame([['', 'receipt']], $overlaps);
    }
}
