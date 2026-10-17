<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class DimensionValueHistoryGuard
{
    private readonly DimensionPeriodGuard $periods;

    public function __construct(private readonly Connection $db)
    {
        $this->periods = new DimensionPeriodGuard($db);
    }

    public function changeValue(int $valueId, array $changes, callable $write): mixed
    {
        if (!array_key_exists('parent_id', $changes) && !array_key_exists('cost_center_id', $changes)) {
            return $write();
        }
        return $this->periods->transaction(function () use ($valueId, $changes, $write): mixed {
            $this->lockPeriods();
            $value = $this->value($valueId);
            if ($value !== null) {
                $moves = array_key_exists('parent_id', $changes)
                    && self::id($changes['parent_id']) !== self::id($value['parent_id']);
                $remaps = array_key_exists('cost_center_id', $changes)
                    && self::id($changes['cost_center_id']) !== self::id($value['cost_center_id']);
                if ($moves || $remaps) {
                    $values = $moves ? $this->branch($value) : [$value];
                    $this->assertMutable($value['type_id'], $values, $remaps ? [self::id($changes['cost_center_id'])] : []);
                }
            }
            return $write();
        });
    }

    public function deleteValue(int $valueId, callable $write): mixed
    {
        return $this->periods->transaction(function () use ($valueId, $write): mixed {
            $this->lockPeriods();
            $value = $this->value($valueId);
            if ($value !== null) {
                $this->assertMutable($value['type_id'], $this->branch($value));
            }
            return $write();
        });
    }

    public function deleteType(int $typeId, callable $write): mixed
    {
        return $this->periods->transaction(function () use ($typeId, $write): mixed {
            $this->lockPeriods();
            $this->assertMutable($typeId, $this->typeValues($typeId));
            return $write();
        });
    }

    public function deleteCostCenter(int $supplierId, int $centerId, callable $write): mixed
    {
        return $this->periods->transaction(function () use ($supplierId, $centerId, $write): mixed {
            $this->lockPeriods();
            // FK mazání střediska by jinak přepsalo cost_center_id hodnot na NULL mimo jejich repository.
            $stmt = $this->db->pdo()->prepare('SELECT v.id, v.type_id, v.parent_id, v.cost_center_id
                FROM dimension_values v JOIN cost_centers cc ON cc.id = v.cost_center_id
                WHERE cc.id = ? AND cc.supplier_id = ? ORDER BY v.type_id, v.id FOR UPDATE');
            $stmt->execute([$centerId, $supplierId]);
            $types = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $value) {
                $types[(int) $value['type_id']][] = $value;
            }
            foreach ($types as $typeId => $values) {
                $this->assertMutable($typeId, $values);
            }
            return $write();
        });
    }

    public function lockPeriods(): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Dimension history locks require a transaction.');
        }
        // Globální hodnotu může používat i firma, která již skupinu opustila.
        // Scope ani zdánlivý no-op nesmí vycházet ze starého snapshotu volajícího.
        // Vzácné strukturální editace proto zamykají období celé instalace před číselníkem i deníkem.
        $this->db->pdo()->query('SELECT id FROM accounting_periods ORDER BY supplier_id, starts_on, id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function id(mixed $id): ?int
    {
        return $id === null ? null : (int) $id;
    }

    private function value(int $valueId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, type_id, parent_id, cost_center_id FROM dimension_values WHERE id = ? FOR UPDATE');
        $stmt->execute([$valueId]);
        $value = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($value === false) {
            return null;
        }
        $value['type_id'] = (int) $value['type_id'];
        return $value;
    }

    private function typeValues(int $typeId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, type_id, parent_id, cost_center_id FROM dimension_values WHERE type_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$typeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function branch(array $value): array
    {
        $values = $this->typeValues((int) $value['type_id']);
        $children = [];
        foreach ($values as $row) {
            $children[(int) $row['parent_id']][] = $row;
        }
        $out = [];
        $queue = [$value];
        while ($queue !== []) {
            $row = array_pop($queue);
            $id = (int) $row['id'];
            if (isset($out[$id])) {
                continue;
            }
            $out[$id] = $row;
            array_push($queue, ...($children[$id] ?? []));
        }
        return array_values($out);
    }

    private function assertMutable(int $typeId, array $values, array $additionalCenters = []): void
    {
        $pdo = $this->db->pdo();
        $entries = [];
        $remember = static function (array $rows) use (&$entries): void {
            foreach ($rows as $row) {
                $entries[(int) $row['supplier_id']][(int) $row['id']] = (int) $row['id'];
            }
        };
        foreach (array_chunk(array_column($values, 'id'), 500) as $ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach (['journal_entry_line_dimensions', 'journal_entry_line_dimension_splits'] as $table) {
                $stmt = $pdo->prepare("SELECT e.id, e.supplier_id FROM {$table} d
                    JOIN journal_entry_lines l ON l.id = d.line_id
                    JOIN journal_entries e ON e.id = l.entry_id
                    WHERE d.dimension_type_id = ? AND d.dimension_value_id IN ({$marks})
                      AND e.posted_at IS NOT NULL FOR UPDATE");
                $stmt->execute([$typeId, ...$ids]);
                $remember($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        }
        $centers = array_values(array_unique(array_filter([...array_column($values, 'cost_center_id'), ...$additionalCenters])));
        foreach (array_chunk($centers, 500) as $ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT e.id, e.supplier_id FROM cost_centers cc
                JOIN journal_entry_lines l ON l.supplier_id = cc.supplier_id AND l.cost_center = cc.code
                JOIN journal_entries e ON e.id = l.entry_id
                LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = ?
                LEFT JOIN journal_entry_line_dimension_splits s ON s.line_id = l.id AND s.dimension_type_id = ?
                WHERE cc.id IN ({$marks}) AND e.posted_at IS NOT NULL
                  AND d.line_id IS NULL AND s.line_id IS NULL FOR UPDATE");
            $stmt->execute([$typeId, $typeId, ...$ids]);
            $remember($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        ksort($entries, SORT_NUMERIC);
        foreach ($entries as $supplierId => $ids) {
            foreach ($this->periods->lockEntries($supplierId, array_values($ids)) as $entry) {
                $this->periods->assertMutable($entry);
            }
        }
    }
}
