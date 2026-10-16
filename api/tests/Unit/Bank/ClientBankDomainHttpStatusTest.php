<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use PHPUnit\Framework\TestCase;

final class ClientBankDomainHttpStatusTest extends TestCase
{
    public function testDomainErrorsWithoutHttpCodeUseConflictAndPreserveValidErrorCodes(): void
    {
        $status = new \ReflectionMethod(BankStatementAction::class, 'domainHttpStatus');
        foreach ([0 => 409, 200 => 409, 403 => 403, 404 => 404, 422 => 422, 599 => 599, 600 => 409] as $code => $expected) {
            self::assertSame($expected, $status->invoke(null, new \DomainException('Synthetic conflict', $code)));
        }
    }
}
