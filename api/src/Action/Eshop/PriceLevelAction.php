<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockPriceLevelRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockPriceLevelService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cenové hladiny odběratelů (Bronze / Silver / Gold, migrace 1833).
 *
 *   GET/POST /api/eshop/price-levels ; GET/PUT/DELETE /api/eshop/price-levels/{id}
 *   GET/PUT  /api/eshop/price-levels/{id}/rules — celá sada pravidel hladiny
 *
 * Kód hladiny není cizí klíč (odběratel drží id), takže jde měnit volně. Hladinu
 * přiřazenou odběratelům nejde smazat; místo toho ji lze deaktivovat — odběratelé
 * se pak naceňují jako bez hladiny.
 */
final class PriceLevelAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockPriceLevelRepository $levels,
        private readonly StockPriceLevelService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        return Json::ok($response, $this->levels->listForSupplier($supplierId, !empty($q['active'])));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $row = $this->levels->find($supplierId, (int) $args['id']);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Cenová hladina nenalezena.', 404);
        }
        return Json::ok($response, $row);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        [$data, $verr] = $this->validate($response, (array) ($request->getParsedBody() ?? []));
        if ($verr !== null) {
            return $verr;
        }
        if ($this->levels->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'price_level_code_taken', 'Cenová hladina s tímto kódem už existuje.', 409);
        }
        $id = $this->levels->insert($supplierId, $data);
        $this->log($request, 'eshop.price_level_created', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->levels->find($supplierId, $id) ?? [], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $existing = $this->levels->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Cenová hladina nenalezena.', 404);
        }
        [$data, $verr] = $this->validate($response, (array) ($request->getParsedBody() ?? []), $existing);
        if ($verr !== null) {
            return $verr;
        }
        $byCode = $this->levels->findByCode($supplierId, $data['code']);
        if ($byCode !== null && $byCode['id'] !== $id) {
            return Json::error($response, 'price_level_code_taken', 'Cenová hladina s tímto kódem už existuje.', 409);
        }
        $this->levels->update($supplierId, $id, $data);
        $this->log($request, 'eshop.price_level_updated', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->levels->find($supplierId, $id) ?? []);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $existing = $this->levels->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Cenová hladina nenalezena.', 404);
        }
        if ($existing['client_count'] > 0) {
            return Json::error(
                $response,
                'price_level_in_use',
                'Cenovou hladinu mají přiřazenou odběratelé — nelze ji smazat. Deaktivujte ji místo mazání.',
                409,
                ['client_count' => $existing['client_count'], 'suggestion' => 'deactivate'],
            );
        }
        $this->levels->delete($supplierId, $id);
        $this->log($request, 'eshop.price_level_deleted', $id, ['code' => $existing['code']]);
        return Json::ok($response, ['deleted' => true]);
    }

    public function rules(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            return Json::ok($response, $this->service->rules($supplierId, (int) $args['id']));
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
    }

    public function replaceRules(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        try {
            $saved = $this->service->replaceRules($supplierId, $id, $request->getParsedBody());
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
        $this->log($request, 'eshop.price_level_rules_updated', $id, ['count' => count($saved)]);
        return Json::ok($response, $saved);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array{code:string, name:string, default_discount_pct:string, is_active:bool, display_order:int}, 1:?Response}
     */
    private function validate(Response $response, array $body, ?array $existing = null): array
    {
        $code = trim((string) ($body['code'] ?? $existing['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? $existing['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 50 || preg_match('/\s/u', $code) === 1) {
            return [[], Json::error($response, 'validation_failed', 'Kód cenové hladiny je povinný (max 50 znaků, bez mezer).', 400)];
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return [[], Json::error($response, 'validation_failed', 'Název cenové hladiny je povinný (max 100 znaků).', 400)];
        }
        $discount = array_key_exists('default_discount_pct', $body)
            ? StockPriceLevelService::adjustmentPct($body['default_discount_pct'] ?? '0')
            : (string) ($existing['default_discount_pct'] ?? '0.000');
        if ($discount === null) {
            return [[], Json::error($response, 'validation_failed', 'Výchozí hodnota hladiny: ' . StockPriceLevelService::ADJUSTMENT_MESSAGE, 400)];
        }
        return [[
            'code'                 => $code,
            'name'                 => $name,
            'default_discount_pct' => $discount,
            'is_active'            => array_key_exists('is_active', $body) ? (bool) $body['is_active'] : (bool) ($existing['is_active'] ?? true),
            'display_order'        => array_key_exists('display_order', $body) ? (int) $body['display_order'] : (int) ($existing['display_order'] ?? 0),
        ], null];
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_price_level',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
