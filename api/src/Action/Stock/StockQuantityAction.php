<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\InTransitService;
use MyInvoice\Service\Stock\ReplenishmentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Odvozená množství (Epic SKLAD „na cestě", §5.4 + §11.4) — vše READ-ONLY.
 *
 *   GET /api/stock/quantities     — skladem / rezervováno / na cestě / u dodavatele
 *   GET /api/stock/in-transit     — „na cestě" s rozpadem na objednávky
 *   GET /api/stock/reservations   — „rezervováno" s rozpadem na faktury
 *   GET /api/stock/replenishment  — „co objednat"
 *
 * Stávající `GET /api/stock/availability` zůstává BEZE ZMĚNY (používá ho editor
 * faktury i MCP) — `/quantities` je jeho nadmnožina, ne náhrada.
 *
 * Rozhodnutí #12: karta bez jediného pohybu MUSÍ vrátit řádek s nulami. Kdyby
 * `/quantities` u nové karty vracelo prázdno, nešlo by v katalogu založit produkt,
 * který zatím jen víme, kdo ho nabízí — a to je celý smysl fáze 3.
 */
final class StockQuantityAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const MAX_ITEMS = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly InTransitService $quantities,
        private readonly ReplenishmentService $replenishment,
    ) {}

    public function quantities(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q     = $request->getQueryParams();
        $items = $this->quantities->quantities(
            $supplierId,
            self::itemIds($q),
            self::warehouseId($q),
            self::MAX_ITEMS,
        );

        if (RequestAuthorization::isClientType($request)) {
            $items = array_map(static function (array $item): array {
                unset($item['in_transit_orders'], $item['vendor_offers']);
                return $item;
            }, $items);
        }
        return Json::ok($response, ['items' => $items, 'total' => count($items)]);
    }

    public function inTransit(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q     = $request->getQueryParams();
        $items = $this->quantities->inTransit($supplierId, self::itemIds($q), self::warehouseId($q));

        return Json::ok($response, ['items' => $items, 'total' => count($items)]);
    }

    public function reservations(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q     = $request->getQueryParams();
        $items = $this->quantities->reservations($supplierId, self::itemIds($q), self::warehouseId($q));

        return Json::ok($response, ['items' => $items, 'total' => count($items)]);
    }

    public function replenishment(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q      = $request->getQueryParams();
        $limit  = max(1, min(500, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $result = $this->replenishment->suggest($supplierId, [
            'warehouse_id' => self::warehouseId($q),
            'below_min'    => !empty($q['below_min']),
            'item_ids'     => self::itemIds($q),
            'coefficient'  => isset($q['coefficient']) ? (float) $q['coefficient'] : null,
            'limit'        => $limit,
            'offset'       => $offset,
        ]);

        return Json::ok($response, [
            'items'  => $result['items'],
            'total'  => $result['total'],
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * @param array<string,mixed> $q
     * @return list<int>
     */
    private static function itemIds(array $q): array
    {
        $raw = (string) ($q['item_ids'] ?? '');
        if (trim($raw) === '') {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn (int $v): bool => $v > 0,
        ))), 0, self::MAX_ITEMS);
    }

    /** @param array<string,mixed> $q */
    private static function warehouseId(array $q): ?int
    {
        return !empty($q['warehouse_id']) ? (int) $q['warehouse_id'] : null;
    }
}
