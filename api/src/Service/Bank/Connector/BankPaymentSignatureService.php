<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;

/**
 * Podpis dávky přesměrováním do banky a zjišťování výsledku dotazem (banka nevolá zpět).
 *
 * Návrat z banky jde na společný callback ({@see OAuthClientFlow::CALLBACK_PATH}) s
 * jednorázovým state; rozpracovaný podpis se eviduje jako přihlášení domény bank
 * s poskytovatelem {@see OAUTH_PROVIDER} a subjektem = id odeslání. Výsledek podpisu
 * se zjišťuje při návratu, na vyžádání z UI a cronem; nic z toho banku znovu nežádá
 * o založení plateb.
 */
final class BankPaymentSignatureService
{
    public const OAUTH_PROVIDER = 'csas_sign';
    private const SIGN_TTL_SECONDS = 1800;
    /** Nejkratší odstup dvou dotazů na stav téže dávky (opakované klikání v UI). */
    private const MIN_REFRESH_SECONDS = 5;
    /** Dotazy při probíhajícím podpisu: první hodinu po minutě, potom šetrněji. */
    private const POLL_SIGNING_SECONDS = 60;
    private const POLL_IDLE_SECONDS = 15 * 60;
    private const POLL_SIGNED_SECONDS = 30 * 60;
    private const POLL_RETRY_SECONDS = 30 * 60;
    /** Založená a nepodepsaná platba v bance propadá po 30 dnech. */
    private const UNSIGNED_LIFETIME_DAYS = 30;
    /** Stav platby banka drží 34 dní; potom se už nedotazujeme. */
    private const STATUS_RETENTION_DAYS = 34;

    public function __construct(
        private readonly BankPaymentOrderSubmissionRepository $submissions,
        private readonly BankPaymentSubmissionService $engine,
        private readonly BankConnectorCallGuard $calls,
        private readonly OAuthClientFlow $flow,
    ) {}

    /**
     * Vydá adresu podpisu dávky v bance. Adresa má krátkou platnost a neukládá se;
     * nové vydání podepisuje stejnou dávku (stejné signId a hash), nikdy nové platby.
     *
     * @return array{signing_url:string,submission:array<string,mixed>}
     */
    public function startSignature(int $supplierId, int $orderId, string $source, int $userId): array
    {
        if ($userId < 1) throw new BankConnectorOperationException('forbidden');
        $state = $this->state($supplierId, $orderId, $source);
        if ($state['status'] !== 'awaiting_signature' || !is_string($state['provider_sign_hash'])
            || !is_string($state['provider_reference']) || $state['provider_reference'] === '') {
            throw new BankConnectorOperationException('payment_signature_unavailable');
        }
        if (self::olderThan($state['age_seconds'], self::UNSIGNED_LIFETIME_DAYS * 86400)) {
            $this->submissions->updateSigning($supplierId, (int) $state['id'], ['awaiting_signature'],
                ['status' => 'expired', 'error_code' => 'payment_signature_expired', 'next_poll_seconds' => null, 'polled' => true]);
            throw new BankConnectorOperationException('payment_signature_expired');
        }
        $access = $this->engine->signingAccess($supplierId, (int) $state['connection_id']);
        return $this->calls->withConnectionLock($supplierId, (int) $access['connection']['currency_id'],
            function () use ($supplierId, $orderId, $source, $userId, $state, $access): array {
                try {
                    $authorization = $this->flow->authorize(false);
                    $callback = $this->flow->callbackUrl() . '?' . http_build_query(['state' => $authorization->state]);
                } catch (OAuthException) {
                    throw new BankConnectorOperationException('app_url_invalid');
                }
                try {
                    $signature = $access['connector']->startSignature($access['token'], (string) $state['provider_reference'],
                        (string) $state['provider_sign_hash'], $callback);
                } catch (BankConnectorException $e) {
                    throw new BankConnectorOperationException($e->errorCode === BankConnectorException::RATE_LIMITED
                        ? 'bank_rate_limited' : 'payment_signature_failed');
                }
                try {
                    $this->flow->store($authorization, $supplierId, $userId, BankOAuthCallbackHandler::DOMAIN, self::OAUTH_PROVIDER,
                        (int) $state['id'], OAuthClientFlow::FLOW_REDIRECT, ['source' => $source, 'order_id' => $orderId],
                        self::SIGN_TTL_SECONDS);
                } catch (OAuthException) {
                    throw new BankConnectorOperationException('encryption_key_unavailable');
                }
                $this->submissions->updateSigning($supplierId, (int) $state['id'], ['awaiting_signature'], [
                    'provider_poll_id' => $signature['poll_id'], 'signature_state' => 'AUTHORIZATION_NOT_STARTED',
                    'error_code' => null, 'signature_requested' => true, 'next_poll_seconds' => self::POLL_SIGNING_SECONDS,
                ]);
                return ['signing_url' => $signature['signing_url'], 'submission' => $this->publicState($supplierId, $orderId, $source)];
            });
    }

