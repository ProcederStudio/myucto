<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\{BankConnectionRepository, CsasOAuthRepository};
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\{CsasApiClient, CsasCredentialVault, CsasOnboardingService, BankConnectorCallGuard, BankConnectorOperationException};
use MyInvoice\Service\OAuth\{OAuthAuthorization, OAuthCallback, OAuthClientFlow, OAuthSession, Pkce};
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class CsasOnboardingServiceTest extends TestCase
{
    private function setupService(): array
    {
        $oauth = $this->createMock(CsasOAuthRepository::class);
        $connections = $this->createMock(BankConnectionRepository::class);
        $api = $this->createMock(CsasApiClient::class);
        $api->method('environment')->willReturn('production');
        $vault = $this->createMock(CsasCredentialVault::class);
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $secrets = $this->createMock(SecretEncryption::class);
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('https://example.invalid');
        $calls->method('withConnectionLock')->willReturnCallback(static fn (int $supplier, int $currency, callable $fn): mixed => $fn());
        $oauth->method('account')->willReturn(['id' => 3, 'supplier_id' => 7, 'bank_code' => '0800', 'code' => 'CZK',
            'account_number' => '1000000005', 'iban' => null, 'is_active' => true]);
        $this->flow = $this->createMock(OAuthClientFlow::class);
        return [new CsasOnboardingService($oauth, $connections, $api, $vault, $calls, $secrets, $config, $this->flow), $oauth, $connections, $api, $vault, $secrets];
    }

    private OAuthClientFlow&\PHPUnit\Framework\MockObject\MockObject $flow;

    public function testExpiredOrReplayedStateCannotExchangeTokens(): void
    {
        [$service, $oauth, , $api] = $this->setupService();
        $oauth->method('currency')->willReturn(3);
        $oauth->expects(self::once())->method('claim')->with(hash('sha256', str_repeat('a', 64)), 7, 11)->willReturn(null);
        $api->expects(self::never())->method('exchange');
        $this->expectException(BankConnectorOperationException::class);
        $service->complete(7, 11, str_repeat('a', 64), 'synthetic-code');
    }

    public function testCallbackMatchesLocalAccountBeforeSavingAndNeverImportsDuringConsent(): void
    {
        [$service, $oauth, $connections, $api, $vault, $secrets] = $this->setupService();
        $hash = hash('sha256', str_repeat('a', 64));
        $oauth->method('currency')->willReturn(3);
        $oauth->method('claim')->willReturn(['currency_id' => 3, 'secret_ciphertext' => 'enc:v2:flow']);
        $secrets->method('decryptFor')->willReturn(json_encode(['api_key' => 'synthetic-key', 'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://example.invalid/api/settings/bank-connections/csas/oauth/callback',
            'code_verifier' => str_repeat('b', 64)]));
        $api->method('exchange')->willReturn(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_in' => 300]);
        $api->method('accounts')->willReturn([['id' => 'synthetic-remote', 'identification' => ['iban' => 'CZ7508000000001000000005'], 'currency' => 'CZK']]);
        $api->expects(self::never())->method('transactions');
        $connections->expects(self::once())->method('ensure')->with(7, 3, 'csas')->willReturn(9);
        $vault->expects(self::once())->method('encode')->with(self::callback(static fn (array $data): bool =>
            $data['supplier_id'] === 7 && $data['connection_id'] === 9 && !isset($data['code_verifier'])))->willReturn('synthetic-encoded');
        $secrets->method('encryptFor')->willReturn('enc:v2:saved');
        $connections->expects(self::once())->method('saveValidated')->with(7, 9, 'csas', 'enc:v2:saved', true, 'CZ7508000000001000000005', '0800', 'CZK');
        $oauth->expects(self::once())->method('finish')->with($hash, true);
        self::assertSame(3, $service->complete(7, 11, str_repeat('a', 64), 'synthetic-code'));
    }

    public function testStartStoresOnlyEncryptedFlowAndReturnsNoCredentials(): void
    {
        [$service, $oauth, , $api, , $secrets] = $this->setupService();
        $secrets->method('validateKey')->willReturn(null);
        $authorization = new OAuthAuthorization(str_repeat('c', 64), Pkce::verifier());
        $this->flow->method('authorize')->willReturn($authorization);
        $api->expects(self::once())->method('authorizationUrl')->with(self::callback(static fn (array $credentials): bool =>
            $credentials['code_verifier'] === $authorization->codeVerifier), $authorization->state)
            ->willReturn('https://bezpecnost.csas.cz/api/psd2/fl/oidc/v1/auth?state=synthetic');
        $oauth->expects(self::once())->method('cancelPending')->with(7, 3);
        // Rozpracované přihlášení ukládá šifrovaně společná vrstva; verifier v datech poskytovatele není.
        $this->flow->expects(self::once())->method('store')->with($authorization, 7, 11, 'bank', 'csas', 3, OAuthClientFlow::FLOW_REDIRECT,
            self::callback(static fn (array $secret): bool => $secret['client_secret'] === 'synthetic-secret' && !isset($secret['code_verifier'])
                && $secret['redirect_uri'] === 'https://example.invalid/api/settings/bank-connections/csas/oauth/callback'), 600);
        self::assertSame(['redirect_url' => 'https://bezpecnost.csas.cz/api/psd2/fl/oidc/v1/auth?state=synthetic'],
            $service->start(7, 3, 11, ['api_key' => 'synthetic-key', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret']));
    }

    public function testCallbackOfSharedFlowExchangesCodeWithVerifierFromTheLayer(): void
    {
        [$service, , $connections, $api, $vault, $secrets] = $this->setupService();
        $session = new OAuthSession(str_repeat('d', 64), 7, 11, 'bank', 'csas', 3, OAuthClientFlow::FLOW_REDIRECT, 'oauth', 'processing', false,
            ['api_key' => 'synthetic-key', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret',
                'redirect_uri' => 'https://example.invalid/api/settings/bank-connections/csas/oauth/callback', 'environment' => 'production'],
            str_repeat('v', 64));
        $this->flow->expects(self::once())->method('claim')->with(str_repeat('a', 64), 7, 11, 'bank', 'csas')->willReturn($session);
        $api->expects(self::once())->method('exchange')->with(self::callback(static fn (array $c): bool => $c['code_verifier'] === str_repeat('v', 64)), 'synthetic-code')
            ->willReturn(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_in' => 300]);
        $api->method('accounts')->willReturn([['id' => 'synthetic-remote', 'identification' => ['iban' => 'CZ7508000000001000000005'], 'currency' => 'CZK']]);
        $connections->method('ensure')->willReturn(9);
        $vault->method('encode')->willReturn('synthetic-encoded');
        $secrets->method('encryptFor')->willReturn('enc:v2:saved');
        $this->flow->expects(self::once())->method('finish')->with($session, true);

        self::assertSame(3, $service->completeCallback($this->oauthCallback(str_repeat('a', 64), 'synthetic-code')));
    }

    public function testCallbackOfExpiredOrForeignSharedFlowNeverExchangesTokens(): void
    {
        [$service, , , $api] = $this->setupService();
        $this->flow->method('claim')->willReturn(null);
        $api->expects(self::never())->method('exchange');

        $this->expectExceptionObject(new BankConnectorOperationException('csas_oauth_expired'));
        $service->completeCallback($this->oauthCallback(str_repeat('a', 64), 'synthetic-code'));
    }

    private function oauthCallback(string $state, string $code): OAuthCallback
    {
        return new OAuthCallback(['supplier_id' => 7, 'user_id' => 11, 'domain' => 'bank', 'provider' => 'csas', 'subject_id' => 3],
            $state, $code, null, [], (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/oauth/callback'));
    }
}
