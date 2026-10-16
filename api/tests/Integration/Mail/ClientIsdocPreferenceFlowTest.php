<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Mail;

use MyInvoice\Action\Client\CreateClientAction;
use MyInvoice\Action\Client\GetClientAction;
use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Invoice\InvoiceIsdocDeliverySettings;
use MyInvoice\Service\Supplier\SupplierCreator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class ClientIsdocPreferenceFlowTest extends TestCase
{
    private ContainerInterface $container;
    private \PDO $pdo;
    private ClientRepository $clients;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->clients = $this->container->get(ClientRepository::class);
        $this->pdo->beginTransaction();
        $this->pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)')->execute([
            'isdoc-client-' . bin2hex(random_bytes(6)) . '@example.test',
            password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), 'ISDOC client test', 'admin',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->supplierId = $this->container->get(SupplierCreator::class)->create([
            'company_name' => 'Syntetická klientská ISDOC firma', 'street' => 'Testovací 1',
            'city' => 'Testov', 'zip' => '10000', 'email' => 'isdoc-client@example.test',
        ], $this->userId, false);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testApiCreateAndUpdateRoundTripPreservesOmissionAndResetsExplicitNull(): void
    {
        $supplier = $this->pdo->prepare('SELECT public_isdoc_enabled, email_attach_isdoc FROM supplier WHERE id = ?');
        $supplier->execute([$this->supplierId]);
        $defaults = $supplier->fetch(\PDO::FETCH_ASSOC);
        self::assertFalse((bool) $defaults['public_isdoc_enabled']);
        self::assertFalse((bool) $defaults['email_attach_isdoc']);
        foreach ([[], ['email_attach_isdoc' => null], ['email_attach_isdoc' => false], ['email_attach_isdoc' => true]] as $preference) {
            $response = ($this->container->get(CreateClientAction::class))($this->request('POST', $this->clientData($preference)), new Response());
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
            $client = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($preference['email_attach_isdoc'] ?? null, $client['email_attach_isdoc']);
            $id = (int) $client['id'];
            self::assertSame($client['email_attach_isdoc'], $this->clients->find($id)['email_attach_isdoc']);
            $get = ($this->container->get(GetClientAction::class))($this->request('GET'), new Response(), ['id' => $id]);
            self::assertSame(200, $get->getStatusCode(), (string) $get->getBody());
            self::assertSame($client['email_attach_isdoc'], json_decode((string) $get->getBody(), true)['email_attach_isdoc']);
        }
        foreach ([
            [['email_attach_isdoc' => true], true], [['note' => 'První poznámka'], true],
            [['email_attach_isdoc' => false], false], [['note' => 'Druhá poznámka'], false],
            [['email_attach_isdoc' => null], null], [['note' => 'Třetí poznámka'], null],
        ] as [$patch, $expected]) {
            $response = ($this->container->get(UpdateClientAction::class))($this->request('PUT', $patch), new Response(), ['id' => $id]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame($expected, json_decode((string) $response->getBody(), true)['email_attach_isdoc']);
            self::assertSame($expected, $this->clients->find($id)['email_attach_isdoc']);
        }
        $supplier->execute([$this->supplierId]);
        self::assertSame($defaults, $supplier->fetch(\PDO::FETCH_ASSOC));
    }

    public function testRepositoryPreferencesDriveDeliveryAndDoNotChangePublicDownload(): void
    {
        $settings = $this->container->get(InvoiceIsdocDeliverySettings::class);
        foreach ([false, true] as $supplierDefault) {
            $this->pdo->prepare('UPDATE supplier SET email_attach_isdoc = ?, public_isdoc_enabled = 0 WHERE id = ?')
                ->execute([(int) $supplierDefault, $this->supplierId]);
            foreach ([null, false, true] as $preference) {
                $id = $this->clients->create($this->clientData(['email_attach_isdoc' => $preference]), $this->supplierId);
                $invoice = ['supplier_id' => $this->supplierId, 'client_id' => $id, 'status' => 'issued'];
                self::assertSame($preference, $this->clients->find($id)['email_attach_isdoc']);
                self::assertSame($preference ?? $supplierDefault, $settings->emailEnabled($invoice));
                self::assertFalse($settings->publicEnabled($invoice));
                $this->clients->update($id, ['note' => 'Samostatná úprava']);
                self::assertSame($preference, $this->clients->find($id)['email_attach_isdoc']);
                $this->clients->update($id, ['email_attach_isdoc' => null]);
                self::assertNull($this->clients->find($id)['email_attach_isdoc']);
                self::assertSame($supplierDefault, $settings->emailEnabled($invoice));
            }
        }
    }

    public function testApiAndRepositoryRejectInvalidValuesWithoutChangingSavedPreference(): void
    {
        $id = $this->clients->create($this->clientData(['email_attach_isdoc' => false]), $this->supplierId);
        foreach (['false', '', 0, 1, 2, [], ['value' => true]] as $invalid) {
            $body = $this->clientData(['email_attach_isdoc' => $invalid]);
            $created = ($this->container->get(CreateClientAction::class))($this->request('POST', $body), new Response());
            $updated = ($this->container->get(UpdateClientAction::class))($this->request('PUT', ['email_attach_isdoc' => $invalid]), new Response(), ['id' => $id]);
            foreach ([$created, $updated] as $response) {
                self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
                self::assertStringContainsString('email_attach_isdoc', (string) $response->getBody());
            }
            foreach ([fn () => $this->clients->create($body, $this->supplierId), fn () => $this->clients->update($id, ['email_attach_isdoc' => $invalid])] as $write) {
                try {
                    $write();
                    self::fail('Neplatná preference musí být odmítnuta i při přímém zápisu přes repository.');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('ISDOC', $e->getMessage());
                }
            }
            self::assertFalse($this->clients->find($id)['email_attach_isdoc']);
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ?');
        $count->execute([$this->supplierId]);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    private function clientData(array $overrides = []): array
    {
        return array_replace([
            'company_name' => 'Syntetický klient ISDOC', 'street' => 'Zkušební 1', 'city' => 'Testov',
            'zip' => '10000', 'country_iso2' => 'CZ', 'main_email' => 'client@example.test',
            'currency_default' => 'CZK', 'is_customer' => true, 'is_vendor' => false,
        ], $overrides);
    }

    private function request(string $method, array $body = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/clients')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }
}
