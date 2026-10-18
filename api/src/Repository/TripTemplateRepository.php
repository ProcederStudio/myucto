<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro trip_templates — pravidelné cesty knihy jízd.
 *
 * Per tenant (supplier_id). car_id NULL = cesta pro libovolné auto. Jízdy si šablonu
 * nepamatují (kopírují její hodnoty), proto jde šablonu kdykoli smazat.
 */
final class TripTemplateRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, bool $includeArchived = false): array
    {
        $sql = 'SELECT tt.*, c.registration AS car_registration, c.name AS car_name,
                       tc.label AS category_label, tc.is_private AS category_is_private
                  FROM trip_templates tt
             LEFT JOIN cars c ON c.id = tt.car_id AND c.supplier_id = tt.supplier_id
             LEFT JOIN trip_categories tc ON tc.id = tt.category_id AND tc.supplier_id = tt.supplier_id
                 WHERE tt.supplier_id = ?';
        if (!$includeArchived) $sql .= ' AND tt.is_archived = 0';
        $sql .= ' ORDER BY tt.display_order ASC, tt.label ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tt.*, c.registration AS car_registration, c.name AS car_name,
                    tc.label AS category_label, tc.is_private AS category_is_private
               FROM trip_templates tt
          LEFT JOIN cars c ON c.id = tt.car_id AND c.supplier_id = tt.supplier_id
          LEFT JOIN trip_categories tc ON tc.id = tt.category_id AND tc.supplier_id = tt.supplier_id
              WHERE tt.id = ? AND tt.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function create(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO trip_templates (supplier_id, car_id, label, origin, destination, purpose,
                                         distance_km, category_id, time_start, time_end,
                                         display_order, is_archived, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, ...$this->bind($data), $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE trip_templates
                SET car_id = ?, label = ?, origin = ?, destination = ?, purpose = ?,
                    distance_km = ?, category_id = ?, time_start = ?, time_end = ?,
                    display_order = ?, is_archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([...$this->bind($data), $id, $supplierId]);
        return $stmt->rowCount() >= 0;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM trip_templates WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<mixed> Pořadí dle sloupců za supplier_id. */
    private function bind(array $data): array
    {
        return [
            $this->nullableInt($data['car_id'] ?? null),
            mb_substr(trim((string) ($data['label'] ?? '')), 0, 120),
            $this->nullableStr($data['origin'] ?? null),
            $this->nullableStr($data['destination'] ?? null),
            $this->nullableStr($data['purpose'] ?? null),
            round((float) ($data['distance_km'] ?? 0), 1),
            $this->nullableInt($data['category_id'] ?? null),
            $this->nullableStr($data['time_start'] ?? null),
            $this->nullableStr($data['time_end'] ?? null),
            (int) ($data['display_order'] ?? 0),
            !empty($data['is_archived']) ? 1 : 0,
        ];
    }

    private function nullableInt(mixed $v): ?int
    {
        return ($v === null || $v === '' || (int) $v === 0) ? null : (int) $v;
    }

    private function nullableStr(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : mb_substr($s, 0, 255);
    }

    private function cast(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'supplier_id'         => (int) $r['supplier_id'],
            'car_id'              => $r['car_id'] !== null ? (int) $r['car_id'] : null,
            'car_registration'    => isset($r['car_registration']) ? (string) $r['car_registration'] : null,
            'car_name'            => isset($r['car_name']) ? (string) $r['car_name'] : null,
            'label'               => (string) $r['label'],
            'origin'              => $r['origin'] !== null ? (string) $r['origin'] : null,
            'destination'         => $r['destination'] !== null ? (string) $r['destination'] : null,
            'purpose'             => $r['purpose'] !== null ? (string) $r['purpose'] : null,
            'distance_km'         => (float) $r['distance_km'],
            'category_id'         => $r['category_id'] !== null ? (int) $r['category_id'] : null,
            'category_label'      => isset($r['category_label']) ? (string) $r['category_label'] : null,
            'category_is_private' => isset($r['category_is_private']) ? (bool) $r['category_is_private'] : null,
            'time_start'          => $r['time_start'] !== null ? substr((string) $r['time_start'], 0, 5) : null,
            'time_end'            => $r['time_end'] !== null ? substr((string) $r['time_end'], 0, 5) : null,
            'display_order'       => (int) $r['display_order'],
            'is_archived'         => (bool) $r['is_archived'],
        ];
    }
}
