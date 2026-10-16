<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use DateTimeImmutable;
use MyInvoice\Action\License\LicenseStatusAction;
use MyInvoice\Action\License\PerformanceChangeAction;
use MyInvoice\Action\License\PerformanceQuoteAction;
use MyInvoice\Action\License\UpgradeLicenseAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\License\BillingSnapshot;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseNetworkException;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\System\InstanceEntitlement;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\PerformanceRecommendation;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageQuotaStatus;
use MyInvoice\Service\System\StorageUsageMeter;
use MyInvoice\Service\System\StorageUsageSnapshot;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Výkonnostní instance — PerformanceQuoteAction / PerformanceChangeAction proti
 * mockovanému licenčnímu serveru a blok `instance.performance` ve stavu licence.
 */
#[Group('integration')]
#[AllowMockObjectsWithoutExpectations]
final class PerformanceInstanceActionTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private Config $config;
    private LicenseClient&MockObject $client;
    private LicenseService $service;
    private PerformanceRecommendation $recommendation;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->ping() || !$this->db->hasTable('license') || !$this->db->hasTable('app_meta')) {
            $this->markTestSkipped('Migrace license/app_meta neproběhly / DB nedostupná.');
        }

        $this->config = new Config([
            'app' => ['managed' => true],
            'license' => ['public_key' => $this->licensePublicKeyBase64()],
        ]);
        $this->client = $this->createMock(LicenseClient::class);
        $this->service = new LicenseService($this->db, $this->config, new LicenseTokenVerifier(), $this->client);
        $this->recommendation = new PerformanceRecommendation($this->db);

        $pdo = $this->db->pdo();
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare('DELETE FROM app_meta WHERE k = ?')->execute([PerformanceRecommendation::KEY]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── kalkulace ────────────────────────────────────────────────────────────

    public function testQuoteForwardsContractAndAttachesUsage(): void
    {
        $this->seedActivated();
        $this->seedUsage(120000, 10);
        $this->client->expects($this->once())
            ->method('performanceQuote')
            ->with('MYU-TEST-0001-AAAA', $this->logicalNot($this->equalTo('')), true)
            ->willReturn([
                'ok' => true, 'error' => null, 'current_enabled' => false, 'new_enabled' => true,
                'pending_disable' => false, 'cancel_scheduled' => false, 'amount' => 175.0,
                'recurring_delta' => 350.0, 'monthly_price' => 350.0, 'currency' => 'CZK',
                'period_end' => '2026-11-01 00:00:00', 'scheduled' => false, 'effective_at' => null,
                'ram_mb' => 1024, 'php_processes' => 8, 'base_ram_mb' => 512, 'base_php_processes' => 4,
                'quote_token' => 'perf-quote', 'quote_expires_at' => '2026-10-16 12:10:00',
            ]);

        $resp = $this->quoteAction()($this->adminRequest(['enabled' => true]), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertTrue($body['new_enabled']);
        self::assertEqualsWithDelta(175.0, $body['amount'], 0.001);
        self::assertEqualsWithDelta(350.0, $body['recurring_delta'], 0.001);
        self::assertSame('perf-quote', $body['quote_token']);
        self::assertSame('2026-10-16 12:10:00', $body['expires_at']);
        self::assertSame(1024, $body['ram_mb']);
        self::assertSame(8, $body['php_processes']);
        self::assertSame(120000, $body['usage']['invoices']);
        self::assertTrue($body['usage']['recommended']);
        self::assertSame(['invoices' => 100000, 'journal_entries' => 500000], $body['usage']['thresholds']);
    }

    public function testQuoteRejectsNonBooleanEnabled(): void
    {
        $this->seedActivated();
        $this->client->expects($this->never())->method('performanceQuote');

        $resp = $this->quoteAction()($this->adminRequest(['enabled' => 'yes']), new Psr7Response());

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->body($resp)['error']['code']);
    }

    public function testQuoteMapsServerErrorToCzechMessage(): void
    {
        $this->seedActivated();
        $this->client->method('performanceQuote')->willReturn(['ok' => false, 'error' => 'not_hosted']);

        $resp = $this->quoteAction()($this->adminRequest(['enabled' => true]), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        $error = $this->body($resp)['error'];
        self::assertSame('not_hosted', $error['code']);
        self::assertSame(PerformanceQuoteAction::ERROR_MESSAGES['not_hosted'], $error['message']);
    }

    public function testQuoteNetworkErrorIsServiceUnavailable(): void
    {
        $this->seedActivated();
        $this->client->method('performanceQuote')->willThrowException(new LicenseNetworkException('timeout'));

        $resp = $this->quoteAction()($this->adminRequest(['enabled' => true]), new Psr7Response());

        self::assertSame(503, $resp->getStatusCode());
        self::assertSame('server_unreachable', $this->body($resp)['error']['code']);
    }

    public function testSelfHostedIsRejectedBeforeServerCall(): void
    {
        $this->seedActivated();
        $this->client->expects($this->never())->method('performanceQuote');
        $this->client->expects($this->never())->method('performanceChange');
        $selfHosted = new ManagedModeGuard(new Config(['app' => ['managed' => false]]));

        $quote = (new PerformanceQuoteAction($this->service, $selfHosted, $this->recommendation))(
            $this->adminRequest(['enabled' => true]),
            new Psr7Response(),
        );
        $change = (new PerformanceChangeAction($this->service, $selfHosted))(
            $this->adminRequest(['enabled' => true, 'quote_token' => 'perf-quote']),
            new Psr7Response(),
        );

        self::assertSame(409, $quote->getStatusCode());
        self::assertSame(409, $change->getStatusCode());
        self::assertSame('not_managed', $this->body($change)['error']['code']);
    }

    public function testNonAdminIsForbidden(): void
    {
        $this->client->expects($this->never())->method('performanceQuote');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/performance/quote')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 2, 'role' => 'accountant'])
            ->withParsedBody(['enabled' => true]);

        self::assertSame(403, $this->quoteAction()($request, new Psr7Response())->getStatusCode());
    }

    // ── změna ────────────────────────────────────────────────────────────────

    public function testEnableChargesAndRefreshesLicense(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('performanceChange')
            ->with('MYU-TEST-0001-AAAA', $this->logicalNot($this->equalTo('')), true, 'perf-quote')
            ->willReturn([
                'ok' => true, 'enabled' => true, 'pending_disable' => false, 'amount_charged' => 175.0,
                'scheduled' => false, 'effective_at' => null, 'pending' => false, 'order_id' => 42,
            ]);
        $this->client->expects($this->once())
            ->method('renew')
            ->willReturn(['ok' => true, 'token' => $this->token()]);

        $resp = $this->changeAction()(
            $this->adminRequest(['enabled' => true, 'quote_token' => 'perf-quote']),
            new Psr7Response(),
        );

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertTrue($body['enabled']);
        self::assertFalse($body['pending']);
        self::assertSame('42', $body['order_id']);
        self::assertEqualsWithDelta(175.0, $body['amount_charged'], 0.001);
        self::assertSame('active', $body['state']['state']);
    }

    /**
     * Naplánované vypnutí se musí promítnout hned: `pending_disable` přichází
     * až s obnovou licence, takže ji změna vynutí i u `scheduled`.
     */
    public function testScheduledDisableStillRefreshesLicense(): void
    {
        $this->seedActivated();
        $this->client->method('performanceChange')->willReturn([
            'ok' => true, 'enabled' => true, 'pending_disable' => true, 'amount_charged' => 0.0,
            'scheduled' => true, 'effective_at' => '2026-11-01 00:00:00', 'pending' => false, 'order_id' => null,
        ]);
        $this->client->expects($this->once())
            ->method('renew')
            ->willReturn(['ok' => true, 'token' => $this->token()]);

        $resp = $this->changeAction()(
            $this->adminRequest(['enabled' => false, 'quote_token' => 'perf-quote']),
            new Psr7Response(),
        );

        $body = $this->body($resp);
        self::assertSame(200, $resp->getStatusCode());
        self::assertTrue($body['scheduled']);
        self::assertTrue($body['pending_disable']);
        self::assertSame('2026-11-01 00:00:00', $body['effective_at']);
    }

    public function testChargePendingIsNotAnErrorAndSkipsRenew(): void
    {
        $this->seedActivated();
        $this->client->method('performanceChange')
            ->willReturn(['ok' => false, 'error' => 'charge_pending', 'order_id' => 77]);
        $this->client->expects($this->never())->method('renew');

        $resp = $this->changeAction()(
            $this->adminRequest(['enabled' => true, 'quote_token' => 'perf-quote']),
            new Psr7Response(),
        );

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertTrue($body['pending']);
        self::assertSame('77', $body['order_id']);
    }

    public function testCardPaymentRequiredForwardsPayUrl(): void
    {
        $this->seedActivated();
        $this->client->method('performanceChange')->willReturn([
            'ok' => false, 'error' => 'card_payment_required', 'order_id' => 91,
            'pay_url' => 'https://example.test/platba-navrat?t=perf',
        ]);
        $this->client->expects($this->never())->method('renew');

        $resp = $this->changeAction()(
            $this->adminRequest(['enabled' => true, 'quote_token' => 'perf-quote']),
            new Psr7Response(),
        );

        self::assertSame(422, $resp->getStatusCode());
        $error = $this->body($resp)['error'];
        self::assertSame('card_payment_required', $error['code']);
        self::assertSame('https://example.test/platba-navrat?t=perf', $error['pay_url']);
        self::assertSame(UpgradeLicenseAction::CARD_PAYMENT_REQUIRED_MESSAGE, $error['message']);
    }

    public function testNetworkErrorOnChangeIsResultUnknown(): void
    {
        $this->seedActivated();
        $this->client->method('performanceChange')->willThrowException(new LicenseNetworkException('timeout'));

        $resp = $this->changeAction()(
            $this->adminRequest(['enabled' => true, 'quote_token' => 'perf-quote']),
            new Psr7Response(),
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('result_unknown', $this->body($resp)['error']['code']);
    }

    public function testChangeRequiresQuoteToken(): void
    {
        $this->seedActivated();
        $this->client->expects($this->never())->method('performanceChange');

        $resp = $this->changeAction()($this->adminRequest(['enabled' => true]), new Psr7Response());

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('quote_required', $this->body($resp)['error']['code']);
    }

    // ── objem dat a doporučení ───────────────────────────────────────────────

    public function testRecommendationThresholdsAreStrictlyAbove(): void
    {
        self::assertFalse(PerformanceRecommendation::isRecommended(100000, 500000));
        self::assertTrue(PerformanceRecommendation::isRecommended(100001, 0));
        self::assertTrue(PerformanceRecommendation::isRecommended(0, 500001));
    }

    public function testUsageIsMeasuredAndCachedForAnHour(): void
    {
        $now = new DateTimeImmutable('2026-10-16 12:00:00');
        $measured = $this->recommendation->usage($now);

        self::assertSame((int) $this->db->pdo()->query('SELECT COUNT(*) FROM invoices')->fetchColumn(), $measured['invoices']);
        self::assertSame(
            (int) $this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
            $measured['journal_entries'],
        );

        $this->seedUsage(1, 600000, $now);
        $cached = $this->recommendation->usage($now->modify('+59 minutes'));
        self::assertSame(600000, $cached['journal_entries']);
        self::assertTrue($cached['recommended']);

        $expired = $this->recommendation->usage($now->modify('+61 minutes'));
        self::assertSame($measured['journal_entries'], $expired['journal_entries']);
    }

    // ── stav licence ─────────────────────────────────────────────────────────

    public function testStatusExposesPerformanceBlockWithUsage(): void
    {
        $this->seedActivated();
        $this->seedUsage(10, 700000);
        $this->db->pdo()->prepare('UPDATE license SET instance_info = ? WHERE id = 1')->execute([json_encode([
            'quota_gb' => 2,
            'performance' => [
                'enabled' => true, 'pending_disable' => false, 'vendor_state' => 'enable_requested',
                'price_month_czk' => 350.0, 'ram_mb' => 1024, 'php_processes' => 8,
                'base_ram_mb' => 512, 'base_php_processes' => 4,
            ],
        ], JSON_THROW_ON_ERROR)]);

        $performance = $this->statusPayload()['instance']['performance'];

        self::assertTrue($performance['enabled']);
        self::assertSame('enable_requested', $performance['vendor_state']);
        self::assertEqualsWithDelta(350.0, $performance['price_month_czk'], 0.001);
        self::assertSame(1024, $performance['ram_mb']);
        self::assertSame(4, $performance['base_php_processes']);
        self::assertTrue($performance['usage']['recommended']);
    }

    /** Starší licenční server blok neposílá — sekce se pak na obrazovce nekreslí. */
    public function testStatusOmitsPerformanceForOlderServer(): void
    {
        $this->seedActivated();
        $this->db->pdo()->prepare('UPDATE license SET instance_info = ? WHERE id = 1')
            ->execute([json_encode(['quota_gb' => 2], JSON_THROW_ON_ERROR)]);

        self::assertArrayNotHasKey('performance', $this->statusPayload()['instance']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function quoteAction(): PerformanceQuoteAction
    {
        return new PerformanceQuoteAction($this->service, new ManagedModeGuard($this->config), $this->recommendation);
    }

    private function changeAction(): PerformanceChangeAction
    {
        return new PerformanceChangeAction($this->service, new ManagedModeGuard($this->config));
    }

    /** @return array<string,mixed> */
    private function statusPayload(): array
    {
        $managed = new ManagedModeGuard($this->config);
        $entitlement = new InstanceEntitlement($this->db, $this->config);
        $snapshot = StorageUsageSnapshot::unmeasured();
        $policy = new class (
            $this->config,
            $managed,
            new StorageUsageMeter($this->db, $this->config),
            $entitlement,
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
        $action = new LicenseStatusAction(
            $this->service,
            $this->db,
            $managed,
            $policy,
            $this->config,
            $entitlement,
            new BillingSnapshot($this->config),
            $this->recommendation,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/license/status')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin', 'email' => 'admin@example.test']);
        $response = $action($request, new Psr7Response());
        self::assertSame(200, $response->getStatusCode());

        return $this->body($response);
    }

    private function seedUsage(int $invoices, int $journalEntries, ?DateTimeImmutable $at = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        )->execute([PerformanceRecommendation::KEY, json_encode([
            'invoices' => $invoices,
            'journal_entries' => $journalEntries,
            'measured_at' => ($at ?? new DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR)]);
    }

    private function seedActivated(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = ?, token = ?, last_check_ok = 1 WHERE id = 1'
        )->execute(['MYU-TEST-0001-AAAA', $this->token()]);
    }

    /** @param array<string,mixed> $body */
    private function adminRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/performance')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function body(Psr7Response $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return $this->signLicenseToken(array_merge([
            'lic'           => 1,
            'iid'           => $this->instanceId,
            'tier'          => 'single',
            'users'         => 3,
            'max_companies' => 5,
            'valid_until'   => time() + 86400,
            'status'        => 'ok',
            'nonce'         => 'nonce-1',
        ], $overrides));
    }
}
