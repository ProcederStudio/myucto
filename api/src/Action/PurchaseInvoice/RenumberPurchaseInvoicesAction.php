<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceRenumberService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET  /api/purchase-invoices/renumber?period=2026-10  náhled přečíslování měsíce
 * POST /api/purchase-invoices/renumber {period}        přečíslování podle data vystavení
 *
 * Interní plumbing (není ve veřejném API). Klient přečíslovat nesmí, interní čísla
 * spravuje účetní.
 */
final class RenumberPurchaseInvoicesAction
{
    public function __construct(
        private readonly PurchaseInvoiceRenumberService $service,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Přečíslování dokladů je vyhrazeno účetní.', 403);
        }
        $period = (string) ($request->getQueryParams()['period'] ?? '');
        try {
            return Json::ok($response, $this->service->preview(SupplierGuard::currentId($request), $period));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function renumber(Request $request, Response $response): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Přečíslování dokladů je vyhrazeno účetní.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            return Json::ok($response, $this->service->renumber(
                SupplierGuard::currentId($request),
                (string) ($body['period'] ?? ''),
                isset($user['id']) ? (int) $user['id'] : null,
            ));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\DomainException $e) {
            return Json::error($response, 'period_locked', $e->getMessage(), 409);
        }
    }
}
