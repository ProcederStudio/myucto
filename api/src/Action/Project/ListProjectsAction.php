<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ProjectRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListProjectsAction
{
    public function __construct(
        private readonly ProjectRepository $repo,
        private readonly ClientRepository $clients,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $clientId = isset($args['client_id']) ? (int) $args['client_id'] : null;
        if ($clientId !== null) {
            $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
            $client = $this->clients->find($clientId);
            if ($client === null || (int) ($client['supplier_id'] ?? 0) !== $sid) {
                return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
            }
            return Json::ok($response, ['data' => $this->repo->listForClient($clientId)]);
        }

        $q = $request->getQueryParams();
        $filters = [
            'status'      => isset($q['filter']['status']) ? (string) $q['filter']['status'] : null,
            'client_id'   => isset($q['filter']['client_id']) ? (int) $q['filter']['client_id'] : null,
            'supplier_id' => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
        ];
        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.projects_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));
        $includeInvoices = RequestAuthorization::allows($request, 'invoices');
        $sort = (string) ($q['sort'] ?? 'name');

        if (!$includeInvoices && in_array($sort, ['revenue', 'last_activity'], true)) $sort = 'name';
        return Json::ok($response, $this->repo->listAll($filters, $page, $perPage, $sort, $includeInvoices));
    }
}
