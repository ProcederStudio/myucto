<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Force-edit vystavené ZÁLOHOVÉ faktury (proforma) → faktura přečísluje doklad:
 * staré číslo se uvolní z proforma řady (releaseIfLatest), nové se přidělí v řadě
 * faktur (next()). Typ se uloží (u draftu jde vždy, u vystavené jen přes ?force=1
 * + admin, a to právě s přečíslováním).
 *
 * Pojistka: proformu s navázaným finálem / daňovým dokladem k platbě nelze
 * překlopit (úplata by se zdanila podruhé) → 409 has_linked_documents.
 *
 * Izolace v roce 2099 pod existujícím supplierem; vše uklizeno v tearDown.
 * Soft-skip bez cfg.php / DB / template s counterem.
 */
#[Group('integration')]
final class TypeChangeRenumberTest extends TestCase
{
    private Connection $db;
    private UpdateInvoiceAction $action;
    private VarsymbolGenerator $gen;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private string $proformaTpl = '';
    private string $invoiceTpl = '';
    private \DateTimeImmutable $date;
    /** @var int[] */
    private array $created = [];
    private ?string $originalSimplifiedFormat = null;
    private bool $simplifiedFormatTouched = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(UpdateInvoiceAction::class);
            $this->gen    = $c->get(VarsymbolGenerator::class);
            $config       = $c->get(Config::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $sup = $pdo->query(
            "SELECT invoice_number_format, proforma_number_format FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->invoiceTpl  = trim((string) ($sup['invoice_number_format'] ?? '')) ?: trim((string) $config->get('varsymbol.templates.invoice', ''));
        $this->proformaTpl = trim((string) ($sup['proforma_number_format'] ?? '')) ?: trim((string) $config->get('varsymbol.templates.proforma', ''));
        if (!str_contains($this->invoiceTpl, '{C') || !str_contains($this->proformaTpl, '{C')) {
            $this->markTestSkipped('Chybí proforma/invoice template s counterem ({C+}).');
        }

        $this->clientId   = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/CZK/vat_rate/admin user.');
        }

        $this->date = new \DateTimeImmutable('2099-06-15');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        $pdo->prepare("DELETE FROM invoice_counters WHERE supplier_id = ? AND period LIKE '2099%'")
            ->execute([$this->supplierId]);
        if ($this->simplifiedFormatTouched) {
            $pdo->prepare('UPDATE supplier SET simplified_number_format = ? WHERE id = ?')
                ->execute([$this->originalSimplifiedFormat, $this->supplierId]);
            $this->simplifiedFormatTouched = false;
        }
    }

