<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Zápis do `api_request_log` — per-request stopa volání veřejného API bearer tokenem.
 *
 * Vzniklo kvůli MCP serveru: agent volá API sám za uživatele, takže bez tohohle
 * logu není z aplikace vidět, CO se přes token dělo (`api_tokens.last_used_at` je
 * navíc throttlovaný na 5 minut).
 *
 * Zápis je BEST-EFFORT: selhání logu nesmí shodit obsloužený request. Chyba se
 * proto jen zapíše do aplikačního logu a jede se dál.
 */
final class ApiRequestLogger
{
    private const MAX_ROUTE = 255;
    private const MAX_QUERY = 512;
    private const MAX_TOOL  = 96;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array{
     *   token_id?: int|null, user_id?: int|null, supplier_id?: int|null,
     *   ip?: string, method?: string, route?: string, query?: string,
     *   status?: int, duration_ms?: int, scope_used?: string,
     *   client?: string, client_version?: string, tool?: string, error_code?: string
     * } $entry
     */
    public function log(array $entry): void
    {
        try {
            $packedIp = @inet_pton((string) ($entry['ip'] ?? ''));

            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO api_request_log
                    (token_id, user_id, supplier_id, ip, method, route, query, status,
                     duration_ms, scope_used, client, client_version, tool, error_code)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                ($entry['token_id'] ?? null) ?: null,
                ($entry['user_id'] ?? null) ?: null,
                ($entry['supplier_id'] ?? null) ?: null,
                $packedIp !== false ? $packedIp : null,
                mb_substr(strtoupper((string) ($entry['method'] ?? '')), 0, 10),
                mb_substr((string) ($entry['route'] ?? ''), 0, self::MAX_ROUTE),
                mb_substr((string) ($entry['query'] ?? ''), 0, self::MAX_QUERY),
                (int) ($entry['status'] ?? 0),
                max(0, (int) ($entry['duration_ms'] ?? 0)),
                mb_substr((string) ($entry['scope_used'] ?? ''), 0, 16),
                mb_substr((string) ($entry['client'] ?? ''), 0, 64),
                mb_substr((string) ($entry['client_version'] ?? ''), 0, 32),
                mb_substr((string) ($entry['tool'] ?? ''), 0, self::MAX_TOOL),
                mb_substr((string) ($entry['error_code'] ?? ''), 0, 64),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('api_request_log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Výpis logu pro daného uživatele (jen jeho vlastní tokeny) s filtry a stránkováním.
     *
     * Řádky po smazaném tokenu (`token_id` = NULL kvůli FK SET NULL) se do výpisu
     * nedostanou — nešlo by ověřit, komu patřily. Vlastní `user_id` na řádku je
     * proto druhá vazba, přes kterou historii vidí i po revokaci tokenu.
     *
     * @param array{token_id?:int,method?:string,route?:string,client?:string,only_errors?:bool} $filter
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function listForUser(int $userId, array $filter = [], int $limit = 50, int $offset = 0): array
    {
        return $this->listEntries($userId, $filter, $limit, $offset);
    }

    /**
     * Výpis logu všech uživatelů instance i se jménem uživatele (volitelně filtr
     * `user_id`). Jen pro superadmina, bránu drží volající akce.
     *
     * @param array{user_id?:int,token_id?:int,method?:string,route?:string,client?:string,only_errors?:bool} $filter
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function listAll(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $userId = (int) ($filter['user_id'] ?? 0);
        return $this->listEntries($userId > 0 ? $userId : null, $filter, $limit, $offset);
    }

    /** @return array{rows: list<array<string,mixed>>, total: int} */
    private function listEntries(?int $userId, array $filter, int $limit, int $offset): array
    {
        $where  = $userId !== null ? ['l.user_id = :uid'] : ['1 = 1'];
        $params = $userId !== null ? [':uid' => $userId] : [];

        if (!empty($filter['token_id'])) {
            $where[] = 'l.token_id = :tid';
            $params[':tid'] = (int) $filter['token_id'];
        }
        if (!empty($filter['method'])) {
            $where[] = 'l.method = :method';
            $params[':method'] = strtoupper((string) $filter['method']);
        }
        if (!empty($filter['route'])) {
            $where[] = 'l.route LIKE :route';
            $params[':route'] = '%' . addcslashes((string) $filter['route'], '%_\\') . '%';
        }
        if (!empty($filter['client'])) {
            $where[] = 'l.client = :client';
            $params[':client'] = (string) $filter['client'];
        }
        if (!empty($filter['only_errors'])) {
            $where[] = 'l.status >= 400';
        }

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $pdo = $this->db->pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM api_request_log l' . $sqlWhere);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $stmt = $pdo->prepare(
            'SELECT l.id, l.token_id, t.name AS token_name, l.supplier_id, l.ts, l.ip,
                    l.method, l.route, l.query, l.status, l.duration_ms, l.scope_used,
                    l.client, l.client_version, l.tool, l.error_code,
                    l.user_id, u.name AS user_name, u.email AS user_email,
                    (g.id IS NOT NULL) AS via_oauth, c.client_name AS oauth_client
               FROM api_request_log l
               LEFT JOIN api_tokens t ON t.id = l.token_id
               LEFT JOIN users u ON u.id = l.user_id
               LEFT JOIN mcp_oauth_grants g ON g.access_token_id = l.token_id
               LEFT JOIN mcp_oauth_clients c ON c.client_id = g.client_id'
            . $sqlWhere .
            ' ORDER BY l.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['id']          = (int) $r['id'];
            $r['token_id']    = $r['token_id'] !== null ? (int) $r['token_id'] : null;
            $r['user_id']     = $r['user_id'] !== null ? (int) $r['user_id'] : null;
            $r['via_oauth']   = (bool) $r['via_oauth'];
            $r['supplier_id'] = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
            $r['status']      = (int) $r['status'];
            $r['duration_ms'] = (int) $r['duration_ms'];
            $r['ip']          = $r['ip'] !== null ? (@inet_ntop($r['ip']) ?: null) : null;
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
