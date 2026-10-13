<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth\Tokens;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ApiRequestLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/auth/api-log — výpis volání veřejného API vlastními tokeny.
 *
 * Session-only (není v `ApiScopeMiddleware::BEARER_ALLOWED`) — kdo si čte log,
 * má být přihlášený člověk, ne integrace. Uživatel vidí výhradně řádky svých
 * vlastních tokenů, filtr `user_id` je v repository napevno. Superadmin s `all=1`
 * vidí log všech uživatelů instance i se jménem uživatele (volitelně `user_id`).
 *
 * Query: all, user_id, token_id, method, route, client, only_errors, limit (1–200), offset
 */
final class ApiRequestLogAction
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ApiRequestLogger $log,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $q = $request->getQueryParams();

        $method = strtoupper(trim((string) ($q['method'] ?? '')));
        if (!in_array($method, self::METHODS, true)) {
            $method = '';
        }

        $filter = [
            'token_id'    => (int) ($q['token_id'] ?? 0),
            'method'      => $method,
            'route'       => mb_substr(trim((string) ($q['route'] ?? '')), 0, 120),
            'client'      => mb_substr(trim((string) ($q['client'] ?? '')), 0, 64),
            'only_errors' => filter_var($q['only_errors'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        $limit  = (int) ($q['limit'] ?? 50);
        $offset = (int) ($q['offset'] ?? 0);

        $all = filter_var($q['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($all && !RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Log všech uživatelů vidí jen správce instance.', 403);
        }
        $result = $all
            ? $this->log->listAll($filter + ['user_id' => (int) ($q['user_id'] ?? 0)], $limit, $offset)
            : $this->log->listForUser($userId, $filter, $limit, $offset);

        return Json::ok($response, [
            'entries' => $result['rows'],
            'total'   => $result['total'],
            'limit'   => max(1, min(200, $limit)),
            'offset'  => max(0, $offset),
        ]);
    }
}
