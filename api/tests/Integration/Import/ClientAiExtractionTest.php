<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Action\Admin\Import\AiExtractPdfAction;
use MyInvoice\Action\Admin\Import\AiProviderCredentialsAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientAiExtractionPolicy;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;

#[Group('integration')]
final class ClientAiExtractionTest extends StockTestCase
{
    private int $sid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = $this->createSupplier();
        $this->db->pdo()->prepare("UPDATE supplier SET ai_provider = 'gemini', client_ai_extraction_enabled = 0, gemini_api_key_enc = NULL, ic = '12345679' WHERE id = ?")
            ->execute([$this->sid]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach ($this->supplierIds as $sid) {
                $this->db->pdo()->prepare('DELETE FROM purchase_invoice_counters WHERE supplier_id = ?')->execute([$sid]);
            }
        }
        parent::tearDown();
    }

    public function testClientCannotExtractUntilCompanyEnablesOwnSelectedProvider(): void
    {
        $request = $this->request();
        $action = $this->container->get(AiExtractPdfAction::class);
        self::assertSame(403, $action($request, new Response())->getStatusCode());
        $this->enable();
        self::assertSame(422, $action($request, new Response())->getStatusCode(), 'Zapnutí bez vlastního klíče nestačí.');
        $other = $this->createSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET gemini_api_key_enc = ? WHERE id = ?')->execute([
            $this->container->get(SecretEncryption::class)->encrypt('synthetic-other-company-key'), $other,
        ]);
        self::assertSame(422, $action($request, new Response())->getStatusCode(), 'Klíč jiné firmy se nepoužije.');
        $this->configure();
        self::assertSame(400, $action($request, new Response())->getStatusCode(), 'Oprávněný klient projde až na validaci souboru.');
    }

    public function testSupplierClientRoleOverrideStillRequiresCompanyOptIn(): void
    {
        $request = $this->request()->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        $res = ($this->container->get(AiExtractPdfAction::class))($request, new Response());
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('client_ai_extraction_disabled', json_decode((string) $res->getBody(), true)['error']['code']);
    }

    #[DataProvider('missingPermissions')]
    public function testClientNeedsWriteRightsToExtractCreateAndReview(string $permission, int $level): void
    {
        $this->configure();
        $permissions = (new PermissionCatalog())->legacyPreset('client');
        $permissions[$permission] = $level;
        $request = $this->request(new EffectiveRole(101, 'Synthetic client', 'client', true, $permissions));
        self::assertSame(403, ($this->container->get(AiExtractPdfAction::class))($request, new Response())->getStatusCode());
        self::assertSame(403, $this->container->get(AiProviderCredentialsAction::class)->extractionStatus($request, new Response())->getStatusCode());
    }

    public static function missingPermissions(): iterable
    {
        yield 'extraction read only' => ['purchase_invoices.extract', 1];
        yield 'create read only' => ['purchase_invoices.create', 1];
        yield 'review read only' => ['purchase_invoices', 1];
    }

    public function testSafeStatusShowsDisabledWithoutReturningCredentials(): void
    {
        $res = $this->container->get(AiProviderCredentialsAction::class)->extractionStatus($this->request(), new Response());
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        self::assertFalse($body['client_ai_extraction_enabled']);
        self::assertSame(['configured', 'default_model', 'models', 'allowed_models'], array_keys($body['providers']['gemini']));
        self::assertFalse($body['providers']['gemini']['configured']);
    }

    public function testIssuedStatusRetainsIndependentInvoiceCreatePermission(): void
    {
        $request = $this->request(new EffectiveRole(106, 'Issued client', 'client', true, ['invoices' => 2, 'invoices.create' => 2]));
        $action = $this->container->get(AiProviderCredentialsAction::class);
        self::assertSame(403, $action->extractionStatus($request, new Response())->getStatusCode());
        $issued = $request->withUri($request->getUri()->withPath('/api/admin/imports/ai-extract-pdf-issued/status'));
        self::assertSame(200, $action->extractionStatus($issued, new Response())->getStatusCode());
    }

    public function testClientCannotEnableItselfEvenWithDelegatedSettingsWrite(): void
    {
        $permissions = (new PermissionCatalog())->legacyPreset('client');
        $permissions['settings.company.write'] = 2;
        $permissions['settings.ai_provider'] = 2;
        $req = $this->request(new EffectiveRole(102, 'Delegated client', 'client', true, $permissions))
            ->withParsedBody(['client_ai_extraction_enabled' => true]);
        $res = $this->container->get(SettingsAction::class)->updateSupplier($req, new Response());
        self::assertSame(403, $res->getStatusCode());
        self::assertFalse($this->container->get(ClientAiExtractionPolicy::class)->supplierState($this->sid)['client_ai_extraction_enabled']);
    }

    public function testStaffCanEnableAndDisableClientExtraction(): void
    {
        $action = $this->container->get(SettingsAction::class);
        $req = $this->request(new EffectiveRole(103, 'Superadmin', 'superadmin', true, [], 'superadmin'));
        foreach ([true, false] as $value) {
            $res = $action->updateSupplier($req->withParsedBody(['client_ai_extraction_enabled' => $value]), new Response());
            self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
            self::assertSame($value, json_decode((string) $res->getBody(), true)['client_ai_extraction_enabled']);
        }
    }

    public function testAccountantRetainsAccessWithoutClientEnableOrKey(): void
    {
        $request = $this->request(new EffectiveRole(104, 'Accountant', 'staff', true, (new PermissionCatalog())->legacyPreset('accountant')));
        self::assertSame(400, ($this->container->get(AiExtractPdfAction::class))($request, new Response())->getStatusCode());
    }

    public function testClientAndLockedDomainCannotRouteAcrossCompanies(): void
    {
        self::assertFalse(ClientAiExtractionPolicy::canRouteAcrossSuppliers($this->request()));
        $staff = $this->request(new EffectiveRole(105, 'Staff', 'staff', true, ['purchase_invoices.extract' => 2]));
        self::assertTrue(ClientAiExtractionPolicy::canRouteAcrossSuppliers($staff));
        self::assertFalse(ClientAiExtractionPolicy::canRouteAcrossSuppliers($staff->withAttribute(
            TenantDomainMiddleware::ATTR_CONTEXT,
            new TenantDomainContext(TenantDomainContext::CUSTOM, 'portal.example.test', 'https://portal.example.test', supplierId: $this->sid, purpose: 'portal'),
        )));
    }

    public function testClientUploadCreatesReviewableDraftAndKeepsCurrentCompany(): void
    {
        $this->configure();
        $vendor = $this->client($this->sid, 'Synthetic AI vendor');
        $this->container->get(\MyInvoice\Repository\CardClearingSettingsRepository::class)->save($this->sid, ['enabled' => true], $this->userId);
        $gateway = $this->createMock(LlmGatewayInterface::class);
        $gateway->expects(self::once())->method('extractInvoice')->with($this->sid, self::anything(), null, LlmGatewayInterface::TENANT_ROLE_BUYER)
            ->willReturn(['ok' => true, 'data' => $this->data(), 'model' => 'synthetic-model']);
        $gateway->method('strongerModel')->willReturn(null);
        $extractor = $this->rebuild($this->container->get(AiPdfExtractor::class), ['anthropic' => $gateway]);
        $action = $this->rebuild($this->container->get(AiExtractPdfAction::class), ['extractor' => $extractor]);
        $res = $action($this->upload($this->request()), new Response());
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $id = (int) json_decode((string) $res->getBody(), true)['purchase_invoice_id'];
        $row = $this->invoice($id);
        self::assertSame($this->sid, (int) $row['supplier_id']);
        self::assertSame('draft', $row['status']);
        self::assertNull($row['paid_at']);
        self::assertTrue((bool) $row['prices_include_vat']);
        self::assertSame($vendor, (int) $row['vendor_id']);
        $edit = $this->request()->withParsedBody(['note_above_items' => 'Synthetic client review completed']);
        $updated = ($this->container->get(\MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction::class))($edit, new Response(), ['id' => (string) $id]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame('Synthetic client review completed', $this->invoice($id)['note_above_items']);
        $receive = $this->request()->withParsedBody(['target' => 'received']);
        $received = ($this->container->get(\MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction::class))($receive, new Response(), ['id' => (string) $id]);
        self::assertSame(200, $received->getStatusCode(), (string) $received->getBody());
        self::assertSame('received', $this->invoice($id)['status']);
    }

    #[DataProvider('structuredSources')]
    public function testStructuredDocumentsAlsoStayDraft(string $source): void
    {
        $this->configure();
        $this->client($this->sid, 'Synthetic AI vendor');
        $this->container->get(\MyInvoice\Repository\CardClearingSettingsRepository::class)->save($this->sid, ['enabled' => true], $this->userId);
        $xml = '<Invoice xmlns="http://isdoc.cz/namespace/2013"><DocumentType>1</DocumentType><ID>991001</ID>'
            . '<IssueDate>2026-09-01</IssueDate><TaxPointDate>2026-09-01</TaxPointDate><LocalCurrencyCode>CZK</LocalCurrencyCode>'
            . '<AccountingSupplierParty><Party><PartyName><Name>Synthetic AI vendor</Name></PartyName></Party></AccountingSupplierParty>'
            . '<AccountingCustomerParty><Party><PartyIdentification><ID>12345679</ID></PartyIdentification></Party></AccountingCustomerParty>'
            . '<InvoiceLines><InvoiceLine><Item><Description>Synthetic service</Description></Item>'
            . '<InvoicedQuantity unitCode="ks">1</InvoicedQuantity><UnitPrice>100</UnitPrice>'
            . '<ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory></InvoiceLine></InvoiceLines></Invoice>';
        if ($source === 'isdocx') {
            $path = tempnam(sys_get_temp_dir(), 'client-isdocx-');
            try {
                $zip = new \ZipArchive();
                self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
                $zip->addFromString('document.isdoc', $xml);
                $zip->addFromString('document.pdf', '%PDF-1.4 synthetic-readable-document');
                $zip->close();
                $bytes = file_get_contents($path);
            } finally {
                unlink($path);
            }
        } else {
            $compressed = gzcompress($xml);
            $bytes = "%PDF-1.7\n1 0 obj\n<< /Type /Filespec /F (document.isdoc) /EF << /F 2 0 R >> >>\nendobj\n"
                . '2 0 obj << /Type /EmbeddedFile /Filter /FlateDecode /Length ' . strlen($compressed) . ">>\nstream\n"
                . $compressed . "\nendstream\nendobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        }
        $gateway = $this->createMock(LlmGatewayInterface::class);
        $gateway->expects(self::never())->method('extractInvoice');
        $extractor = $this->rebuild($this->container->get(AiPdfExtractor::class), ['anthropic' => $gateway]);
        $action = $this->rebuild($this->container->get(AiExtractPdfAction::class), ['extractor' => $extractor]);
        $stream = new Stream(fopen('php://temp', 'w+'));
        $stream->write($bytes);
        $stream->rewind();
        $req = $this->request()->withUploadedFiles(['pdf' => new UploadedFile($stream, $source === 'isdocx' ? 'document.isdocx' : 'document.pdf', 'application/octet-stream', $stream->getSize(), UPLOAD_ERR_OK)]);
        $res = $action($req, new Response());
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $body = json_decode((string) $res->getBody(), true);
        self::assertSame($source, $body['source']);
        $invoice = $this->invoice((int) $body['purchase_invoice_id']);
        self::assertSame('draft', $invoice['status']);
        self::assertNull($invoice['paid_at']);
    }

    public static function structuredSources(): iterable
    {
        yield 'PDF with embedded ISDOC' => ['isdoc_embedded'];
        yield 'ISDOCX package' => ['isdocx'];
    }

    public function testStaffKeepsAutomaticPaidCardReceiptBehaviour(): void
    {
        $this->client($this->sid, 'Synthetic AI vendor');
        $this->container->get(\MyInvoice\Repository\CardClearingSettingsRepository::class)->save($this->sid, ['enabled' => true], $this->userId);
        $gateway = $this->createMock(LlmGatewayInterface::class);
        $gateway->expects(self::once())->method('extractInvoice')->willReturn(['ok' => true, 'data' => $this->data()]);
        $gateway->method('strongerModel')->willReturn(null);
        $extractor = $this->rebuild($this->container->get(AiPdfExtractor::class), ['anthropic' => $gateway]);
        $res = $extractor->extractAndCreate($this->sid, $this->userId, '%PDF-1.4 synthetic-staff-document');
        self::assertTrue($res['ok'], json_encode($res));
        self::assertSame('paid', $this->invoice((int) $res['purchase_invoice_id'])['status']);
    }

    public function testForeignBuyerIsRejectedInsteadOfBeingHandedToAnotherCompany(): void
    {
        $this->configure();
        $other = $this->createSupplier();
        $this->db->pdo()->prepare("UPDATE supplier SET ic = '87654321' WHERE id = ?")->execute([$other]);
        $gateway = $this->createMock(LlmGatewayInterface::class);
        $data = $this->data();
        $data['customer'] = ['ic' => '87654321'];
        $gateway->expects(self::once())->method('extractInvoice')->willReturn(['ok' => true, 'data' => $data]);
        $gateway->method('strongerModel')->willReturn(null);
        $extractor = $this->rebuild($this->container->get(AiPdfExtractor::class), ['anthropic' => $gateway]);
        $action = $this->rebuild($this->container->get(AiExtractPdfAction::class), ['extractor' => $extractor]);
        $res = $action($this->upload($this->request()), new Response());
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('wrong_tenant', json_decode((string) $res->getBody(), true)['error']['source'] ?? null);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id IN (' . $this->sid . ',' . $other . ')')->fetchColumn());
    }

    private function request(?EffectiveRole $role = null): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/ai-extract-pdf')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withAttribute('auth.effective_role', $role ?? new EffectiveRole(100, 'Client', 'client', true, (new PermissionCatalog())->legacyPreset('client')))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid);
    }

    private function enable(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET client_ai_extraction_enabled = 1 WHERE id = ?')->execute([$this->sid]);
    }

    private function configure(): void
    {
        $this->enable();
        $this->db->pdo()->prepare('UPDATE supplier SET gemini_api_key_enc = ? WHERE id = ?')->execute([
            $this->container->get(SecretEncryption::class)->encrypt('synthetic-fixture-key'), $this->sid,
        ]);
    }

    private function upload(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ServerRequestInterface
    {
        $stream = new Stream(fopen('php://temp', 'w+'));
        $stream->write('%PDF-1.4 synthetic-client-document');
        $stream->rewind();
        return $request->withUploadedFiles(['pdf' => new UploadedFile($stream, 'synthetic.pdf', 'application/pdf', $stream->getSize(), UPLOAD_ERR_OK)]);
    }

    /** @return array<string,mixed> */
    private function data(): array
    {
        return [
            'vendor' => ['company_name' => 'Synthetic AI vendor'], 'customer' => ['ic' => '12345679'],
            'document_kind' => 'receipt', 'vendor_invoice_number' => 'SYNTHETIC-CLIENT-1',
            'currency' => 'CZK', 'issue_date' => '2026-09-01', 'tax_date' => '2026-09-01', 'due_date' => '2026-09-01',
            'already_paid' => true, 'payment' => ['method' => 'card', 'method_confidence' => 1], 'card_last4' => '4242',
            'items' => [['description' => 'Synthetic service', 'quantity' => 1, 'unit_price_without_vat' => 121, 'vat_rate' => 21]],
            'total_without_vat' => 100, 'total_with_vat' => 121, 'unit_prices_include_vat' => true,
        ];
    }

    private function invoice(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** Reuse real dependencies, replacing only the AI gateway with a deterministic fake. */
    private function rebuild(object $service, array $replace): object
    {
        $reflection = new \ReflectionClass($service);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $args[] = array_key_exists($name, $replace) ? $replace[$name] : $reflection->getProperty($name)->getValue($service);
        }
        return $reflection->newInstanceArgs($args);
    }
}
