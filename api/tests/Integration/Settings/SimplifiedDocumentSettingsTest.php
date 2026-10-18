<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Action\Settings\SupplierInvoiceCounterAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Supplier\SupplierCreator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Nastavení zjednodušeného daňového dokladu (migrace 2037): řada v Číslování,
 * výchozí „netisknout odběratele" ve Fakturaci. Bez nastavení se nic nemění.
 */
#[Group('integration')]
final class SimplifiedDocumentSettingsTest extends TestCase
{
    private \PDO $pdo;
    private SettingsAction $action;
    private SupplierInvoiceCounterAction $counterAction;
    private ServerRequestInterface $request;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje: test vyžaduje DB.');
        }
        $container = Bootstrap::buildContainer();
        $this->pdo = $container->get(Connection::class)->pdo();
        $this->action = $container->get(SettingsAction::class);
        $this->counterAction = $container->get(SupplierInvoiceCounterAction::class);
        $this->pdo->beginTransaction();

        $this->pdo->prepare('INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)')->execute([
            'simplified-settings-' . bin2hex(random_bytes(6)) . '@example.test',
            password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), 'Simplified settings test', 'admin',
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $supplierId = $container->get(SupplierCreator::class)->create([
            'company_name' => 'Syntetická pokladní firma', 'street' => 'Testovací 1',
            'city' => 'Testov', 'zip' => '10000', 'email' => 'pokladna@example.test',
        ], $userId, false);
        $this->request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array<string,mixed> */
    private function supplier(): array
    {
        $response = $this->action->getSupplier($this->request, new Response());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $body */
    private function save(array $body): ResponseInterface
    {
        return $this->action->updateSupplier($this->request->withParsedBody($body), new Response());
    }

    public function testDefaultsKeepTodaysBehaviour(): void
    {
        $data = $this->supplier();

        self::assertNull($data['simplified_number_format']);
        self::assertFalse($data['simplified_hide_customer_default']);
    }

    public function testSettingsRoundTrip(): void
    {
        $response = $this->save(['simplified_number_format' => ' ZD{YYYY}{CCCC} ', 'simplified_hide_customer_default' => true]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $data = $this->supplier();
        self::assertSame('ZD{YYYY}{CCCC}', $data['simplified_number_format']);
        self::assertTrue($data['simplified_hide_customer_default']);

        $this->save(['simplified_number_format' => '', 'simplified_hide_customer_default' => false]);
        $data = $this->supplier();
        self::assertNull($data['simplified_number_format'], 'Prázdné pole = zpět na řadu faktur.');
        self::assertFalse($data['simplified_hide_customer_default']);
    }

    public function testTemplateWithoutCounterIsRejected(): void
    {
        $response = $this->save(['simplified_number_format' => 'ZD{YYYY}']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCollisionWithInvoiceSeriesIsReported(): void
    {
        $this->save(['invoice_number_format' => '{YYYY}{CCCC}', 'simplified_number_format' => 'Z{YYYY}{CCCC}']);

        $types = [];
        foreach ($this->supplier()['number_series_collisions'] as $collision) {
            $types[] = [$collision['a']['type'], $collision['b']['type']];
        }
        self::assertContains(['invoice', 'simplified'], $types, 'Po normalizaci na číslice dávají obě řady stejný VS.');
    }

    public function testCounterOfOwnSeriesCanBeSet(): void
    {
        $this->save(['simplified_number_format' => 'ZD{YYYY}{CCCC}']);

        $response = ($this->counterAction)(
            $this->request->withParsedBody(['type' => 'simplified', 'next_number' => 25, 'date' => '2096-05-01']),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ZD20960025', $body['data']['preview'] ?? $body['preview'] ?? null);
    }
}
