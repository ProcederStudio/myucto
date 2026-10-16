<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\License;

use DateTimeImmutable;
use MyInvoice\Action\License\LicenseStatusAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Service\License\BillingSnapshot;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\System\InstanceEntitlement;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\PerformanceRecommendation;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageQuotaStatus;
use MyInvoice\Service\System\StorageUsageMeter;
use MyInvoice\Service\System\StorageUsageSnapshot;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET /api/license/status → `instance.billing` a `instance.links` (H-31).
 *
 * Červená linka nad aplikací i stránka Hostingu se ptají „je zaplaceno?".
 * Instalace o platbě přitom skoro nic neví — má jen stav licence z tokenu
 * a poslední stav předplatného, který jí licenční server poslal. Tenhle test
 * drží obojí u sebe:
 *
 *  1. `unpaid` stojí VÝHRADNĚ na těchhle dvou vstupech. Kdyby se dopočítával
 *     odjinud (třeba z toho, že se instalace tři dny nedovolala), rozsvítí se
 *     linka u zákazníků, kteří zaplaceno mají.
 *  2. Overage NENÍ dluh — uživatelů je víc, než licence pokrývá, ale zaplaceno
 *     je. Červená linka by tam byla lež.
 *  3. Adresy jsou z konfigurace, ne zadrátované — testovací instalace nesmí
 *     posílat zákazníka na ostrý web; nenastavená adresa je `null`, ne půlka
 *     odkazu.
 *  4. Self-hosted odpověď nesmí nový blok dostat vůbec.
 *
 * Bez databáze — stejný postup jako {@see LicenseStatusManagedInstanceTest}:
 * `Connection` se konstruuje jen kvůli typu (spojení navazuje až `pdo()`),
 * měření podstrkuje testovací potomek `StorageQuotaPolicy`.
 */
final class LicenseStatusInstanceBillingTest extends TestCase
{
    private const GB = 1024 * 1024 * 1024;

    // ─────────────────────────────────────────────────────────────────────────
    //  Neuhrazeno
    // ─────────────────────────────────────────────────────────────────────────

    public function testActiveLicenceWithPaidSubscriptionIsNotUnpaid(): void
    {
        $billing = $this->billing($this->state(LicenseState::ACTIVE, ['state' => 'active', 'auto_renew' => true]));

        self::assertFalse($billing['unpaid']);
        self::assertSame('active', $billing['subscription_state']);
        self::assertSame('active', $billing['license_state']);
    }

    /** Degradovaná licence = zavřené komerční moduly; ve spravovaném provozu je to dluh. */
    public function testDegradedLicenceIsUnpaid(): void
    {
        self::assertTrue($this->billing($this->state(LicenseState::DEGRADED))['unpaid']);
    }

    public function testExpiredTrialIsUnpaid(): void
    {
        self::assertTrue($this->billing($this->state(LicenseState::TRIAL_EXPIRED))['unpaid']);
    }

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: implementace, která se dívá jen
     * na stav licence, tenhle případ zahodí. Licenční server přitom hlásí
     * nezaplacenou platbu (`past_due`) DŘÍV, než token propadne — je to jediné
     * pole, které o platbě mluví přímo, a je to nejužitečnější okamžik, kdy
     * zákazníkovi něco říct.
     */
    public function testPastDueSubscriptionIsUnpaidEvenWhileTheLicenceStillRuns(): void
    {
        $billing = $this->billing($this->state(LicenseState::ACTIVE, ['state' => 'past_due']));

        self::assertTrue($billing['unpaid']);
        self::assertSame('active', $billing['license_state'], 'Licence pořád běží — stav se nesmí přepsat.');
        self::assertSame('past_due', $billing['subscription_state']);
    }

    public function testRunningTrialIsNotUnpaid(): void
    {
        self::assertFalse($this->billing($this->state(LicenseState::TRIAL))['unpaid']);
    }

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: „nemá aktivní stav" je svůdně
     * krátká podmínka. Overage ji splňuje, přitom je zaplaceno — jen se
     * přečerpal počet uživatelů. Blokující červená linka tam nepatří.
     */
    public function testOverageIsNotUnpaid(): void
    {
        self::assertFalse($this->billing($this->state(LicenseState::OVERAGE))['unpaid']);
    }