    /**
     * Zjistí stav podpisu a plateb u banky. Častější dotaz než jednou za pár sekund se
     * nepošle a vrátí se uložený stav.
     *
     * @return array<string,mixed> veřejný stav odeslání
     */
    public function refresh(int $supplierId, int $orderId, string $source): array
    {
        $state = $this->state($supplierId, $orderId, $source);
        if (in_array($state['status'], ['awaiting_signature', 'signed'], true)
            && !self::recent($state['polled_seconds_ago'], self::MIN_REFRESH_SECONDS)) {
            $this->poll($supplierId, (int) $state['id']);
        }
        return $this->publicState($supplierId, $orderId, $source);
    }

    /** Návrat z banky po podpisu. Vrací adresu, kam přesměrovat prohlížeč. */
    public function completeCallback(OAuthCallback $callback): string
    {
        $supplierId = $callback->supplierId();
        $session = $this->flow->claim($callback->state, $supplierId, $callback->userId(), BankOAuthCallbackHandler::DOMAIN, self::OAUTH_PROVIDER);
        if ($session === null) return self::location('purchase_invoice', null, 'error');
        $source = $session->secret['source'] ?? null;
        $orderId = $session->secret['order_id'] ?? null;
        if (!in_array($source, ['purchase_invoice', 'payroll'], true) || !is_int($orderId) || $orderId < 1) {
            $this->flow->finish($session, false, 'invalid_state');
            return self::location('purchase_invoice', null, 'error');
        }
        if (!RequestAuthorization::allows($callback->request, $source === 'payroll' ? 'payroll.payments' : 'purchase_invoices.payment_orders', AccessLevel::WRITE)) {
            $this->flow->finish($session, false, 'forbidden');
            return self::location($source, $orderId, 'error');
        }
        $this->flow->finish($session, true);
        try {
            $state = $this->state($supplierId, $orderId, $source);
            if ((int) $state['id'] !== $session->subjectId) return self::location($source, $orderId, 'error');
            $this->poll($supplierId, (int) $state['id']);
        } catch (BankConnectorOperationException) {
            // Stav se dozví další dotaz (UI nebo cron); návrat do aplikace tím nesmí selhat.
        }
        return self::location($source, $orderId, 'returned');
    }

