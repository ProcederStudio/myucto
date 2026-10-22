<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankPaymentSignatureService;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionJournal;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionService;
use MyInvoice\Service\Bank\Connector\SignableBankPaymentConnector;
use MyInvoice\Service\OAuth\OAuthAuthorization;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Podpis dávky v bance a zjišťování výsledku. Repozitář je simulovaný v paměti se
 * stejnou podmínkou přechodu jako SQL (stav se mění jen z očekávaného výchozího stavu).
 */
#[AllowMockObjectsWithoutExpectations]
final class BankPaymentSignatureServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $state = [];
    /** @var array<int,array<string,mixed>> */
    private array $items = [];
    private SigningConnector $connector;
    private OAuthClientFlow $flow;

    protected function setUp(): void
    {
        $this->connector = new SigningConnector();
        $this->state = ['id' => 901, 'supplier_id' => 1, 'payment_order_id' => 501, 'payroll_batch_id' => null, 'connection_id' => 101,
            'provider' => 'csas', 'status' => 'awaiting_signature', 'provider_reference' => 'SYNTHETIC-BATCH',
            'provider_sign_hash' => 'synthetic-batch-hash', 'provider_poll_id' => null, 'signature_state' => null,
            'age_seconds' => 60, 'polled_seconds_ago' => null, 'signature_requested_seconds_ago' => null,
            'error_code' => null, 'accepted_count' => null, 'rejected_count' => null];
        $this->items = [
            1 => ['line_no' => 1, 'status' => 'created', 'provider_sign_id' => 'SIGN-1', 'instruction_status' => 'ACTC'],
            2 => ['line_no' => 2, 'status' => 'created', 'provider_sign_id' => 'SIGN-2', 'instruction_status' => 'ACTC'],
        ];
    }

    public function testSigningUrlIsIssuedForTheSameBatchAndReturnStateIsBoundToSubmission(): void
    {
        $stored = null;
        $flow = $this->createMock(OAuthClientFlow::class);
        $flow->method('authorize')->willReturn(new OAuthAuthorization(str_repeat('d', 64), null));
        $flow->method('callbackUrl')->willReturn('https://ucto.example.test/api/oauth/callback');
        $flow->expects(self::once())->method('store')->willReturnCallback(
            function (OAuthAuthorization $a, int $supplier, int $user, string $domain, string $provider, int $subject, string $flowType,
                array $secret, int $ttl) use (&$stored): void {
                $stored = compact('supplier', 'user', 'domain', 'provider', 'subject', 'secret', 'ttl');
            });
        $result = $this->service($flow)->startSignature(1, 501, 'purchase_invoice', 7);

        self::assertSame('https://webapi.developers.erstegroup.com/api/csas/sandbox/v1/sandbox-idp/sign', $result['signing_url']);
        self::assertSame([['SYNTHETIC-BATCH', 'synthetic-batch-hash', 'https://ucto.example.test/api/oauth/callback?state=' . str_repeat('d', 64)]],
            $this->connector->signatures);
        self::assertSame(['supplier' => 1, 'user' => 7, 'domain' => 'bank', 'provider' => 'csas_sign', 'subject' => 901,
            'secret' => ['source' => 'purchase_invoice', 'order_id' => 501], 'ttl' => 1800], $stored);
        self::assertSame('160527036796442', $this->state['provider_poll_id']);
        self::assertSame('awaiting_signature', $this->state['status']);
    }

    #[DataProvider('notSignable')]
    public function testSigningIsRefusedOutsideAwaitingSignature(string $status): void
    {
        $this->state['status'] = $status;
        try {
            $this->service()->startSignature(1, 501, 'purchase_invoice', 7);
            self::fail('Podpis jde jen u dávky čekající na podpis.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('payment_signature_unavailable', $e->errorCode);
        }
        self::assertSame([], $this->connector->signatures);
    }

    public static function notSignable(): iterable
    {
        foreach (['creating', 'unknown', 'rejected', 'signed', 'completed', 'expired'] as $status) yield $status => [$status];
    }

    public function testDoneSignatureAndSettledPaymentsComplete(): void
    {
        $this->state['provider_poll_id'] = '160527036796442';
        $this->connector->poll = 'DONE';
        $this->connector->payments = ['SIGN-1' => 'ACSC', 'SIGN-2' => 'ACSC'];

        self::assertSame('completed', $this->service()->refresh(1, 501, 'purchase_invoice')['status']);
        self::assertSame(2, $this->state['accepted_count']);
        self::assertNull($this->state['next_poll_seconds']);
    }

    public function testRejectedPaymentAfterSignatureIsPartiallyRejected(): void
    {
        $this->state['status'] = 'signed';
        $this->connector->payments = ['SIGN-1' => 'ACSC', 'SIGN-2' => 'RJCT'];

        self::assertSame('partially_rejected', $this->service()->refresh(1, 501, 'purchase_invoice')['status']);
        self::assertSame([1, 1], [$this->state['accepted_count'], $this->state['rejected_count']]);
        self::assertSame('payment_partially_rejected', $this->state['error_code']);
    }

    public function testPaymentsStillProcessingKeepSignedAndScheduleNextPoll(): void
    {
        $this->state['status'] = 'signed';
        $this->connector->payments = ['SIGN-1' => 'ACSC', 'SIGN-2' => 'ACSP'];

        self::assertSame('signed', $this->service()->refresh(1, 501, 'purchase_invoice')['status']);
        self::assertSame(1800, $this->state['next_poll_seconds']);
        $this->state['polled_seconds_ago'] = 600;
        $this->connector->payments = ['SIGN-2' => 'ACSC'];
        self::assertSame('completed', $this->service()->refresh(1, 501, 'purchase_invoice')['status']);
        self::assertSame(['SIGN-1', 'SIGN-2', 'SIGN-2'], $this->connector->statusQueries, 'Konečný stav se znovu nedotazuje.');
    }

    #[DataProvider('signatureOutcomes')]
    public function testSignatureOutcomeMovesStateWithoutCreatingAnything(string $poll, string $status, ?string $errorCode, ?string $pollId): void
    {
        $this->state['provider_poll_id'] = '160527036796442';
        $this->connector->poll = $poll;

        self::assertSame($status, $this->service()->refresh(1, 501, 'purchase_invoice')['status']);
        self::assertSame($errorCode, $this->state['error_code']);
        self::assertSame($pollId, $this->state['provider_poll_id']);
        self::assertSame(0, $this->connector->submissions, 'Dotaz na stav nikdy nezakládá platby.');
    }

    public static function signatureOutcomes(): iterable
    {
        yield 'odmítnuto' => ['REJECTED', 'rejected', 'payment_signature_rejected', '160527036796442'];
        yield 'selhalo zpracování' => ['ORDER_PROCESSING_FAILED', 'rejected', 'payment_signature_rejected', '160527036796442'];
        yield 'vypršel podpis' => ['EXPIRED', 'awaiting_signature', 'payment_signature_expired', null];
        yield 'ještě nezačal' => ['AUTHORIZATION_NOT_STARTED', 'awaiting_signature', null, '160527036796442'];
    }

    public function testUnsignedBatchExpiresAfterThirtyDays(): void
    {
        $this->state['age_seconds'] = 31 * 86400;
        self::assertSame('expired', $this->service()->refresh(1, 501, 'purchase_invoice')['status']);
        self::assertSame([], $this->connector->statusQueries);
    }

    public function testRepeatedRefreshWithinFewSecondsDoesNotCallBank(): void
    {
        $this->state['provider_poll_id'] = '160527036796442';
        $this->state['polled_seconds_ago'] = 2;
        self::assertSame('awaiting_signature', $this->service()->refresh(1, 501, 'purchase_invoice')['status']);
        self::assertSame(0, $this->connector->polls);
    }

    public function testBankFailureDuringPollIsServiceErrorAndPostponesNextPoll(): void
    {
        $this->state['provider_poll_id'] = '160527036796442';
        $this->connector->failPoll = true;
        try {
            $this->service()->refresh(1, 501, 'purchase_invoice');
            self::fail('Výpadek banky musí skončit chybou služby.');
        } catch (BankConnectorOperationException $e) {
            self::assertSame('payment_status_unavailable', $e->errorCode);
        }
        self::assertSame(1800, $this->state['next_poll_seconds']);
        self::assertSame('awaiting_signature', $this->state['status']);
    }

    public function testCallbackWithoutPaymentOrderWriteIsRefusedAndNotPolled(): void
    {
        $this->state['provider_poll_id'] = '160527036796442';
        $flow = $this->createMock(OAuthClientFlow::class);
        $flow->method('claim')->willReturn($this->session(['source' => 'purchase_invoice', 'order_id' => 501]));
        $flow->expects(self::once())->method('finish')->with(self::anything(), false, 'forbidden');

        $location = $this->service($flow)->completeCallback($this->returnFromBank(['settings.bank_accounts' => AccessLevel::WRITE->value]));

        self::assertSame('/purchase-invoices/payment-orders?bank_order=501&bank_sign=error', $location);
        self::assertSame(0, $this->connector->polls);
    }

    public function testCallbackPollsAndReturnsToPaymentOrder(): void
    {
        $this->state['provider_poll_id'] = '160527036796442';
        $this->connector->poll = 'DONE';
        $this->connector->payments = ['SIGN-1' => 'ACSP', 'SIGN-2' => 'ACSP'];
        $flow = $this->createMock(OAuthClientFlow::class);
        $flow->method('claim')->willReturn($this->session(['source' => 'purchase_invoice', 'order_id' => 501]));
        $flow->expects(self::once())->method('finish')->with(self::anything(), true);

        $location = $this->service($flow)->completeCallback($this->returnFromBank([
            'settings.bank_accounts' => AccessLevel::WRITE->value, 'purchase_invoices.payment_orders' => AccessLevel::WRITE->value,
        ]));

        self::assertSame('/purchase-invoices/payment-orders?bank_order=501&bank_sign=returned', $location);
        self::assertSame('signed', $this->state['status']);
    }

    public function testUsedOrForeignStateReturnsErrorWithoutTouchingSubmission(): void
    {
        $flow = $this->createMock(OAuthClientFlow::class);
        $flow->method('claim')->willReturn(null);
        $location = $this->service($flow)->completeCallback($this->returnFromBank([]));
        self::assertSame('/purchase-invoices/payment-orders?bank_sign=error', $location);
        self::assertSame(0, $this->connector->polls);
    }

    private function service(?OAuthClientFlow $flow = null): BankPaymentSignatureService
    {
        $repository = $this->createMock(BankPaymentOrderSubmissionRepository::class);
        $repository->method('find')->willReturnCallback(fn (): array => ['id' => 901, 'payment_order_id' => 501,
            'status' => $this->state['status'], 'error_code' => $this->state['error_code']]);
        $repository->method('signingState')->willReturnCallback(fn (): array => $this->state);
        $repository->method('items')->willReturnCallback(fn (): array => $this->items);
        $repository->method('recordItemStatus')->willReturnCallback(function (int $s, int $id, int $line, string $status): void {
            $this->items[$line]['instruction_status'] = $status;
        });
        $repository->method('updateSigning')->willReturnCallback(function (int $s, int $id, array $from, array $changes): bool {
            if (!in_array($this->state['status'], $from, true)) return false;
            foreach ($changes as $key => $value) {
                if (!in_array($key, ['polled', 'signature_requested'], true)) $this->state[$key] = $value;
            }
            if (($changes['polled'] ?? false) === true) $this->state['polled_seconds_ago'] = 0;
            return true;
        });
        $engine = $this->createMock(BankPaymentSubmissionService::class);
        $engine->method('signingAccess')->willReturn(['connector' => $this->connector, 'token' => 'synthetic-token',
            'connection' => ['currency_id' => 11]]);
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $calls->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $cb): mixed => $cb());
        return new BankPaymentSignatureService($repository, $engine, $calls, $flow ?? $this->createMock(OAuthClientFlow::class));
    }

    private function session(array $secret): OAuthSession
    {
        return new OAuthSession(str_repeat('e', 64), 1, 7, 'bank', 'csas_sign', 901, 'redirect', 'oauth', 'processing', false, $secret, null);
    }

    /** @param array<string,int> $permissions */
    private function returnFromBank(array $permissions): OAuthCallback
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/oauth/callback')
            ->withAttribute('auth.effective_role', new EffectiveRole(10, 'Testovací role', 'staff', true, $permissions));
        return new OAuthCallback(['supplier_id' => 1, 'user_id' => 7, 'provider' => 'csas_sign', 'subject_id' => 901],
            str_repeat('d', 64), '', null, [], $request);
    }
}

