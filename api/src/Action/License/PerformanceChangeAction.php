<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/performance {enabled, quote_token} — zapnutí nebo vypnutí
 * výkonnostní instance (admin only).
 *
 * Zapnutí strhne poměrný doplatek do konce zaplaceného období; u poskytovatele
 * se pak čeká na provedení (`vendor_state = enable_requested`). Vypnutí platí
 * ke konci období a zapnutí během naplánovaného vypnutí jen to vypnutí zruší.
 */
final class PerformanceChangeAction
{
    public const ERROR_MESSAGES = PerformanceQuoteAction::ERROR_MESSAGES + [
        'charge_failed' => 'Platbu se nepodařilo strhnout z uložené karty. Doplatek zaplatíte '
            . 'jinou kartou přes odkaz níž — poslali jsme ho i e-mailem.',
        'charge_pending' => 'Platba se zpracovává. Nekupujte prosím znovu — jakmile ji brána potvrdí, změna se projeví sama.',
        'card_payment_required' => UpgradeLicenseAction::CARD_PAYMENT_REQUIRED_MESSAGE,
        'payments_disabled' => 'Platby jsou dočasně pozastavené. Zkuste to prosím později.',
        'result_unknown' => 'Odpověď licenčního serveru nedorazila. Než to zkusíte znovu, ověřte prosím stav licence — '
            . 'změna mohla proběhnout.',
        'quote_required' => 'Nejdříve si nechte spočítat aktuální cenu.',
        'quote_expired' => 'Kalkulace vypršela. Nechte si prosím spočítat aktuální cenu znovu.',
        'quote_mismatch' => 'Kalkulace neodpovídá zadání. Nechte si prosím spočítat cenu znovu.',
        'quote_changed' => 'Cena se mezitím změnila. Nechte si prosím spočítat aktuální cenu znovu.',
        'quote_invalid' => 'Kalkulace neodpovídá zadání. Nechte si prosím spočítat cenu znovu.',
        'change_failed' => 'Změna výkonnostní instance se nezdařila. Zkuste to prosím znovu.',
    ];

    public function __construct(
        private readonly LicenseService $license,
        private readonly ManagedModeGuard $managed,
    ) {}

    public static function message(string $error): string
    {
        return self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['change_failed'];
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
        $quoteToken = trim((string) ($body['quote_token'] ?? ''));
        if ($quoteToken === '') {
            return Json::error($response, 'quote_required', self::message('quote_required'), 400);
        }

        $result = $this->license->changePerformance($body['enabled'], $quoteToken);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'change_failed');
            // ⚠️ `pay_url` musí projít až na obrazovku: po neprojité kartě je to
            // jediná cesta, jak doplatek zaplatit.
            $extra = isset($result['pay_url']) ? ['pay_url' => (string) $result['pay_url']] : [];

            return Json::error(
                $response,
                $error,
                self::message($error),
                $error === 'server_unreachable' ? 503 : 422,
                $extra,
            );
        }

        $state = $result['state_local'] ?? $this->license->current();

        return Json::ok($response, [
            'enabled' => (bool) ($result['enabled'] ?? $body['enabled']),
            'pending_disable' => (bool) ($result['pending_disable'] ?? false),
            'amount_charged' => $result['amount_charged'] ?? null,
            'scheduled' => (bool) ($result['scheduled'] ?? false),
            'effective_at' => $result['effective_at'] ?? null,
            'pending' => (bool) ($result['pending'] ?? false),
            'order_id' => isset($result['order_id']) ? (string) $result['order_id'] : null,
            'state' => $state->toArray($this->license->buyUrl()),
        ]);
    }
}
