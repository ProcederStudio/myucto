<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth\Tokens;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa volitelného IP allowlistu API tokenu (session-only, jako zbytek /api/auth/tokens).
 *
 *   GET    /api/auth/tokens/{id}/ips
 *   POST   /api/auth/tokens/{id}/ips        { cidr, note? }
 *   DELETE /api/auth/tokens/{id}/ips/{ipId}
 *
 * Prázdný seznam znamená BEZ OMEZENÍ. Zúžení i rozšíření allowlistu je změna
 * bezpečnostního nastavení, takže obojí jde do `activity_log`.
 */
final class TokenIpRuleAction
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response, array $args): Response
    {
        $userId = self::userId($request);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        $tokenId = (int) ($args['id'] ?? 0);
        if ($tokenId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí ID tokenu.', 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        return Json::ok($response, [
            'rules' => $this->tokens->listIpRules($tokenId, $userId),
            'client_ip' => $ip,
            'client_ip_private' => $this->ipMatcher->isPrivateOrLocal($ip),
        ]);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $userId = self::userId($request);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        $tokenId = (int) ($args['id'] ?? 0);
        if ($tokenId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí ID tokenu.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $cidr = (string) ($body['cidr'] ?? '');
        $note = (string) ($body['note'] ?? '');

        try {
            $ruleId = $this->tokens->addIpRule($tokenId, $userId, $cidr, $note);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        if ($ruleId === null) {
            return Json::error($response, 'not_found', 'Token nenalezen nebo nepatří uživateli.', 404);
        }

        $this->logChange($request, $userId, $tokenId, 'api_token.ip_rule_added', [
            'rule_id' => $ruleId,
            'cidr'    => ApiTokenService::normalizeRule($cidr),
        ]);

        return Json::ok($response, ['rules' => $this->tokens->listIpRules($tokenId, $userId)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = self::userId($request);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        $tokenId = (int) ($args['id'] ?? 0);
        $ruleId  = (int) ($args['ipId'] ?? 0);
        if ($tokenId <= 0 || $ruleId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí ID tokenu nebo pravidla.', 400);
        }

        if (!$this->tokens->deleteIpRule($ruleId, $userId)) {
            return Json::error($response, 'not_found', 'Pravidlo nenalezeno nebo nepatří uživateli.', 404);
        }

        $this->logChange($request, $userId, $tokenId, 'api_token.ip_rule_removed', ['rule_id' => $ruleId]);

        return Json::ok($response, ['rules' => $this->tokens->listIpRules($tokenId, $userId)]);
    }

    private function logChange(Request $request, int $userId, int $tokenId, string $action, array $payload): void
    {
        $this->activity->log(
            $action,
            $userId,
            'api_token',
            $tokenId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
    }

    private static function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }
}
