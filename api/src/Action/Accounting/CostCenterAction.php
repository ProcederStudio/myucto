<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CostCenterAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly CostCenterRepository $costCenters,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $includeInactive = !empty($request->getQueryParams()['include_inactive']);
        return Json::ok($response, $this->costCenters->listForSupplier($supplierId, $includeInactive));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($body['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 50 || preg_match('/[\x00-\x1F\x7F]/u', $code)) {
            return Json::error($response, 'validation_failed', 'Kód střediska musí mít 1–50 znaků a nesmí obsahovat řídicí znaky.', 422);
        }
        if ($name === '' || mb_strlen($name) > 255) {
            return Json::error($response, 'validation_failed', 'Název střediska musí mít 1–255 znaků.', 422);
        }
        if ($this->costCenters->findByCode($supplierId, $code) !== null) {
            return Json::error($response, 'duplicate_cost_center', 'Středisko s tímto kódem už existuje.', 409);
        }

        $id = $this->costCenters->create($supplierId, $code, $name);
        $this->log($request, 'accounting.cost_center_created', $id, ['code' => $code]);
        return Json::ok($response, $this->costCenters->find($supplierId, $id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        if ($this->costCenters->find($supplierId, $id) === null) {
            return Json::error($response, 'not_found', 'Středisko nenalezeno.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $changes = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '' || mb_strlen($name) > 255) {
                return Json::error($response, 'validation_failed', 'Název střediska musí mít 1–255 znaků.', 422);
            }
            $changes['name'] = $name;
        }
        if (array_key_exists('is_active', $body)) {
            $changes['is_active'] = (bool) $body['is_active'];
        }
        $this->costCenters->update($supplierId, $id, $changes);
        $this->log($request, 'accounting.cost_center_updated', $id, ['fields' => array_keys($changes)]);
        return Json::ok($response, $this->costCenters->find($supplierId, $id));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->costCenters->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Středisko nenalezeno.', 404);
        }

        $used = $this->costCenters->hasUsage($supplierId, (string) $existing['code']);
        try {
            $ok = $used
                ? $this->costCenters->deactivate($supplierId, $id)
                : $this->costCenters->delete($supplierId, $id);
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (!$ok) {
            return Json::error($response, 'not_found', 'Středisko nenalezeno.', 404);
        }
        $this->log($request, $used ? 'accounting.cost_center_deactivated' : 'accounting.cost_center_deleted', $id, [
            'code' => $existing['code'],
        ]);
        return Json::ok($response, ['deleted' => !$used]);
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'cost_center',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