final class SigningConnector implements SignableBankPaymentConnector
{
    /** @var list<array{0:string,1:string,2:string}> */
    public array $signatures = [];
    /** @var list<string> */
    public array $statusQueries = [];
    /** @var array<string,string> */
    public array $payments = [];
    public string $poll = 'PROCESSING';
    public bool $failPoll = false;
    public int $polls = 0;
    public int $submissions = 0;

    public function provider(): string { return 'csas'; }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        throw new \LogicException('Výpis se v testu podpisu nestahuje.');
    }

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        $this->submissions++;
        throw new \LogicException('Podpis nesmí zakládat platby.');
    }

    public function assertPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): void {}

    public function submitStagedPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo,
        BankPaymentSubmissionJournal $journal): array
    {
        $this->submissions++;
        throw new \LogicException('Podpis nesmí zakládat platby.');
    }

    public function startSignature(#[\SensitiveParameter] string $token, string $batchSignId, string $batchHash, string $callbackUri): array
    {
        $this->signatures[] = [$batchSignId, $batchHash, $callbackUri];
        return ['signing_url' => 'https://webapi.developers.erstegroup.com/api/csas/sandbox/v1/sandbox-idp/sign', 'valid_to' => null,
            'poll_id' => '160527036796442', 'poll_interval_ms' => 5000];
    }

    public function signatureState(#[\SensitiveParameter] string $token, string $pollId): array
    {
        $this->polls++;
        if ($this->failPoll) throw new BankConnectorException(BankConnectorException::REMOTE_UNAVAILABLE, 'synthetic');
        return ['state' => $this->poll, 'poll_interval_ms' => 5000];
    }

    public function paymentState(#[\SensitiveParameter] string $token, string $signId): array
    {
        $this->statusQueries[] = $signId;
        return ['instruction_status' => $this->payments[$signId] ?? 'ACTC', 'status_change_info' => null];
    }
}
