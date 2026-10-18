<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\TripTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pravidelné cesty (šablony jízd knihy jízd):
 *   GET    /api/logbook/trip-templates
 *   POST   /api/logbook/trip-templates
 *   PUT    /api/logbook/trip-templates/{id}
 *   DELETE /api/logbook/trip-templates/{id}
 */
final class TripTemplatesAction
{
    private const UPDATABLE = ['car_id', 'label', 'origin', 'destination', 'purpose', 'distance_km',
        'category_id', 'time_start', 'time_end', 'display_order', 'is_archived'];

    public function __construct(
        private readonly TripTemplateRepository $repo,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TenantReferenceGuard $tenantRefs,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $includeArchived = !empty($request->getQueryParams()['include_archived']);
        return Json::ok($response, $this->repo->listForTenant($supplierId, $includeArchived));
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if ($err = $this->validate($supplierId, $body)) {
            return Json::error($response, 'validation_failed', $err, 400);
        }
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }
        $id = $this->repo->create($supplierId, $body, $this->userId($request));
        $this->log($request, 'trip_template.created', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $current = $this->repo->find($id, $supplierId);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Pravidelná cesta nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        // Vynechaný klíč = ponechat uloženou hodnotu; poslaný null / "" = vymazat.
        $merged = $body + array_intersect_key($current, array_flip(self::UPDATABLE));
        if ($err = $this->validate($supplierId, $merged)) {
            return Json::error($response, 'validation_failed', $err, 400);
        }
        if ($ref = $this->tenantRefError($supplierId, $merged)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }
        $this->repo->update($id, $supplierId, $merged);
        $this->log($request, 'trip_template.updated', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if (!$this->repo->delete($id, $supplierId)) {
            return Json::error($response, 'not_found', 'Pravidelná cesta nenalezena.', 404);
        }
        $this->log($request, 'trip_template.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function validate(int $supplierId, array $body): ?string
    {
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) return 'Název je povinný, max 120 znaků.';
        $distance = (float) ($body['distance_km'] ?? 0);
        if ($distance <= 0 || $distance > 100000) return 'Ujeté km musí být kladné číslo.';
        $carId = (int) ($body['car_id'] ?? 0);
        if ($carId > 0 && $this->cars->find($carId, $supplierId) === null) return 'Auto neexistuje.';
        foreach (['time_start', 'time_end'] as $key) {
            $time = trim((string) ($body[$key] ?? ''));
            if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
                return 'Neplatný čas (formát HH:MM).';
            }
        }
        return null;
    }

    private function tenantRefError(int $supplierId, array $body): ?string
    {
        $badRefs = $this->tenantRefs->violations($supplierId, $body, ['category_id']);

        return $badRefs !== [] ? TenantReferenceGuard::message($badRefs) : null;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'trip_template', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
