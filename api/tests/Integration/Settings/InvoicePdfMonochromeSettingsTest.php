<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\ClientBrandingSettingsAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Volba „Úsporná černobílá faktura" (`supplier.invoice_pdf_monochrome`): ve výchozím
 * stavu vypnutá, ukládá se přes nastavení firmy i přes úzký brandingový výřez
 * (stránka Branding ji posílá oběma cestami) a týká se jen dané firmy.
 */
#[Group('integration')]
final class InvoicePdfMonochromeSettingsTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testOptionIsOffByDefaultAndRoundTripsPerSupplier(): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $settings = $container->get(SettingsAction::class);
        $branding = $container->get(ClientBrandingSettingsAction::class);
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, 1);
            $otherId = $this->createIsolatedSupplier($pdo, 1);
            $pdo->exec("UPDATE supplier SET invoice_pdf_monochrome = DEFAULT WHERE id IN ({$supplierId}, {$otherId})");
            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);

            self::assertFalse($this->read($settings->getSupplier($request, new Response()))['invoice_pdf_monochrome']);
            self::assertFalse($this->read($branding->get($request, new Response()))['invoice_pdf_monochrome']);

            $response = $settings->updateSupplier($request->withParsedBody(['invoice_pdf_monochrome' => true]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertTrue($this->read($settings->getSupplier($request, new Response()))['invoice_pdf_monochrome']);

            $response = $branding->update($request->withParsedBody(['invoice_pdf_monochrome' => false]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertFalse($this->read($response)['invoice_pdf_monochrome']);

            $response = $branding->update($request->withParsedBody(['invoice_pdf_monochrome' => true]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertTrue($this->read($response)['invoice_pdf_monochrome']);

            $other = $request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $otherId);
            self::assertFalse($this->read($settings->getSupplier($other, new Response()))['invoice_pdf_monochrome']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    /** @return array<string,mixed> */
    private function read(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return (array) ($data['data'] ?? $data);
    }
}