    /** Bez data poslední kontroly nejde „neuhrazeno" číst — pole musí jít ven. */
    public function testBillingCarriesWhenWeLastAskedTheServer(): void
    {
        $billing = $this->billing($this->state(LicenseState::DEGRADED));

        self::assertSame('2026-08-21T09:00:00+00:00', $billing['last_check_at']);
        self::assertTrue($billing['last_check_ok']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Odkazy
    // ─────────────────────────────────────────────────────────────────────────

    public function testLinksComeFromConfigurationNotFromCode(): void
    {
        $links = $this->instance(
            $this->state(LicenseState::ACTIVE),
            extra: ['license' => ['server_url' => 'https://test.example/']],
        )['links'];

        self::assertSame('https://test.example/obchodni-podminky', $links['terms']);
        self::assertSame('https://test.example/ochrana-osobnich-udaju', $links['privacy']);
        self::assertSame('https://test.example/predplatne', $links['expand_storage']);
        self::assertSame('https://test.example/support', $links['support']);
    }

    /** Nenakonfigurovaná adresa → null, ne poloviční odkaz do prázdna. */
    public function testMissingServerUrlYieldsNullLinks(): void
    {
        $links = $this->instance($this->state(LicenseState::ACTIVE))['links'];

        foreach (['terms', 'privacy', 'expand_storage', 'support', 'subscription'] as $key) {
            self::assertNull($links[$key], "Odkaz {$key} se nesmí vymýšlet.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Self-hosted regrese
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: nový blok se nejsnáz přidá do
     * payloadu vždycky. Self-hosted odpověď je ale hlavní cesta k nákupu
     * licence a musí zůstat beze změny — a degradovaná licence tam znamená
     * „běžím na MIT jádru", ne „dluží".
     */
    public function testSelfHostedResponseHasNoBillingBlockAtAll(): void
    {
        $payload = $this->invoke($this->state(LicenseState::DEGRADED), managed: false);

        self::assertArrayNotHasKey('instance', $payload);
        self::assertStringNotContainsString('unpaid', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Pomocné
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function billing(LicenseState $state, array $extra = []): array
    {
        return $this->instance($state, $extra)['billing'];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function instance(LicenseState $state, array $extra = []): array
    {
        $payload = $this->invoke($state, managed: true, extra: $extra);

        self::assertArrayHasKey('instance', $payload, 'Spravovaná instalace blok instance mít musí.');

        return $payload['instance'];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function invoke(LicenseState $state, bool $managed, array $extra = []): array
    {
        $config = new Config(array_replace_recursive([
            'app'      => ['managed' => $managed],
            'instance' => ['quota_gb' => 10],
        ], $extra));

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/license/status')
            ->withAttribute(LicenseMiddleware::ATTR_STATE, $state)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin', 'email' => 'admin@example.test']);

        $response = $this->action($config)($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function action(Config $config): LicenseStatusAction
    {
        $db = new Connection($config);

        return new LicenseStatusAction(
            new LicenseService($db, $config, new LicenseTokenVerifier(), new LicenseClient($config)),
            $db,
            new ManagedModeGuard($config),
            $this->policy($config),
            $config,
            new InstanceEntitlement($db, $config),
            new BillingSnapshot($config),
            new PerformanceRecommendation($db),
        );
    }

    /** Kvótová politika s podstrčeným měřením — bez databáze. */
    private function policy(Config $config): StorageQuotaPolicy
    {
        $snapshot = new StorageUsageSnapshot(
            measuredAt:    new DateTimeImmutable('2026-08-21 10:00:00'),
            databaseBytes: 1 * self::GB,
            filesBytes:    1 * self::GB,
            usageBytes:    2 * self::GB,
        );

        return new class (
            $config,
            new ManagedModeGuard($config),
            new StorageUsageMeter(new Connection($config), $config),
            new InstanceEntitlement(new Connection($config), $config),
            $snapshot,
        ) extends StorageQuotaPolicy {
            public function __construct(
                Config $config,
                ManagedModeGuard $managed,
                StorageUsageMeter $meter,
                InstanceEntitlement $entitlement,
                private readonly StorageUsageSnapshot $snapshot,
            ) {
                parent::__construct($config, $managed, $meter, $entitlement);
            }

            public function evaluate(): StorageQuotaStatus
            {
                return $this->evaluateSnapshot($this->snapshot);
            }
        };
    }

    /** @param array<string,mixed>|null $subscription */
    private function state(string $kind, ?array $subscription = null): LicenseState
    {
        return new LicenseState(
            $kind,
            'inst-test',
            'multi10',
            10,
            5,
            3,
            2,
            1_800_000_000,
            null,
            null,
            'MYU-TEST-0000-0001',
            '2026-08-21T09:00:00+00:00',
            true,
            false,
            $subscription,
        );
    }
}
