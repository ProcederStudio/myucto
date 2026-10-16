<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;

/** Jeden pokus spotřebuje jednotku před vytěžením, včetně chyb a strukturovaných dokladů. */
final class ClientAiExtractionQuota
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{period:string,limit:int,used:int,remaining:int} */
    public function usage(int $supplierId, ?\DateTimeImmutable $now = null): array
    {
        $period = $this->period($now);
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.client_ai_extraction_monthly_limit AS quota_limit, COALESCE(u.used, 0) AS used
               FROM supplier s LEFT JOIN client_ai_extraction_usage u ON u.supplier_id = s.id AND u.period = ?
              WHERE s.id = ?'
        );
        $stmt->execute([$period, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return $this->state($period, (int) ($row['quota_limit'] ?? 0), (int) ($row['used'] ?? 0));
    }

    /**
     * Supplier lock serializuje rezervace i změnu limitu. Transakce končí před AI voláním.
     * NULL znamená vyčerpaný nebo nulový limit; rezervace se po chybě nevrací.
     * @return array{period:string,limit:int,used:int,remaining:int}|null
     */
    public function reserve(int $supplierId, ?\DateTimeImmutable $now = null): ?array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Client AI quota must be reserved outside an existing transaction.');
        }
        $period = $this->period($now);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT client_ai_extraction_monthly_limit FROM supplier WHERE id = ? FOR UPDATE');
            $stmt->execute([$supplierId]);
            $limit = (int) $stmt->fetchColumn();
            if ($limit <= 0) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare('INSERT IGNORE INTO client_ai_extraction_usage (supplier_id, period, used) VALUES (?, ?, 0)')
                ->execute([$supplierId, $period]);
            $stmt = $pdo->prepare('UPDATE client_ai_extraction_usage SET used = used + 1 WHERE supplier_id = ? AND period = ? AND used < ?');
            $stmt->execute([$supplierId, $period, $limit]);
            $reserved = $stmt->rowCount() === 1;
            $stmt = $pdo->prepare('SELECT used FROM client_ai_extraction_usage WHERE supplier_id = ? AND period = ?');
            $stmt->execute([$supplierId, $period]);
            $used = (int) $stmt->fetchColumn();
            $pdo->commit();
            return $reserved ? $this->state($period, $limit, $used) : null;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function period(?\DateTimeImmutable $now): string
    {
        return ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m');
    }

    /** @return array{period:string,limit:int,used:int,remaining:int} */
    private function state(string $period, int $limit, int $used): array
    {
        return ['period' => $period, 'limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
    }
}
