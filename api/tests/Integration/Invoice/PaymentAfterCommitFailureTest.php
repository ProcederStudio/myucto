<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use DI\Container;
use MyInvoice\Action\Invoice\CreatePaymentAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Stats\StatsRecomputer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Úhrada je po commitu uložená. Přepočet cache statistik, který běží až po něm, smí
 * selhat (typicky deadlock 1213 při hromadném zadávání úhrad přes API), ale nesmí
 * změnit odpověď: 409 `invalid_payment` u uložené úhrady vedla klienta API k tomu,
 * že ji poslal znovu, a vznikla duplicitní úhrada.
 */
#[Group('integration')]
final class PaymentAfterCommitFailureTest extends TestCase
{
    private Container $container;
    private Connection $db;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $recomputeCalls = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $this->currencyId, $vat, $this->userId));

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['Synthetic payment commit', 'Test 1', 'Praha', '11000', $country, uniqid('pay-') . '@example.com', $this->currencyId, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        // Přepočet statistik po commitu úhrady pokaždé skončí deadlockem.
        $stats = $this->getMockBuilder(StatsRecomputer::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['recomputeClient'])
            ->getMock();
        $stats->expects($this->atLeastOnce())->method('recomputeClient')->willReturnCallback(function (): void {
            $this->recomputeCalls++;
            $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
            $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            throw $e;
        });
        $this->container->set(StatsRecomputer::class, $stats);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        $this->db->close();
    }

    public function testStatsFailureAfterCommitKeepsSavedPaymentAndSuccessResponse(): void
    {
        $invoiceId = $this->issuedInvoice();

        $response = ($this->container->get(CreatePaymentAction::class))(
            $this->request('/api/invoices/' . $invoiceId . '/payments', ['amount' => 1210, 'paid_on' => '2099-06-20']),
            new Psr7Response(),
            ['id' => (string) $invoiceId],
        );

        $body = $this->json($response);
        self::assertGreaterThan(0, $this->recomputeCalls, 'Přepočet statistik po úhradě neproběhl, test nic neověřil.');
        self::assertSame(200, $response->getStatusCode(), (string) json_encode($body));
        self::assertTrue($body['data']['became_paid'] ?? $body['became_paid'] ?? false);
        self::assertSame(1, $this->paymentCount($invoiceId));
        self::assertSame('paid', $this->invoiceStatus($invoiceId));
    }

    public function testStatsFailureAfterCommitKeepsMarkPaidSuccessResponse(): void
    {
        $invoiceId = $this->issuedInvoice();

        $response = ($this->container->get(MarkPaidAction::class))(
            $this->request('/api/invoices/' . $invoiceId . '/mark-paid', ['paid_at' => '2099-06-20']),
            new Psr7Response(),
            ['id' => (string) $invoiceId],
        );

        self::assertGreaterThan(0, $this->recomputeCalls, 'Přepočet statistik po úhradě neproběhl, test nic neověřil.');
        self::assertSame(200, $response->getStatusCode(), (string) json_encode($this->json($response)));
        self::assertSame(1, $this->paymentCount($invoiceId));
        self::assertSame('paid', $this->invoiceStatus($invoiceId));
    }

    private function issuedInvoice(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, "2099-06-15", "2099-06-15", "2099-06-29", ?, 0, 1000, 210, 1210, "issued", "1", ?)'
        )->execute([$this->supplierId, uniqid('PAY-'), $this->clientId, $this->currencyId, $this->userId]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $body */
    private function request(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return (array) json_decode((string) $response->getBody(), true);
    }

    private function paymentCount(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);

        return (int) $stmt->fetchColumn();
    }

    private function invoiceStatus(int $invoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);

        return (string) $stmt->fetchColumn();
    }
}
