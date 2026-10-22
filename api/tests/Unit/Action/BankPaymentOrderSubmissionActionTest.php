<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action;

use MyInvoice\Action\PurchaseInvoice\BankPaymentOrderSubmissionAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnector;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\Connector\BankPaymentOrderSubmissionService;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionService;
use MyInvoice\Service\Payment\PaymentOrderService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[AllowMockObjectsWithoutExpectations]
final class BankPaymentOrderSubmissionActionTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function testHttpStatusFollowsPersistedSubmissionOutcome(string $status, int $expectedHttp, bool $ok): void
    {
        $response = $this->action($status)->post($this->request(), new Response(), ['orderId' => 501]);

        self::assertSame($expectedHttp, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame($status, $body['submission']['status'] ?? null);
        self::assertSame($ok, !isset($body['error']));
    }

    public static function outcomes(): iterable
    {
        yield 'čeká na autorizaci' => ['accepted_awaiting_authorization', 202, true];
        yield 'banka převzala soubor k importu' => ['import_started', 202, true];
        yield 'banka odmítla' => ['rejected', 422, true];
        yield 'nejistý výsledek' => ['unknown', 502, true];
    }

    private function action(string $finalStatus): BankPaymentOrderSubmissionAction
    {
        $connections = $this->createMock(BankConnectionRepository::class);
        $submissions = $this->createMock(BankPaymentOrderSubmissionRepository::class);
        $orders = $this->createMock(PaymentOrderRepository::class);
        $paymentOrders = $this->createMock(PaymentOrderService::class);
        $registry = $this->createMock(BankConnectorRegistry::class);
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $secrets = $this->createMock(SecretEncryption::class);

        $connector = new class ($finalStatus) implements BankConnector {
            public function __construct(private readonly string $status) {}
            public function provider(): string { return 'rb'; }
            public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
            {
                throw new \LogicException('Výpis se v testu odeslání nestahuje.');
            }
            public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
            {
                return match ($this->status) {
                    'import_started' => ['accepted' => true, 'reference' => 'SYNTHETIC-ASYNC', 'status' => 'import_started'],
                    'accepted_awaiting_authorization' => ['accepted' => true, 'reference' => 'SYNTHETIC-REF'],
                    'rejected' => throw new \MyInvoice\Service\Bank\Connector\BankConnectorException('payment_rejected', 'safe', false, 422),
                    default => throw new \MyInvoice\Service\Bank\Connector\BankConnectorException('remote_unavailable', 'safe', true),
                };
            }
        };

        $connections->method('findWithCredentialById')->willReturn([
            'id' => 101, 'supplier_id' => 1, 'currency_id' => 11, 'provider' => 'rb',
            'enabled' => true, 'has_token' => true, 'token_ciphertext' => 'enc:v2:synthetic', 'is_active' => true,
            'account_code' => 'CZK', 'bank_code' => '5500', 'account_number' => '1000000005', 'iban' => null,
            'verified_account_number' => '1000000005', 'verified_bank_code' => '5500', 'verified_currency' => 'CZK',
        ]);
        $calls->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $cb): mixed => $cb());
        $calls->method('call')->willReturnCallback(static fn (string $t, callable $cb): mixed => $cb());
        $orders->method('find')->willReturn([
            'id' => 501, 'supplier_id' => 1, 'currency' => 'CZK', 'payer_currency_id' => 11,
            'payer_account_number' => '1000000005', 'payer_bank_code' => '5500', 'mark_paid' => false,
            'payment_date' => date('Y-m-d'),
        ]);
        $orders->method('allItemsStillPayable')->willReturn(true);
        $paymentOrders->method('download')->willReturn(['bytes' => 'SYNTHETIC-ABO']);
        $secrets->method('validateKey')->willReturn(null);
        $secrets->method('decryptFor')->willReturn('synthetic-token');
        $registry->method('get')->willReturn($connector);
        $registry->method('supportsBankCode')->willReturn(true);

        $row = static fn (string $status): array => [
            'id' => 901, 'payment_order_id' => 501, 'connection_id' => 101, 'provider' => 'rb', 'status' => $status,
            'provider_reference' => null, 'error_code' => null, 'accepted_count' => null, 'rejected_count' => null,
            'created_at' => '2026-10-22 10:00:00', 'submitted_at' => null,
        ];
        $submissions->method('find')->willReturnOnConsecutiveCalls(null, $row($finalStatus));
        $submissions->method('begin')->willReturn(['created' => true, 'submission' => $row('unknown')]);

        return new BankPaymentOrderSubmissionAction(new BankPaymentOrderSubmissionService(
            new BankPaymentSubmissionService($connections, $submissions, $registry, $calls, $secrets),
            $orders,
            $paymentOrders,
        ));
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoices/payment-orders/501/submit')
            ->withParsedBody(['connection_id' => 101])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute('auth.effective_role', new EffectiveRole(10, 'Testovací role', 'staff', true, [
                'purchase_invoices.payment_orders' => AccessLevel::WRITE->value,
                'settings.bank_accounts' => AccessLevel::WRITE->value,
            ]));
    }
}
