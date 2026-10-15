<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Service\Supplier\SupplierCreator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class StandaloneIsdocSettingsTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testStandaloneOptionsRoundTripIndependentlyOfPdfEmbeddingAndOtherSuppliers(): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $action = $container->get(SettingsAction::class);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)')->execute([
                'isdoc-settings-' . bin2hex(random_bytes(6)) . '@example.test',
                password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), 'ISDOC settings test', 'admin',
            ]);
            $userId = (int) $pdo->lastInsertId();
            $supplierId = $container->get(SupplierCreator::class)->create([
                'company_name' => 'Syntetická ISDOC firma', 'street' => 'Testovací 1',
                'city' => 'Testov', 'zip' => '10000', 'email' => 'isdoc@example.test',
            ], $userId, false);
            $otherId = $this->createIsolatedSupplier($pdo, $supplierId);
            $pdo->exec("UPDATE supplier SET embed_isdoc = 0, public_isdoc_enabled = 0, email_attach_isdoc = 0 WHERE id IN ({$supplierId}, {$otherId})");
            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'admin']);

            foreach ([
                [['public_isdoc_enabled' => true], true, false],
                [['email_attach_isdoc' => true], true, true],
                [['public_isdoc_enabled' => false], false, true],
                [['email_attach_isdoc' => false], false, false],
            ] as [$body, $public, $email]) {
                $response = $action->updateSupplier($request->withParsedBody($body), new Response());
                self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
                $response = $action->getSupplier($request, new Response());
                $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($public, $data['public_isdoc_enabled']);
                self::assertSame($email, $data['email_attach_isdoc']);
                self::assertFalse($data['embed_isdoc']);
            }

            $response = $action->getSupplier($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $otherId), new Response());
            $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($data['public_isdoc_enabled']);
            self::assertFalse($data['email_attach_isdoc']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
