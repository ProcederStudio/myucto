<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Service\Auth\WebAuthnOperationPolicy;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Tenant\TenantDomainPolicy;
use PHPUnit\Framework\TestCase;

final class TenantDomainPolicyTest extends TestCase
{
    private TenantDomainPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TenantDomainPolicy();
    }

    public function testUnknownHostnameIsRejectedBeforeRouting(): void
    {
        $denial = $this->policy->denial(
            new TenantDomainContext(TenantDomainContext::UNKNOWN, 'unknown.example.test', ''),
            'GET',
            '/portal',
        );

        self::assertSame(421, $denial['status'] ?? null);
        self::assertSame('unknown_host', $denial['code'] ?? null);
    }

    public function testCanonicalHostnameConflictIsRejectedBeforeRouting(): void
    {
        $denial = $this->policy->denial(
            new TenantDomainContext(
                TenantDomainContext::CONFIGURATION_ERROR,
                'app.example.test',
                'https://app.example.test',
            ),
            'GET',
            '/',
        );

        self::assertSame(421, $denial['status'] ?? null);
        self::assertSame('canonical_hostname_conflict', $denial['code'] ?? null);
    }

    public function testPendingHostnameOnlyServesItsExactVerificationEndpoint(): void
    {
        $context = $this->context(TenantDomainContext::VERIFICATION, 'all');
        $token = str_repeat('a', 64);

        self::assertNull($this->policy->denial(
            $context,
            'GET',
            '/api/public/domain-verification/' . $token,
        ));
        self::assertSame(421, $this->policy->denial($context, 'GET', '/portal')['status'] ?? null);
        self::assertSame(
            421,
            $this->policy->denial(
                $context,
                'POST',
                '/api/public/domain-verification/' . $token,
            )['status'] ?? null,
        );
    }

    public function testPortalOnlyDomainCannotServePublicTokenRoutes(): void
    {
        $context = $this->context(TenantDomainContext::CUSTOM, 'portal');

        self::assertSame(
            404,
            $this->policy->denial($context, 'GET', '/api/public/invoice/' . str_repeat('a', 48))['status'] ?? null,
        );
        self::assertNull($this->policy->denial($context, 'GET', '/portal'));
        self::assertNull($this->policy->denial($context, 'GET', '/clients/42/edit'));
        self::assertNull($this->policy->denial($context, 'GET', '/invoices/new'));
        self::assertNull($this->policy->denial($context, 'GET', '/purchase-invoices/42'));
        self::assertNull($this->policy->denial($context, 'GET', '/recurring/42/edit'));
        self::assertNull($this->policy->denial($context, 'GET', '/projects'));
        self::assertNull($this->policy->denial($context, 'GET', '/admin/price-list'));
        self::assertNull($this->policy->denial($context, 'GET', '/logbook'));
        self::assertNull($this->policy->denial($context, 'GET', '/stock/items'));
        self::assertNull($this->policy->denial($context, 'GET', '/stock/fulfillment'));
        self::assertNull($this->policy->denial($context, 'GET', '/profile/password'));
        self::assertNull($this->policy->denial($context, 'GET', '/exchange'));
        self::assertNull($this->policy->denial($context, 'GET', '/admin/export'));
        self::assertNull($this->policy->denial($context, 'GET', '/admin/import'));
        self::assertNull($this->policy->denial($context, 'GET', '/api/clients'));
        self::assertNull($this->policy->denial($context, 'GET', '/api/projects'));
    }

    public function testPortalDomainRejectsStaffAndUnauthenticatedSurfaces(): void
    {
        $context = $this->context(TenantDomainContext::CUSTOM, 'portal');

        foreach ([
            ['GET', '/accounting/journal'],
            ['GET', '/stock/reports'],
            ['GET', '/purchase-invoices/payment-orders'],
            ['GET', '/admin/settings'],
            ['GET', '/profile/api-tokens'],
            ['GET', '/forgot'],
            ['POST', '/api/auth/login'],
            ['GET', '/api/accounting/journal'],
            ['GET', '/api/stock/reports'],
            ['GET', '/api/admin/users'],
            ['GET', '/api/admin/export'],
            ['POST', '/api/admin/import'],
        ] as [$method, $path]) {
            $denial = $this->policy->denial($context, $method, $path);
            self::assertSame(404, $denial['status'] ?? null, "$method $path");
            self::assertSame('client_surface_only', $denial['code'] ?? null, "$method $path");
        }
    }

    public function testWebAuthnApiIsCanonicalOnlyWhileCustomPagesCanStartTheHandoff(): void
    {
        $custom = $this->context(TenantDomainContext::CUSTOM, 'portal');

        $canonical = new TenantDomainContext(
            TenantDomainContext::CANONICAL,
            'app.example.test',
            'https://app.example.test',
        );

        foreach ((new WebAuthnOperationPolicy())->inventory() as $name => $operation) {
            $path = $operation['example_path'];
            foreach ([$operation['method'], ...($operation['method_aliases'] ?? [])] as $method) {
                $denial = $this->policy->denial($custom, $method, $path);
                self::assertSame(404, $denial['status'] ?? null, "$name: $method $path");
                self::assertSame('client_surface_only', $denial['code'] ?? null, "$name: $method $path");
                self::assertNull(
                    $this->policy->denial($canonical, $method, $path),
                    "$name: $method $path",
                );
            }
        }

        self::assertNull($this->policy->denial($custom, 'GET', '/profile/passkeys'));
        self::assertNull($this->policy->denial($custom, 'GET', '/setup-mfa'));
    }

    public function testCustomDomainKeepsNonWebAuthnMfaAndSessionStateOperationsAvailable(): void
    {
        $custom = $this->context(TenantDomainContext::CUSTOM, 'portal');

        foreach ([
            ['GET', '/api/auth/totp/status'],
            ['POST', '/api/auth/totp/setup'],
            ['POST', '/api/auth/totp/enable'],
            ['POST', '/api/auth/mfa/step-up/totp'],
            ['POST', '/api/auth/mfa/step-up/recovery'],
            ['GET', '/api/auth/mfa/recovery-codes'],
            ['POST', '/api/auth/mfa/recovery-codes'],
            ['GET', '/api/auth/session/status'],
            ['POST', '/api/auth/session/lock'],
            ['GET', '/api/auth/session/lock-preference'],
            ['PUT', '/api/auth/session/lock-preference'],
        ] as [$method, $path]) {
            self::assertNull($this->policy->denial($custom, $method, $path), "$method $path");
        }
    }

    public function testUnverifiedAndUnknownHostsCannotStartCanonicalHandoff(): void
    {
        $verification = $this->context(TenantDomainContext::VERIFICATION, 'portal');
        $unknown = new TenantDomainContext(TenantDomainContext::UNKNOWN, 'unknown.example.test', '');

        self::assertSame(
            421,
            $this->policy->denial($verification, 'POST', '/api/auth/domain-login/start')['status'] ?? null,
        );
        self::assertSame(
            421,
            $this->policy->denial($unknown, 'POST', '/api/auth/domain-login/start')['status'] ?? null,
        );
    }

    public function testPublicOnlyDomainCannotServePortalButCanServePublicPage(): void
    {
        $context = $this->context(TenantDomainContext::CUSTOM, 'public_links');

        self::assertSame(404, $this->policy->denial($context, 'GET', '/')['status'] ?? null);
        self::assertSame(404, $this->policy->denial($context, 'GET', '/portal')['status'] ?? null);
        self::assertNull($this->policy->denial($context, 'GET', '/invoice/token'));
        self::assertNull($this->policy->denial($context, 'GET', '/assets/app.js'));
    }

    private function context(string $mode, string $purpose): TenantDomainContext
    {
        return new TenantDomainContext(
            $mode,
            'portal.example.test',
            'https://portal.example.test',
            7,
            12,
            $purpose,
            $mode === TenantDomainContext::CUSTOM ? 'active' : 'pending',
        );
    }
}