    /**
     * Cron: dotazy na dávky, kterým nastal čas. Chyba jedné dávky nezastaví ostatní a
     * další pokus se odloží.
     *
     * @return array{polled:int,errors:int}
     */
    public function pollDue(array $providers, int $limit = 20): array
    {
        $polled = 0;
        $errors = 0;
        foreach ($providers as $provider) {
            foreach ($this->submissions->dueForPolling($provider, $limit) as $row) {
                try {
                    $this->poll($row['supplier_id'], $row['id']);
                    $polled++;
                } catch (\Throwable) {
                    $errors++;
                    try {
                        $this->submissions->updateSigning($row['supplier_id'], $row['id'], ['awaiting_signature', 'signed'],
                            ['next_poll_seconds' => self::POLL_RETRY_SECONDS, 'polled' => true]);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        return ['polled' => $polled, 'errors' => $errors];
    }

    private function poll(int $supplierId, int $submissionId): void
    {
        $state = $this->submissions->signingState($supplierId, $submissionId);
        if ($state === null || !in_array($state['status'], ['awaiting_signature', 'signed'], true)) return;
        if (self::olderThan($state['age_seconds'], self::STATUS_RETENTION_DAYS * 86400)) {
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature', 'signed'], $state['status'] === 'awaiting_signature'
                ? ['status' => 'expired', 'error_code' => 'payment_signature_expired', 'next_poll_seconds' => null, 'polled' => true]
                : ['next_poll_seconds' => null, 'polled' => true]);
            return;
        }
        $access = $this->engine->signingAccess($supplierId, (int) $state['connection_id']);
        try {
            $this->calls->withConnectionLock($supplierId, (int) $access['connection']['currency_id'],
                function () use ($supplierId, $submissionId, $access): void {
                    $state = $this->submissions->signingState($supplierId, $submissionId);
                    if ($state === null) return;
                    if ($state['status'] === 'awaiting_signature') {
                        $this->pollSignature($supplierId, $submissionId, $state, $access);
                        $state = $this->submissions->signingState($supplierId, $submissionId);
                    }
                    if ($state !== null && $state['status'] === 'signed') {
                        $this->pollPayments($supplierId, $submissionId, $access);
                    }
                });
        } catch (BankConnectorException $e) {
            // Dotaz na stav nic nezakládá; chyba banky jen odloží další dotaz.
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature', 'signed'],
                ['next_poll_seconds' => self::POLL_RETRY_SECONDS, 'polled' => true]);
            throw new BankConnectorOperationException($e->errorCode === BankConnectorException::RATE_LIMITED
                ? 'bank_rate_limited' : 'payment_status_unavailable');
        }
    }

    /** @param array{connector:SignableBankPaymentConnector,token:string} $access */
    private function pollSignature(int $supplierId, int $submissionId, array $state, array $access): void
    {
        if (self::olderThan($state['age_seconds'], self::UNSIGNED_LIFETIME_DAYS * 86400)) {
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'],
                ['status' => 'expired', 'error_code' => 'payment_signature_expired', 'next_poll_seconds' => null, 'polled' => true]);
            return;
        }
        $pollId = $state['provider_poll_id'];
        if (!is_string($pollId) || $pollId === '') {
            // Podpis mimo aplikaci (přímo v bankovnictví) se pozná na stavu první platby.
            $this->detectSignatureFromPayments($supplierId, $submissionId, $access);
            return;
        }
        $result = $access['connector']->signatureState($access['token'], $pollId);
        $requestedRecently = self::recent($state['signature_requested_seconds_ago'], 3600);
        $changes = match ($result['state']) {
            'DONE' => ['status' => 'signed', 'signature_state' => 'DONE', 'error_code' => null, 'next_poll_seconds' => self::POLL_SIGNING_SECONDS],
            'REJECTED', 'ORDER_PROCESSING_FAILED' => ['status' => 'rejected', 'signature_state' => $result['state'],
                'error_code' => 'payment_signature_rejected', 'next_poll_seconds' => null],
            'EXPIRED' => ['provider_poll_id' => null, 'signature_state' => 'EXPIRED', 'error_code' => 'payment_signature_expired',
                'next_poll_seconds' => self::POLL_IDLE_SECONDS],
            default => ['signature_state' => $result['state'],
                'next_poll_seconds' => $requestedRecently ? max(self::POLL_SIGNING_SECONDS, intdiv($result['poll_interval_ms'], 1000)) : self::POLL_IDLE_SECONDS],
        };
        $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'], $changes + ['polled' => true]);
    }

    /** @param array{connector:SignableBankPaymentConnector,token:string} $access */
    private function detectSignatureFromPayments(int $supplierId, int $submissionId, array $access): void
    {
        $first = null;
        foreach ($this->submissions->items($supplierId, $submissionId) as $item) {
            if ($item['status'] === 'created' && is_string($item['provider_sign_id'])) {
                $first = $item;
                break;
            }
        }
        if ($first === null) {
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'], ['next_poll_seconds' => null, 'polled' => true]);
            return;
        }
        $payment = $access['connector']->paymentState($access['token'], (string) $first['provider_sign_id']);
        $this->submissions->recordItemStatus($supplierId, $submissionId, $first['line_no'], $payment['instruction_status'], $payment['status_change_info']);
        $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'], $payment['instruction_status'] === 'ACTC'
            ? ['next_poll_seconds' => self::POLL_IDLE_SECONDS, 'polled' => true]
            : ['status' => 'signed', 'signature_state' => 'DONE', 'error_code' => null, 'next_poll_seconds' => self::POLL_SIGNING_SECONDS, 'polled' => true]);
    }

