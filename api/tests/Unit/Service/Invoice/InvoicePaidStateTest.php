<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoicePaidState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoicePaidStateTest extends TestCase
{
    #[DataProvider('cases')]
    public function testEffectivelyPaidAndAmountDue(string $status, float $toPay, float $paid, bool $isPaid, float $due): void
    {
        $invoice = ['status' => $status, 'amount_to_pay' => $toPay, 'paid_total' => $paid];

        self::assertSame($isPaid, InvoicePaidState::isEffectivelyPaid($invoice));
        self::assertSame($due, InvoicePaidState::amountDue($invoice));
    }

    public static function cases(): array
    {
        return [
            'nezaplacená'                     => ['issued', 1000.0, 0.0, false, 1000.0],
            'částečně zaplacená'              => ['issued', 1000.0, 400.0, false, 600.0],
            'zaplacená přesně'                => ['paid', 1000.0, 1000.0, true, 0.0],
            'zbytek v toleranci'              => ['paid', 1000.0, 999.97, true, 0.0],
            'přeplatek'                       => ['paid', 1000.0, 1100.0, true, 0.0],
            'starší doklad bez plateb'        => ['paid', 1000.0, 0.0, true, 0.0],
            'paid s doplatkem po úpravě'      => ['paid', 1100.0, 1000.0, false, 100.0],
            'vratka vyplacená (záporná)'      => ['paid', -608.0, -608.0, true, 0.0],
        ];
    }
}
