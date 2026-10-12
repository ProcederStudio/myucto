<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\PaymentMethods;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Zjednodušený doklad (§ 30 ZDPH) jde založit bez odběratele — editorem, API i MCP.
 * Bez klienta se doklad naváže na systémového koncového zákazníka firmy; běžný doklad
 * bez klienta dál neprojde validací.
 *
 * Bez obalové transakce (akce přepočítávají cache ve vlastní transakci), úklid v tearDown.
 * Data jsou syntetická.
 */
#[Group('integration')]
final class SimplifiedDocumentCreateTest extends TestCase
{
    private Connection $db;
    private CreateInvoiceAction $create;
    private UpdateInvoiceAction $update;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private ?int $walkInBefore = null;
    /** @var list<int> */
    private array $invoiceIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db     = $c->get(Connection::class);
            $this->create = $c->get(CreateInvoiceAction::class);
            $this->update = $c->get(UpdateInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE UPPER(COALESCE(country, 'CZ')) = 'CZ' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $cur = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 ORDER BY (code = 'CZK') DESC, id LIMIT 1");
        $cur->execute([$this->supplierId]);
        $this->currencyId = (int) $cur->fetchColumn();
        if (in_array(0, [$this->supplierId, $this->userId, $this->vatRateId, $this->currencyId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }
        $this->walkInBefore = $this->walkInId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $created = $this->walkInId();
        if ($this->walkInBefore === null && $created !== null) {
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$created]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$created]);
        }
        $this->db->close();
    }

    public function testSimplifiedInvoiceWithoutClientUsesWalkInCustomer(): void
    {
        $first = $this->post($this->payload(simplified: true));
        $second = $this->post($this->payload(simplified: true));

        self::assertSame(201, $first['status'], json_encode($first['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(201, $second['status']);
        $walkIn = $this->walkInId();
        self::assertNotNull($walkIn);
        self::assertSame($walkIn, (int) $first['body']['client_id']);
        self::assertSame($walkIn, (int) $second['body']['client_id'], 'Oba doklady sdílí jednoho koncového zákazníka.');
    }

    public function testOrdinaryInvoiceWithoutClientStillFailsValidation(): void
    {
        $res = $this->post($this->payload(simplified: false));

        self::assertSame(400, $res['status']);
        self::assertArrayHasKey('client_id', $res['body']['error']['fields'] ?? []);
    }

    public function testDraftUpdateWithoutClientKeepsWalkInCustomer(): void
    {
        $created = $this->post($this->payload(simplified: true));
        $id = (int) $created['body']['id'];

        $req = $this->request('PUT', $this->payload(simplified: true));
        $res = ($this->update)($req, new Psr7Response(), ['id' => (string) $id]);
        $res->getBody()->rewind();
        $body = json_decode((string) $res->getBody(), true);

        self::assertSame(200, $res->getStatusCode(), json_encode($body, JSON_UNESCAPED_UNICODE));
        self::assertSame($this->walkInId(), (int) $body['client_id']);
    }

    private function walkInId(): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM clients WHERE supplier_id = ? AND is_walk_in = 1 LIMIT 1');
        $stmt->execute([$this->supplierId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function payload(bool $simplified): array
    {
        return [
            'invoice_type'       => 'invoice',
            'client_id'          => null,
            'is_simplified'      => $simplified,
            'issue_date'         => '2096-06-10',
            'tax_date'           => '2096-06-10',
            'due_date'           => '2096-06-10',
            'currency_id'        => $this->currencyId,
            'reverse_charge'     => false,
            'prices_include_vat' => true,
            'payment_method'     => PaymentMethods::DEFAULT,
            'language'           => 'cs',
            'items'              => [[
                'description' => 'Drobný prodej (PHPUnit)', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => 80.0, 'vat_rate_id' => $this->vatRateId,
            ]],
        ];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function post(array $body): array
    {
        $res = ($this->create)($this->request('POST', $body), new Psr7Response());
        $res->getBody()->rewind();
        $decoded = (array) json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() === 201 && isset($decoded['id'])) {
            $this->invoiceIds[] = (int) $decoded['id'];
        }
        return ['status' => $res->getStatusCode(), 'body' => $decoded];
    }

    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }
}
