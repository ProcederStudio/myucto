<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BrandingProfileRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Koncept si drží branding_profile_id i poté, co profil přestal být použitelný
 * (deaktivace, vypnutý modul profilů, smazání). Vystavení kvůli tomu nesmí
 * skončit 500 „Brandingový profil #N nenalezen": použije aktivní výchozí profil
 * firmy, případně žádný. Deaktivace a smazání profilu rovnou přepnou koncepty
 * na výchozí profil.
 */
#[Group('integration')]
final class InvoiceBrandingProfileFallbackTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private BrandingProfileRepository $profiles;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $defaultProfileId = 0;
    private int $otherProfileId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->profiles = $container->get(BrandingProfileRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query(
            'SELECT id FROM vat_rates WHERE rate_percent = 21 AND is_reverse_charge = 0 ORDER BY valid_from DESC LIMIT 1'
        )->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/currency/vat_rate/country).');
        }

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier SET stock_enabled = 0, accounting_mode = 'tax_evidence',
                    branding_profiles_enabled = 1, default_branding_profile_id = NULL
              WHERE id = ?"
        )->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, currency_default_id,
                 main_email, language, is_customer, is_vendor)
             VALUES (?, "Odběratel Profil s.r.o.", "Testovací 1", "Praha", "11000", ?, ?, "", "cs", 1, 0)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $this->defaultProfileId = $this->profiles->create($this->supplierId, [
            'name' => 'Výchozí značka', 'display_name' => 'Výchozí značka s.r.o.',
        ]);
        $this->otherProfileId = $this->profiles->create($this->supplierId, [
            'name' => 'Vedlejší značka', 'display_name' => 'Vedlejší značka s.r.o.',
        ]);
        self::assertTrue($this->profiles->setDefault($this->defaultProfileId, $this->supplierId));
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
        $sid = $this->supplierId;
        $pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')
            ->execute([$sid]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('UPDATE supplier SET default_branding_profile_id = NULL WHERE id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM branding_profiles WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
        $this->db->close();
    }

    public function testIssueWithDeactivatedProfileUsesSupplierDefaultProfile(): void
    {
        $id = $this->draft($this->otherProfileId);
        // Stav z produkce: koncept odkazuje na profil, který mezitím někdo vypnul.
        $this->db->pdo()->prepare('UPDATE branding_profiles SET is_active = 0 WHERE id = ?')
            ->execute([$this->otherProfileId]);

        $result = $this->issue($id);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE) ?: '');
        $snapshot = $this->supplierSnapshot($id);
        self::assertSame('Výchozí značka', $snapshot['branding_profile_name'] ?? null);
        self::assertSame('Výchozí značka s.r.o.', $snapshot['display_name'] ?? null);
    }

    public function testIssueWithDisabledProfilesModuleIssuesWithoutProfile(): void
    {
        $id = $this->draft($this->otherProfileId);
        $this->db->pdo()->prepare('UPDATE supplier SET branding_profiles_enabled = 0 WHERE id = ?')
            ->execute([$this->supplierId]);

        $result = $this->issue($id);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertArrayNotHasKey('branding_profile_name', $this->supplierSnapshot($id));
    }

    public function testIssueWithActiveProfileKeepsIt(): void
    {
        $id = $this->draft($this->otherProfileId);

        $result = $this->issue($id);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('Vedlejší značka', $this->supplierSnapshot($id)['branding_profile_name'] ?? null);
    }

    public function testDeactivatingProfileMovesDraftsToDefaultProfile(): void
    {
        $id = $this->draft($this->otherProfileId);

        self::assertTrue($this->profiles->update($this->otherProfileId, $this->supplierId, ['is_active' => false]));

        self::assertSame($this->defaultProfileId, $this->draftProfile($id));
    }

    public function testDeletingProfileMovesDraftsToDefaultProfile(): void
    {
        $id = $this->draft($this->otherProfileId);

        self::assertTrue($this->profiles->delete($this->otherProfileId, $this->supplierId));

        self::assertSame($this->defaultProfileId, $this->draftProfile($id));
    }

    public function testDeletingDefaultProfileLeavesDraftsWithoutProfile(): void
    {
        $id = $this->draft($this->defaultProfileId);

        self::assertTrue($this->profiles->delete($this->defaultProfileId, $this->supplierId));

        self::assertNull($this->draftProfile($id));
    }

    private function draft(?int $brandingProfileId): int
    {
        $pdo = $this->db->pdo();
        $date = date('Y-m-d');
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, branding_profile_id, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, language, status, total_without_vat, total_vat, total_with_vat, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, "cs", "draft", 100, 21, 121, ?)'
        )->execute([$this->supplierId, $brandingProfileId, $this->clientId, $date, $date, $date, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Testovací plnění", 1, "ks", 100, ?, 21, 100, 21, 121, 0)'
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    private function draftProfile(int $id): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT branding_profile_id FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        return $value === null ? null : (int) $value;
    }

    /** @return array<string,mixed> */
    private function supplierSnapshot(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_snapshot FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $decoded = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function issue(int $id): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/issue')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody([]);
        $action = Bootstrap::buildContainer()->get(IssueInvoiceAction::class);
        $response = $action($request, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
