<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\PublicInvoiceGetAction;
use MyInvoice\Action\Invoice\PublicInvoiceIsdocAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use MyInvoice\Tests\Support\StandaloneIsdocTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicInvoiceIsdocActionTest extends TestCase
{
    use StandaloneIsdocTestTrait;

    protected function setUp(): void { $this->setupIsdocDatabase(); }

    public static function deniedRequests(): iterable
    {
        yield 'feature disabled' => [false, true, true, 'issued', true, true];
        yield 'malformed token' => [true, false, true, 'issued', true, true];
        yield 'revoked token' => [true, true, false, 'issued', true, true];
        yield 'wrong tenant' => [true, true, true, 'issued', false, true];
        yield 'supplier disabled' => [true, true, true, 'issued', true, false];
        yield 'draft' => [true, true, true, 'draft', true, true];
        yield 'cancelled' => [true, true, true, 'cancelled', true, true];
    }

    #[DataProvider('deniedRequests')]
    public function testUnavailableDocumentIsNeverExported(bool $feature, bool $tokenValid, bool $found, string $status, bool $tenant, bool $enabled): void
    {
        if ($enabled) $this->pdo->exec('UPDATE supplier SET public_isdoc_enabled = 1 WHERE id = 41');
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects($feature && $tokenValid ? self::once() : self::never())->method('findByPublicToken')
            ->willReturn($found ? $this->isdocInvoice(['status' => $status]) : null);
        $repo->expects(self::never())->method('markPublicViewed');
        $signing = $this->createMock(PdfSigningService::class);
        $signing->expects(self::never())->method('signIsdocIfEnabled');
        $guard = $this->createStub(PublicTenantGuard::class);
        $guard->method('allows')->willReturn($tenant);
        $action = new PublicInvoiceIsdocAction($repo, $this->isdocService(signing: $signing), $this->delivery,
            $this->createStub(ActivityLogger::class), $this->createStub(IpMatcher::class), $guard,
            new InvoicePublicLinkFeature(new Config(['invoices' => ['public_links' => $feature]])));
        $response = $action((new ServerRequestFactory())->createServerRequest('GET', '/'),
            (new ResponseFactory())->createResponse(), ['token' => $tokenValid ? str_repeat('a', 48) : '../bad']);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDownloadReturnsSchemaValidXmlAndRecordsAnonymousView(): void
    {
        $this->pdo->exec('UPDATE supplier SET public_isdoc_enabled = 1 WHERE id = 41');
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('findByPublicToken')->with(str_repeat('a', 48))->willReturn($this->isdocInvoice());
        $repo->expects(self::once())->method('markPublicViewed')->with(91);
        $guard = $this->createMock(PublicTenantGuard::class);
        $guard->expects(self::once())->method('allows')->with(self::anything(), 41)->willReturn(true);
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())->method('log')->with('invoice.public_isdoc_downloaded', null, 'invoice', 91, [], '', '', 41);
        $action = new PublicInvoiceIsdocAction($repo, $this->isdocService(), $this->delivery,
            $logger, $this->createStub(IpMatcher::class), $guard,
            new InvoicePublicLinkFeature(new Config(['invoices' => ['public_links' => true]])));
        $response = $action((new ServerRequestFactory())->createServerRequest('GET', '/'),
            (new ResponseFactory())->createResponse(), ['token' => str_repeat('a', 48)]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="Faktura-TEST-2026-91.isdoc"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertIsdocSchema((string) $response->getBody());
    }

    public function testSigningFailureDoesNotExposeDetailsOrMarkViewed(): void
    {
        $this->pdo->exec('UPDATE supplier SET public_isdoc_enabled = 1 WHERE id = 41');
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findByPublicToken')->willReturn($this->isdocInvoice());
        $repo->expects(self::never())->method('markPublicViewed');
        $guard = $this->createStub(PublicTenantGuard::class);
        $guard->method('allows')->willReturn(true);
        $signing = $this->createStub(PdfSigningService::class);
        $signing->method('signIsdocIfEnabled')->willThrowException(new \RuntimeException('private diagnostic path'));
        $action = new PublicInvoiceIsdocAction($repo, $this->isdocService(signing: $signing), $this->delivery,
            $this->createStub(ActivityLogger::class), $this->createStub(IpMatcher::class), $guard,
            new InvoicePublicLinkFeature(new Config(['invoices' => ['public_links' => true]])));
        $response = $action((new ServerRequestFactory())->createServerRequest('GET', '/'),
            (new ResponseFactory())->createResponse(), ['token' => str_repeat('a', 48)]);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('private diagnostic path', (string) $response->getBody());
    }

    public function testPublicPayloadExposesOnlyAvailability(): void
    {
        $payload = PublicInvoiceGetAction::buildPayload($this->isdocInvoice(), ['public_isdoc_enabled' => 1, 'email_attach_isdoc' => 1], [], null, null, isdocAvailable: true);
        self::assertTrue($payload['isdoc_available']);
        self::assertArrayNotHasKey('public_isdoc_enabled', $payload['supplier']);
        self::assertArrayNotHasKey('email_attach_isdoc', $payload['supplier']);
        self::assertFalse(PublicInvoiceGetAction::buildPayload($this->isdocInvoice(), [], [], null, null)['isdoc_available']);
    }
}
