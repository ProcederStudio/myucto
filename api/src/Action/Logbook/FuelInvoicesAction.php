<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Repository\FuelScanRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\FuelInvoiceScanner;
use MyInvoice\Service\Logbook\Fuel\FuelKeywords;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tankování z přijatých faktur od benzínek (is_fuel_station):
 *   GET  /api/logbook/fuel-invoices                  — přehled faktur od benzínek
 *   GET  /api/logbook/fuel-invoices/{id}/items       — položky faktury (detail)
 *   POST /api/logbook/fuel-invoices/{id}/assign      — vytěžit + přiřadit autu
 *   POST /api/logbook/fuel-invoices/backfill         — zpětné dávkové vytěžení historie
 */
final class FuelInvoicesAction
{
    public function __construct(
        private readonly FuelScanRepository $scans,
        private readonly FuelInvoiceScanner $scanner,
        private readonly FuelingRepository $fuelings,
        private readonly CarRepository $cars,
        private readonly PurchaseInvoiceRepository $invoices,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** Položky faktury (detail pro přehled „Načíst z faktur"). */
    public function items(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::READ)) {
            return Json::error($response, 'forbidden_permission', 'Tankování z faktur vyžaduje právo číst přijaté faktury.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $invoiceId = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($invoiceId, $supplierId);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $items = array_map(static function (array $it): array {
            $desc = (string) ($it['description'] ?? '');
            return [
                'description'       => $desc,
                'quantity'          => isset($it['quantity']) && $it['quantity'] !== null ? (float) $it['quantity'] : null,
                'unit'              => (string) ($it['unit'] ?? ''),
                'total_without_vat' => isset($it['total_without_vat']) ? (float) $it['total_without_vat'] : null,
                'total_with_vat'    => isset($it['total_with_vat']) ? (float) $it['total_with_vat'] : null,
                'is_fuel'           => $desc !== '' && FuelKeywords::isFuel($desc),
            ];
        }, is_array($invoice['items'] ?? null) ? $invoice['items'] : []);

        return Json::ok($response, [
            'id'             => $invoiceId,
            'vendor_name'    => (string) ($invoice['vendor_company_name'] ?? ''),
            'issue_date'     => $invoice['issue_date'] ?? null,
            'currency'       => (string) ($invoice['currency'] ?? 'CZK'),
            'total_with_vat' => (float) ($invoice['total_with_vat'] ?? 0),
            'items'          => $items,
        ]);
    }

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::READ)) {
            return Json::error($response, 'forbidden_permission', 'Tankování z faktur vyžaduje právo číst přijaté faktury.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $filters = [];
        if (!empty($q['year'])) $filters['year'] = (int) $q['year'];
        if (!empty($q['only_unscanned'])) $filters['only_unscanned'] = true;
        // Nespárované faktury zobraz všechny, spárované jen posledních 10.
        $filters['scanned_limit'] = isset($q['scanned_limit']) ? max(0, (int) $q['scanned_limit']) : 10;
        $invoices = $this->scans->listFuelStationInvoices($supplierId, $filters);
        return Json::ok($response, [
            'invoices'  => $invoices,
            'cars'      => $this->cars->listForTenant($supplierId, false),
            'has_cars'  => $this->cars->countActive($supplierId) > 0,
        ]);
    }

    public function assign(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::READ)) {
            return Json::error($response, 'forbidden_permission', 'Tankování z faktur vyžaduje právo číst přijaté faktury.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $invoiceId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        // Jediná hodnota akce: vynechaný car_id nesmí tiše odebrat auto, vymazává jen explicitní null.
        if (!array_key_exists('car_id', $body)) {
            return Json::error($response, 'validation_failed', 'Pole car_id je povinné (null = odebrat auto).', 400);
        }
        $carId = $body['car_id'] !== '' && $body['car_id'] !== null ? (int) $body['car_id'] : null;
        if ($carId !== null && $this->cars->find($carId, $supplierId) === null) {
            return Json::error($response, 'car_not_found', 'Auto neexistuje.', 404);
        }

        // force=true → i už vytěženou fakturu znovu projede (doplní dříve chybějící litry).
        $result = $this->scanner->scanInvoice($supplierId, $invoiceId, $carId, $this->userId($request), true);
        if (empty($result['ok'])) {
            return Json::error($response, 'scan_failed', (string) ($result['error'] ?? 'Vytěžení selhalo.'), 400);
        }
        // Reassign (i pro již dříve vytěženou fakturu) — přepíše auto u všech jejích tankování.
        $reassigned = $this->fuelings->reassignByInvoice($supplierId, $invoiceId, $carId);

        $this->log($request, 'fuel_invoice.assigned', $invoiceId, ['car_id' => $carId, 'result' => $result]);
        return Json::ok($response, $result + ['reassigned' => $reassigned]);
    }

    public function backfill(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::READ)) {
            return Json::error($response, 'forbidden_permission', 'Tankování z faktur vyžaduje právo číst přijaté faktury.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $limit = isset($body['limit']) ? max(1, min(100, (int) $body['limit'])) : 25;

        $report = $this->scanner->backfill($supplierId, $this->userId($request), $limit);
        $this->log($request, 'fuel_invoices.backfill', 0, [
            'processed' => $report['processed'], 'created' => $report['created'], 'remaining' => $report['remaining'],
        ]);
        return Json::ok($response, $report);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'fuel_invoice', $id ?: null, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
