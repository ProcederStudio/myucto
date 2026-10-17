<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Kopie dokladu a dobropis přenášejí textový řádek (item_kind 'text') na jeho místo:
 * dál bez částky a bez klasifikace DPH, kopie s posunutým měsícem v textu.
 *
 * Kopie i dobropis si otevírají vlastní transakci, proto test zapisuje naostro
 * (firma v daňové evidenci, dnešní datum) a v tearDown po sobě uklidí.
 */
#[Group('integration')]
final class InvoiceTextRowCopyTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $repo;
    private \Psr\Container\ContainerInterface $container;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $rateId = 0;
    /** @var list<int> */
    private array $invoiceIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->repo = $this->container->get(InvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query(
            "SELECT id FROM supplier WHERE accounting_mode = 'tax_evidence' AND is_vat_payer = 1 ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rateId = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 AND valid_to IS NULL ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->userId, $this->rateId, $currencyId, $czId], true)) {
            $this->markTestSkipped('Chybí plátce v daňové evidenci, uživatel, sazba 21 %, CZK nebo země CZ.');
        }
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Odběratel kopie textového řádku", "Test 1", "Praha", "11000", ?, "CZ12345678",
                     "kopie@example.invalid", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $czId, $currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $today = date('Y-m-d');
        $source = $this->repo->createDraft([
            'invoice_type' => 'invoice', 'client_id' => $this->clientId, 'issue_date' => $today,
            'tax_date' => $today, 'due_date' => $today, 'currency_id' => $currencyId,
            'reverse_charge' => false, 'language' => 'cs',
        ], $this->userId);
        $this->invoiceIds[] = $source;
        $this->repo->replaceItems($source, [
            ['description' => 'Servis', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->rateId],
            ['item_kind' => 'text', 'description' => 'Období 1/2093', 'vat_rate_id' => $this->rateId],
            ['description' => 'Doprava', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 200, 'vat_rate_id' => $this->rateId],
        ]);
        $this->container->get(InvoiceCalculator::class)->recompute($source);
        $pdo->prepare("UPDATE invoices SET status = 'issued', varsymbol = ?, vat_classification_code = '1' WHERE id = ?")
            ->execute(['TXT' . random_int(1000000, 9999999), $source]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->invoiceIds !== []) {
            $ids = implode(',', array_map('intval', $this->invoiceIds));
            $children = $pdo->query("SELECT id FROM invoices WHERE parent_invoice_id IN ({$ids})")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ([...$children, ...array_reverse($this->invoiceIds)] as $id) {
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([(int) $id]);
            }
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    public function testCloneKeepsTextRowInPlaceWithShiftedMonth(): void
    {
        $cloneId = $this->container->get(BulkReissueAction::class)->cloneOne($this->invoiceIds[0], date('Y-m-d'), true, $this->userId);
        $this->invoiceIds[] = $cloneId;

        $clone = $this->assertTextRowAt($cloneId, 1, 'Období 2/2093');
        self::assertSame(1200.0, (float) $clone['total_without_vat']);
    }

    public function testCreditNoteKeepsTextRowInPlace(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['mode' => 'credit_note', 'reason' => 'Test textového řádku']);
        $resp = ($this->container->get(CancelInvoiceAction::class))($req, new Psr7Response(), ['id' => (string) $this->invoiceIds[0]]);
        $resp->getBody()->rewind();
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());

        $stmt = $this->db->pdo()->prepare("SELECT id FROM invoices WHERE parent_invoice_id = ? AND invoice_type = 'credit_note'");
        $stmt->execute([$this->invoiceIds[0]]);
        $creditNoteId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $creditNoteId);

        $credit = $this->assertTextRowAt($creditNoteId, 1, 'Období 1/2093');
        self::assertSame(-1200.0, (float) $credit['total_without_vat']);
        self::assertCount(1, $credit['vat_breakdown']);
    }

    /** @return array<string,mixed> */
    private function assertTextRowAt(int $invoiceId, int $index, string $description): array
    {
        $invoice = $this->repo->find($invoiceId);
        self::assertNotNull($invoice);
        $items = $invoice['items'];
        self::assertCount(3, $items);
        self::assertSame(['standard', 'text', 'standard'], array_column($items, 'item_kind'));
        self::assertSame($description, $items[$index]['description']);
        self::assertSame(0.0, $items[$index]['total_with_vat']);
        self::assertSame(0.0, abs($items[$index]['quantity']));
        self::assertNull($items[$index]['vat_classification_code']);
        return $invoice;
    }
}
