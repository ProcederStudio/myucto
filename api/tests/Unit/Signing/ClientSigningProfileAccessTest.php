<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing;

use MyInvoice\Action\Settings\SigningProfilesAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\ClientSigningProfileAccess;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientSigningProfileAccessTest extends TestCase
{
    public function testCompanyNativeUploadedCertificateIsDelegatable(): void
    {
        self::assertTrue(ClientSigningProfileAccess::allowsConfiguration(self::profile(), null));
        self::assertTrue(ClientSigningProfileAccess::allowsConfiguration(self::profile(), self::credential()));
        // Inaktivní profil lze spravovat a znovu zapnout; výběr pro runtime
        // navíc kontroluje canReference proti aktuální DB.
        self::assertTrue(ClientSigningProfileAccess::allowsConfiguration(self::profile() + ['is_active' => false], self::credential()));
    }

    public static function protectedConfigurations(): iterable
    {
        yield 'personal owner' => [['owner_user_id' => 7], []];
        yield 'external backend' => [['default_backend' => 'java'], []];
        yield 'other usage' => [['allowed_usages' => ['pdf', 'epo']], []];
        yield 'vault' => [[], ['vault_credential_id' => 9]];
        yield 'passphrase file' => [[], ['passphrase_policy' => 'passphrase_file']];
        yield 'prompt' => [[], ['passphrase_policy' => 'prompt_on_use']];
        yield 'absolute path' => [[], ['certificate_path' => '/etc/private/signing.p12']];
        yield 'windows path' => [[], ['certificate_path' => 'C:\\private\\signing.p12']];
        yield 'other supplier path' => [[], ['certificate_path' => 'signing/profiles/supplier-99/profile-42/profile.p12']];
        yield 'traversal' => [[], ['certificate_path' => 'signing/profiles/supplier-3/profile-42/../profile.p12']];
    }

    #[DataProvider('protectedConfigurations')]
    public function testProtectedConfigurationsAreNotDelegatable(array $profileChanges, array $credentialChanges): void
    {
        self::assertFalse(ClientSigningProfileAccess::allowsConfiguration(
            array_replace(self::profile(), $profileChanges),
            array_replace(self::credential(), $credentialChanges),
        ));
    }

    public static function deniedRequests(): iterable
    {
        yield 'anonymous' => [null, null, 3];
        yield 'missing auth method' => ['client', null, 3];
        yield 'bearer' => ['client', 'bearer', 3];
        yield 'reader' => ['reader', 'session', 3];
        yield 'staff' => ['staff', 'session', 3];
        yield 'no supplier' => ['client', 'session', 0];
    }

    #[DataProvider('deniedRequests')]
    public function testRawClientEntryPointRejectsWithoutSessionCompanyWriteAndSupplier(?string $role, ?string $method, int $supplierId): void
    {
        $action = (new \ReflectionClass(SigningProfilesAction::class))->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/settings/client/signing/profiles')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', new EffectiveRole(
                3, 'Synthetic role', $role === 'staff' ? 'staff' : 'client', true,
                ['settings.company' => $role === 'reader' ? 1 : 2],
            ));
        }
        if ($method !== null) $request = $request->withAttribute(AuthMiddleware::ATTR_METHOD, $method);

        self::assertSame(403, $action->client($request, (new ResponseFactory())->createResponse(), ['operation' => 'listProfiles'])->getStatusCode());
    }

    private static function profile(): array
    {
        return ['id' => 42, 'supplier_id' => 3, 'owner_user_id' => null,
            'default_backend' => 'native', 'allowed_usages' => ['pdf', 'email_smime']];
    }

    private static function credential(): array
    {
        return ['vault_credential_id' => null, 'passphrase_policy' => 'encrypted_store',
            'certificate_path' => 'signing/profiles/supplier-3/profile-42/profile.p12'];
    }
}
