<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Reports\ProjectProfitService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výsledovka po zakázkách (issue #29).
 *
 *   GET /api/projects/profitability            — všechny zakázky: výnos, náklad, marže
 *   GET /api/projects/{id}/profit              — jedna zakázka + její doklady
 *
 * Volitelné filtry: `date_from`, `date_to` (YYYY-MM-DD), `include_archived=1`
 * (jen v přehledu). Neplatné datum se tiše ignoruje — sestava je manažerský
 * přehled, ne daňové podání, takže překlep ve filtru nemá shodit stránku.
 */
final class ProjectProfitAction
{
    public function __construct(private readonly ProjectProfitService $profit) {}

    public function overview(Request $request, Response $response): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden_permission', 'Ekonomika zakázky je dostupná pouze interním rolím.', 403);
        }
        $q = $request->getQueryParams();
        return Json::ok($response, $this->profit->overview(self::supplierId($request), [
            'date_from'        => $q['date_from'] ?? null,
            'date_to'          => $q['date_to'] ?? null,
            'include_archived' => !empty($q['include_archived']),
        ]));
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden_permission', 'Ekonomika zakázky je dostupná pouze interním rolím.', 403);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID zakázky.', 400);
        }
        $q = $request->getQueryParams();
        $result = $this->profit->detail(self::supplierId($request), $id, [
            'date_from' => $q['date_from'] ?? null,
            'date_to'   => $q['date_to'] ?? null,
        ]);
        if ($result === null) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }
        return Json::ok($response, $result);
    }

    private static function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }
}
