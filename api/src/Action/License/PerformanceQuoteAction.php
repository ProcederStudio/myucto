<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\PerformanceRecommendation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/performance/quote {enabled} — kolik by stálo zapnutí nebo
 * co znamená vypnutí výkonnostní instance. Nic nestrhává (admin only).
 *
 * Přikládá objem dat instance (`usage`), podle kterého obrazovka nabídku
 * označí jako doporučenou.
 */
final class PerformanceQuoteAction
{
    public const ERROR_MESSAGES = [
        'invalid_key' => 'Aktivní licence nenalezena. Nejprve aktivujte licenční klíč.',
        'not_managed' => 'Výkonnostní instanci lze zapnout jen u provozu zajištěného námi.',
        'not_hosted' => 'Výkonnostní instanci lze zapnout jen u provozu zajištěného námi.',
        'instance_missing' => 'K licenci není vedená žádná instance.',
        'not_bound' => 'Tato instalace není k licenci aktivně přiřazená.',
        'subscription_inactive' => 'Poměrný doplatek jde strhnout jen z aktivního předplatného s uloženou kartou.',
        'no_change' => 'Výkonnostní instance už je v požadovaném stavu.',
        'performance_price_unavailable' => 'Ceník výkonnostní instance je momentálně nedostupný. Zkuste to prosím za chvíli.',
        'upgrade_in_progress' => 'Předchozí změna licence se ještě zpracovává. Zkuste to prosím za chvíli.',
        'renewal_pending' => 'Probíhá obnova předplatného. Změnu proveďte prosím až po jejím dokončení.',
        'rate_limited' => 'Příliš mnoho pokusů. Zkuste to prosím za chvíli.',
        'server_unreachable' => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'quote_failed' => 'Cenu výkonnostní instance se nepodařilo spočítat.',
    ];

    public function __construct(
        private readonly LicenseService $license,
        private readonly ManagedModeGuard $managed,
        private readonly PerformanceRecommendation $recommendation,
    ) {}

    public static function message(string $error): string
    {
        return self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['quote_failed'];
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        if (!$this->managed->isManaged()) {
            return Json::error($response, 'not_managed', self::message('not_managed'), 409);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('enabled', $body) || !is_bool($body['enabled'])) {
            return Json::error($response, 'validation_failed', 'enabled musí být boolean.', 400);
        }

        $result = $this->license->performanceQuote($body['enabled']);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'quote_failed');

            return Json::error($response, $error, self::message($error), $error === 'server_unreachable' ? 503 : 422);
        }

        return Json::ok($response, [
            'current_enabled' => (bool) ($result['current_enabled'] ?? false),
            'new_enabled' => (bool) ($result['new_enabled'] ?? $body['enabled']),
            'pending_disable' => (bool) ($result['pending_disable'] ?? false),
            'cancel_scheduled' => (bool) ($result['cancel_scheduled'] ?? false),
            'amount' => $result['amount'] ?? null,
            'recurring_delta' => $result['recurring_delta'] ?? null,
            'monthly_price' => $result['monthly_price'] ?? null,
            'currency' => $result['currency'] ?? null,
            'period_end' => $result['period_end'] ?? null,
            'scheduled' => (bool) ($result['scheduled'] ?? false),
            'effective_at' => $result['effective_at'] ?? null,
            'ram_mb' => $result['ram_mb'] ?? null,
            'php_processes' => $result['php_processes'] ?? null,
            'base_ram_mb' => $result['base_ram_mb'] ?? null,
            'base_php_processes' => $result['base_php_processes'] ?? null,
            'quote_token' => $result['quote_token'] ?? null,
            'expires_at' => $result['quote_expires_at'] ?? $result['expires_at'] ?? null,
            'usage' => $this->recommendation->usage(),
        ]);
    }
}
