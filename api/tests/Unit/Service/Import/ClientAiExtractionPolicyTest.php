<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\ClientAiExtractionPolicy;
use MyInvoice\Service\Import\LlmGatewayInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientAiExtractionPolicyTest extends TestCase
{
    #[DataProvider('states')]
    public function testAvailabilityRequiresOptInAndCredentialsOfRequestedSupplier(bool $enabled, ?array $credentials, bool $available): void
    {
        $row = $this->createMock(\PDOStatement::class);
        $row->expects(self::once())->method('execute')->with([42]);
        $row->method('fetchColumn')->willReturn((int) $enabled);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($row);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $gateway = $this->createMock(LlmGatewayInterface::class);
        $gateway->expects($enabled ? self::once() : self::never())->method('getCredentials')->with(42)->willReturn($credentials);
        self::assertSame([
            'client_ai_extraction_enabled' => $enabled,
            'client_ai_extraction_available' => $available,
        ], (new ClientAiExtractionPolicy($db, $gateway))->supplierState(42));
    }

    public static function states(): iterable
    {
        yield 'disabled' => [false, null, false];
        yield 'disabled with credentials' => [false, ['api_key' => 'synthetic', 'default_model' => 'synthetic'], false];
        yield 'enabled without selected key' => [true, null, false];
        yield 'enabled configured' => [true, ['api_key' => 'synthetic', 'default_model' => 'synthetic'], true];
    }
}
