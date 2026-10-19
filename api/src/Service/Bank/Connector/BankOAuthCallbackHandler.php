<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthCallbackHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Návrat z banky na společný OAuth callback (doména bank). Připojení dokončí služba
 * poskytovatele; prohlížeč se vrátí na Banku stejně jako dřív z callbacku banky.
 */
final class BankOAuthCallbackHandler implements OAuthCallbackHandler
{
    public const DOMAIN = 'bank';

    public function __construct(
        private readonly CsasOnboardingService $csas,
        private readonly LoggerInterface $logger,
    ) {}

    public function domain(): string
    {
        return self::DOMAIN;
    }

    public function allows(Request $request): bool
    {
        return RequestAuthorization::isSessionAuth($request)
            && RequestAuthorization::allows($request, 'settings.bank_accounts', AccessLevel::WRITE);
    }

    public function complete(OAuthCallback $callback): string
    {
        $provider = (string) $callback->session['provider'];
        try {
            return match ($provider) {
                CsasOnboardingService::PROVIDER => self::csasLocation('connected', $this->csas->completeCallback($callback)),
                default => throw new BankConnectorOperationException('provider_not_supported'),
            };
        } catch (BankConnectorOperationException $e) {
            $this->logger->warning($provider === CsasOnboardingService::PROVIDER ? 'csas_oauth_callback_failed' : 'bank_oauth_callback_failed', [
                'provider' => $provider,
                'reason' => $e->errorCode,
                'authorization_error' => $callback->error ?? 'unspecified',
                'has_code' => $callback->code !== '',
            ]);
            return $this->failureLocation($callback->session, $e->errorCode);
        }
    }

    public function failureLocation(array $session, string $errorCode): string
    {
        return match ($session['provider'] ?? null) {
            CsasOnboardingService::PROVIDER => self::csasLocation('error'),
            default => '/bank?' . http_build_query(['tab' => 'accounts']),
        };
    }

    /** Návrat na Banku po připojení ČSAS (stejný tvar jako před společnou vrstvou). */
    public static function csasLocation(string $status, ?int $currencyId = null): string
    {
        return '/bank?' . http_build_query(['tab' => 'accounts', 'csas' => $status]
            + ($currencyId !== null ? ['currency_id' => $currencyId] : []));
    }
}
