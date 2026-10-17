<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionValueHistoryGuard;
use PDO;

final class CostCenterRepository
{
    private const COLUMNS = 'id, supplier_id, code, name, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $includeInactive = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM cost_centers WHERE supplier_id = ?';
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY is_active DESC, code ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM cost_centers WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM cost_centers WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function create(int $supplierId, string $code, string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO cost_centers (supplier_id, code, name) VALUES (?, ?, ?)'
        )->execute([$supplierId, $code, $name]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array{name?:string,is_active?:bool} $changes */
    public function update(int $supplierId, int $id, array $changes): bool
    {
        $sets = [];
        $params = [];
        if (array_key_exists('name', $changes)) {
            $sets[] = 'name = ?';
            $params[] = $changes['name'];
        }
        if (array_key_exists('is_active', $changes)) {
            $sets[] = 'is_active = ?';
            $params[] = $changes['is_active'] ? 1 : 0;
        }
        if ($sets === []) {
            return $this->find($supplierId, $id) !== null;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE cost_centers SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0 || $this->find($supplierId, $id) !== null;
    }

    public function hasUsage(int $supplierId, string $code): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                EXISTS (
                    SELECT 1 FROM journal_entry_lines
                     WHERE supplier_id = ? AND cost_center = ?
                )
                OR EXISTS (
                    SELECT 1
                      FROM journal_entry_template_lines l
                      JOIN journal_entry_templates t ON t.id = l.template_id
                     WHERE t.supplier_id = ? AND l.cost_center = ?
                )'
        );
        $stmt->execute([$supplierId, $code, $supplierId, $code]);
        return (bool) $stmt->fetchColumn();
    }

    public function deactivate(int $supplierId, int $id): bool
    {
        return $this->update($supplierId, $id, ['is_active' => false]);
    }

    public function delete(int $supplierId, int $id): bool
    {
        return (new DimensionValueHistoryGuard($this->db))->deleteCostCenter($supplierId, $id, function () use ($supplierId, $id): bool {
            $stmt = $this->db->pdo()->prepare('DELETE FROM cost_centers WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$id, $supplierId]);
            return $stmt->rowCount() > 0;
        });
    }

    /** @return array<string,mixed> */
    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