    /** Vlastní řada zjednodušených dokladů (migrace 2037), v cleanup se vrátí původní. */
    private function useSimplifiedSeries(string $template): void
    {
        $pdo = $this->db->pdo();
        $this->originalSimplifiedFormat = ($v = $pdo->query(
            "SELECT simplified_number_format FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn()) === false ? null : $v;
        $this->simplifiedFormatTouched = true;
        $pdo->prepare('UPDATE supplier SET simplified_number_format = ? WHERE id = ?')
            ->execute([$template, $this->supplierId]);
    }

    /** Vloží vystavenou proformu s daným counterem; vrátí [id, varsymbol]. */
    private function insertIssuedProforma(int $counter): array
    {
        $vs = $this->gen->render($this->proformaTpl, $this->date, $counter);
        $pdo = $this->db->pdo();
        $d = $this->date->format('Y-m-d');
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('proforma', ?, ?, ?, ?, NULL, ?, ?, 'issued', 100, 121, ?)"
        )->execute([$vs, $this->clientId, $this->supplierId, $d, $d, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->created[] = $id;
        // Counter proforma scope dorovnej na tuto hodnotu, ať je release smysluplný.
        $this->gen->syncCounter($this->supplierId, 'proforma', $this->date);
        return [$id, $vs];
    }

    private function counterFor(string $type): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = 0 AND invoice_type = ? AND period LIKE '2099%'"
        );
        $stmt->execute([$this->supplierId, $type]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function forcePut(int $id, array $body): Psr7Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/invoices/' . $id)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withQueryParams(['force' => '1'])
            ->withParsedBody($body);
        return ($this->action)($req, new Psr7Response(), ['id' => (string) $id]);
    }

    private function body(string $type): array
    {
        return [
            'invoice_type' => $type,
            'client_id'    => $this->clientId,
            'currency_id'  => $this->currencyId,
            'issue_date'   => $this->date->format('Y-m-d'),
            'due_date'     => $this->date->modify('+14 days')->format('Y-m-d'),
            'tax_date'     => $this->date->format('Y-m-d'),
            'items'        => [[
                'description'            => 'Test položka',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 100,
                'vat_rate_id'            => $this->vatRateId,
            ]],
        ];
    }

    public function testProformaToInvoiceRenumbers(): void
    {
        [$id, $oldVs] = $this->insertIssuedProforma(1);
        self::assertSame(1, $this->counterFor('proforma'), 'Proforma counter dorovnán na 1.');

        $resp = $this->forcePut($id, $this->body('invoice'));
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        $row = $this->db->pdo()->query("SELECT invoice_type, varsymbol FROM invoices WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('invoice', $row['invoice_type'], 'Typ se uložil jako faktura.');
        self::assertNotSame($oldVs, (string) $row['varsymbol'], 'Doklad dostal nové číslo.');

        // Staré proforma číslo bylo uvolněno z řady (counter zpět na 0).
        self::assertSame(0, $this->counterFor('proforma'), 'Proforma counter byl uvolněn (release).');
        // Nové číslo je v řadě faktur (counter se inkrementoval).
        self::assertGreaterThan(0, $this->counterFor('invoice'), 'Faktura dostala číslo z řady faktur.');
    }

    public function testSimplifiedProformaToInvoiceTakesNumberFromSimplifiedSeries(): void
    {
        $this->useSimplifiedSeries('ZS{YYYY}{CCCC}');
        [$id] = $this->insertIssuedProforma(1);

        $resp = $this->forcePut($id, $this->body('invoice') + ['is_simplified' => true]);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        $vs = (string) $this->db->pdo()->query("SELECT varsymbol FROM invoices WHERE id = {$id}")->fetchColumn();
        self::assertSame('ZS20990001', $vs, 'Zjednodušený doklad dostal číslo z vlastní řady.');
        self::assertSame(1, $this->counterFor('simplified'));
        self::assertSame(0, $this->counterFor('invoice'), 'Řada faktur zůstala nedotčená.');
        self::assertSame(0, $this->counterFor('proforma'), 'Staré číslo se vrátilo do řady záloh.');
    }

    public function testSimplifiedInvoiceToProformaReleasesSimplifiedNumber(): void
    {
        $this->useSimplifiedSeries('ZS{YYYY}{CCCC}');
        $pdo = $this->db->pdo();
        $vs = $this->gen->next($this->supplierId, 'simplified', $this->date);
        $d = $this->date->format('Y-m-d');
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, is_simplified, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', 1, ?, ?, ?, ?, ?, ?, ?, 'issued', 100, 121, ?)"
        )->execute([$vs, $this->clientId, $this->supplierId, $d, $d, $d, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->created[] = $id;
        self::assertSame(1, $this->counterFor('simplified'));

        $resp = $this->forcePut($id, $this->body('proforma'));
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        self::assertSame(0, $this->counterFor('simplified'), 'Číslo se vrátilo do řady zjednodušených dokladů.');
        self::assertSame(1, $this->counterFor('proforma'));
    }

    public function testLinkedFinalBlocksConversion(): void
    {
        [$id, $oldVs] = $this->insertIssuedProforma(1);

        // Navázaný finál (dítě invoice_type='invoice') → konverze musí být zamítnuta.
        $pdo = $this->db->pdo();
        $d = $this->date->format('Y-m-d');
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, parent_invoice_id, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, 'issued', 0, 0, ?)"
        )->execute([$id, $this->invoiceTpl . '-CHILD', $this->clientId, $this->supplierId, $d, $d, $d, $this->currencyId, $this->userId]);
        $this->created[] = (int) $pdo->lastInsertId();

        $resp = $this->forcePut($id, $this->body('invoice'));
        self::assertSame(409, $resp->getStatusCode(), 'Proforma s navázaným finálem nesmí jít překlopit.');

        $row = $this->db->pdo()->query("SELECT invoice_type, varsymbol FROM invoices WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('proforma', $row['invoice_type'], 'Typ zůstal proforma.');
        self::assertSame($oldVs, (string) $row['varsymbol'], 'Číslo se nezměnilo.');
    }
}