    /**
     * Stav jednotlivých plateb podepsané dávky. Dotazuje se jen platba, která ještě
     * nemá konečný stav (ACSC odepsáno, RJCT odmítnuto).
     *
     * @param array{connector:SignableBankPaymentConnector,token:string} $access
     */
    private function pollPayments(int $supplierId, int $submissionId, array $access): void
    {
        $items = array_values(array_filter($this->submissions->items($supplierId, $submissionId),
            static fn (array $item): bool => $item['status'] === 'created'));
        foreach ($items as $index => $item) {
            if (in_array($item['instruction_status'], ['ACSC', 'RJCT'], true) || !is_string($item['provider_sign_id'])) continue;
            $payment = $access['connector']->paymentState($access['token'], $item['provider_sign_id']);
            $this->submissions->recordItemStatus($supplierId, $submissionId, $item['line_no'], $payment['instruction_status'], $payment['status_change_info']);
            $items[$index]['instruction_status'] = $payment['instruction_status'];
        }
        $statuses = array_column($items, 'instruction_status');
        $settled = count(array_filter($statuses, static fn (?string $status): bool => $status === 'ACSC'));
        $rejected = count(array_filter($statuses, static fn (?string $status): bool => $status === 'RJCT'));
        $changes = ['accepted_count' => $settled, 'rejected_count' => $rejected, 'polled' => true];
        if ($items !== [] && $settled + $rejected === count($items)) {
            $changes += match (true) {
                $rejected === 0 => ['status' => 'completed', 'error_code' => null],
                $settled === 0 => ['status' => 'rejected', 'error_code' => 'payment_rejected'],
                default => ['status' => 'partially_rejected', 'error_code' => 'payment_partially_rejected'],
            };
            $changes['next_poll_seconds'] = null;
        } else {
            $changes['next_poll_seconds'] = self::POLL_SIGNED_SECONDS;
        }
        $this->submissions->updateSigning($supplierId, $submissionId, ['signed'], $changes);
    }

    /** @return array<string,mixed> */
    private function state(int $supplierId, int $orderId, string $source): array
    {
        $public = $this->submissions->find($supplierId, $orderId, $source);
        if ($public === null) throw new BankConnectorOperationException('payment_order_not_found');
        $state = $this->submissions->signingState($supplierId, (int) $public['id']);
        if ($state === null) throw new BankConnectorOperationException('payment_order_not_found');
        return $state;
    }

    /** @return array<string,mixed> */
    private function publicState(int $supplierId, int $orderId, string $source): array
    {
        return $this->submissions->find($supplierId, $orderId, $source)
            ?? throw new BankConnectorOperationException('submission_state_unavailable');
    }

    private static function location(string $source, ?int $orderId, string $result): string
    {
        return $source === 'payroll'
            ? '/payroll/payments?' . http_build_query(array_filter(['bank_batch' => $orderId, 'bank_sign' => $result]))
            : '/purchase-invoices/payment-orders?' . http_build_query(array_filter(['bank_order' => $orderId, 'bank_sign' => $result]));
    }

    /** Stáří počítá databáze (TIMESTAMPDIFF), aby nezáleželo na časové zóně PHP. */
    private static function olderThan(?int $ageSeconds, int $seconds): bool
    {
        return $ageSeconds !== null && $ageSeconds > $seconds;
    }

    private static function recent(?int $ageSeconds, int $seconds): bool
    {
        return $ageSeconds !== null && $ageSeconds >= 0 && $ageSeconds < $seconds;
    }
}
