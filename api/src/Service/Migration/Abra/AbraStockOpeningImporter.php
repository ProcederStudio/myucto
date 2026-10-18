<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Service\Stock\StockDocumentService;
use PDO;

final class AbraStockOpeningImporter
{
    private ?array $items = null;
    private ?int $initializedSupplier = null;
    private ?int $warehouseId = null;
    private ?string $sourceWarehouse = null;

    public function __construct(
        private readonly Connection $db,
        private readonly AbraImportRepository $imports,
        private readonly StockDocumentService $documents,
    ) {}

    /** @param list<array<string,mixed>> $rows */
    public static function singleSourceWarehouse(array $rows): string
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = AbraSource::sourceKey($row);
            if ($key === '') throw new AbraException('stock_warehouse_mapping_ambiguous', 'Zdrojový sklad nemá jednoznačný identifikátor.');
            $keys[$key] = true;
        }
        if (count($keys) > 1) {
            throw new AbraException('stock_warehouse_mapping_ambiguous',
                'Zdroj obsahuje více skladů. Převod stavu nelze bezpečně sloučit do jednoho skladu.');
        }
        return (string) (array_key_first($keys) ?? '');
    }

    /** @param list<array<string,mixed>> $rows */
    public function prepareSourceWarehouse(int $supplierId, array $rows): void
    {
        $this->initialize($supplierId);
        $this->sourceWarehouse = self::singleSourceWarehouse($rows);
    }

    /** @return array<string,mixed> */
    public static function mapCard(array $row, array $item): array
    {
        $sourceKey = AbraSource::sourceKey($row);
        $product = AbraSource::relation($row['cenik@ref'] ?? $row['cenik'] ?? null);
        $warehouse = AbraSource::relation($row['sklad@ref'] ?? $row['sklad'] ?? null);
        $quantity = AbraSource::number($row['stavMJ'] ?? null);
        $value = AbraSource::number($row['stavTuz'] ?? null);
        $reason = null;
        if ($sourceKey === '' || $product === null || $warehouse === null || $quantity === null || $value === null) {
            $reason = 'stock_card_invalid';
        } elseif ($quantity < 0) {
            $reason = 'stock_negative_quantity_skipped';
        } elseif ($quantity > 0 && $value < 0) {
            $reason = 'stock_negative_valuation_skipped';
        } elseif ($quantity > 0 && abs($quantity - round($quantity, 3)) > 0.0000001) {
            $reason = 'stock_quantity_precision_unsupported';
        } elseif ($quantity > 0 && ($item === [] || !(bool) ($item['is_active'] ?? false)
            || !(bool) ($item['is_stocked'] ?? false) || ($item['tracking_mode'] ?? 'none') !== 'none')) {
            $reason = 'stock_item_requires_review';
        }
        $qty = $quantity !== null ? round($quantity, 3) : 0.0;
        $valueC = $value !== null ? (int) round($value * 100) : 0;
        $unitCostMicro = $qty > 0 && $valueC >= 0
            ? (int) floor($valueC * 10000 / $qty + 0.00000001) : 0;
        $lineC = (int) round($qty * $unitCostMicro / 10000);
        while ($lineC > $valueC && $unitCostMicro > 0) {
            $unitCostMicro--;
            $lineC = (int) round($qty * $unitCostMicro / 10000);
        }
        return [
            'source_key' => $sourceKey,
            'source_hash' => AbraSource::hash([$sourceKey, $product['key'] ?? '', $warehouse['key'] ?? '',
                $quantity, $value]),
            'product_key' => $product['key'] ?? '',
            'warehouse_key' => $warehouse['key'] ?? '',
            'item_id' => (int) ($item['id'] ?? 0),
            'qty' => number_format($qty, 3, '.', ''),
            'value' => number_format($valueC / 100, 2, '.', ''),
            'unit_cost' => number_format($unitCostMicro / 1000000, 6, '.', ''),
            'extra_cost' => number_format(max(0, $valueC - $lineC) / 100, 2, '.', ''),
            'reason' => $reason,
        ];
    }

    /** @return array{created:int,skipped:int,changed:int,failed:int,warnings:list<string>,documents:int} */
    public function importPage(int $supplierId, int $userId, array $rows, callable $cancelled): array
    {
        $this->initialize($supplierId);
        $result = ['created' => 0, 'skipped' => 0, 'changed' => 0, 'failed' => 0,
            'warnings' => [], 'documents' => 0];
        $warnings = [];
        $ready = [];
        foreach ($rows as $row) {
            if ($cancelled()) throw new AbraException('cancelled', 'Převod byl zrušen.');
            $product = AbraSource::relation($row['cenik@ref'] ?? $row['cenik'] ?? null);
            $plan = self::mapCard($row, $this->items[$product['key'] ?? ''] ?? []);
            if ($plan['warehouse_key'] !== '') {
                $this->sourceWarehouse ??= $plan['warehouse_key'];
                if ($this->sourceWarehouse !== $plan['warehouse_key']) {
                    throw new AbraException('stock_warehouse_mapping_ambiguous',
                        'Zdroj obsahuje více skladů. Převod stavu nelze bezpečně sloučit do jednoho skladu.');
                }
            }
            $mapped = $plan['source_key'] !== ''
                ? $this->imports->lookup($supplierId, 'skladova-karta', $plan['source_key']) : null;
            if ($mapped !== null) {
                if ($plan['reason'] === null && hash_equals((string) $mapped['source_hash'], $plan['source_hash'])) {
                    $result['skipped']++;
                } else {
                    $result['changed']++;
                    $warnings['stock_source_changed_requires_review'] = true;
                }
                continue;
            }
            if ($plan['reason'] !== null) {
                $warnings[$plan['reason']] = true;
                if (in_array($plan['reason'], ['stock_negative_quantity_skipped', 'stock_negative_valuation_skipped'], true)) {
                    $result['skipped']++;
                } else {
                    $result['failed']++;
                }
                continue;
            }
            if ((float) $plan['qty'] === 0.0) {
                $result['skipped']++;
                continue;
            }
            $ready[] = $plan;
        }
        foreach (array_chunk($ready, 100) as $batch) {
            if ($cancelled()) throw new AbraException('cancelled', 'Převod byl zrušen.');
            $pdo = $this->db->pdo();
            $nested = $pdo->inTransaction();
            if ($nested) $pdo->exec('SAVEPOINT abra_stock_opening');
            else {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
            }
            try {
                $level = $pdo->prepare('SELECT qty, value_total FROM stock_levels
                    WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ? FOR UPDATE');
                $prior = $pdo->prepare("SELECT COALESCE(SUM(l.qty), 0) qty, COALESCE(SUM(l.value_total), 0) value_total
                    FROM abra_flexi_import_map m
                    JOIN stock_document_lines l ON l.id = m.target_id AND l.supplier_id = m.supplier_id
                    JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
                    WHERE m.supplier_id = ? AND m.kind = 'skladova-karta'
                      AND m.target_type = 'stock_document_line'
                      AND d.warehouse_id = ? AND l.stock_item_id = ?");
                $itemIds = array_values(array_unique(array_map(static fn (array $plan): int => $plan['item_id'], $batch)));
                sort($itemIds, SORT_NUMERIC);
                $expected = [];
                foreach ($itemIds as $itemId) {
                    $level->execute([$supplierId, $this->warehouseId, $itemId]);
                    $before = $level->fetch(PDO::FETCH_ASSOC);
                    $level->closeCursor();
                    $prior->execute([$supplierId, $this->warehouseId, $itemId]);
                    $imported = $prior->fetch(PDO::FETCH_ASSOC);
                    $prior->closeCursor();
                    $priorQty = (int) round((float) $imported['qty'] * 1000);
                    $priorValue = (int) round((float) $imported['value_total'] * 100);
                    if ((int) round((float) ($before['qty'] ?? 0) * 1000) !== $priorQty
                        || (int) round((float) ($before['value_total'] ?? 0) * 100) !== $priorValue) {
                        throw new AbraException('stock_target_not_empty',
                            'Cílový skladový stav neodpovídá dříve převedeným kartám ABRA Flexi.');
                    }
                    $expected[$itemId] = ['qty' => $priorQty, 'value' => $priorValue];
                }
                foreach ($batch as $plan) {
                    $expected[$plan['item_id']]['qty'] += (int) round((float) $plan['qty'] * 1000);
                    $expected[$plan['item_id']]['value'] += (int) round((float) $plan['value'] * 100);
                }
                $body = ['doc_type' => 'receipt', 'origin' => 'manual',
                    'warehouse_id' => $this->warehouseId, 'doc_date' => date('Y-m-d'),
                    'description' => 'Počáteční stav skladu převzatý z ABRA Flexi',
                    'lines' => array_map(static fn (array $plan): array => [
                        'stock_item_id' => $plan['item_id'], 'qty' => $plan['qty'],
                        'unit_cost' => $plan['unit_cost'], 'extra_cost' => $plan['extra_cost'],
                    ], $batch)];
                $created = $this->documents->create($supplierId, $body, $userId > 0 ? $userId : null);
                $posted = $this->documents->post($supplierId, (int) $created['id'], $userId > 0 ? $userId : null);
                foreach ($itemIds as $itemId) {
                    $level->execute([$supplierId, $this->warehouseId, $itemId]);
                    $after = $level->fetch(PDO::FETCH_ASSOC);
                    $level->closeCursor();
                    if ($after === false
                        || (int) round((float) $after['qty'] * 1000) !== $expected[$itemId]['qty']
                        || (int) round((float) $after['value_total'] * 100) !== $expected[$itemId]['value']) {
                        throw new AbraException('stock_opening_reconciliation_failed',
                            'Konečný stav skladu se neshoduje s ABRA Flexi.');
                    }
                }
                foreach ($batch as $i => $plan) {
                    $line = $posted['lines'][$i] ?? null;
                    if (!is_array($line) || (int) $line['stock_item_id'] !== $plan['item_id']
                        || (int) round((float) $line['qty'] * 1000) !== (int) round((float) $plan['qty'] * 1000)
                        || (int) round((float) $line['value_total'] * 100) !== (int) round((float) $plan['value'] * 100)) {
                        throw new AbraException('stock_opening_reconciliation_failed',
                            'Ocenění počátečního skladu se neshoduje s ABRA Flexi.');
                    }
                    $this->imports->remember($supplierId, 'skladova-karta', $plan['source_key'],
                        $plan['source_hash'], 'stock_document_line', (int) $line['id'], (int) date('Y'));
                    $result['created']++;
                }
                $nested ? $pdo->exec('RELEASE SAVEPOINT abra_stock_opening') : $pdo->commit();
                $result['documents']++;
            } catch (\Throwable $error) {
                if (TransactionFailure::rollbackIfAborted($pdo, $error)) {
                    throw $error;
                }
                if ($nested) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT abra_stock_opening');
                    $pdo->exec('RELEASE SAVEPOINT abra_stock_opening');
                } elseif ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        }
        $result['warnings'] = array_keys($warnings);
        return $result;
    }

    private function initialize(int $supplierId): void
    {
        if ($this->initializedSupplier === $supplierId && $this->items !== null) return;
        $this->items = null;
        $this->sourceWarehouse = null;
        $this->warehouseId = null;
        $warehouses = $this->db->pdo()->prepare('SELECT id FROM warehouses WHERE supplier_id = ? AND is_active = 1');
        $warehouses->execute([$supplierId]);
        $ids = $warehouses->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) {
            throw new AbraException('stock_warehouse_mapping_ambiguous',
                'Pro převod počátečního stavu musí být v cíli jednoznačný aktivní sklad.');
        }
        $this->warehouseId = (int) $ids[0];
        $this->items = [];
        $stmt = $this->db->pdo()->prepare('SELECT m.abra_key, i.id, i.is_active, i.is_stocked, i.tracking_mode
            FROM abra_flexi_import_map m JOIN stock_items i ON i.id = m.target_id AND i.supplier_id = m.supplier_id
            WHERE m.supplier_id = ? AND m.kind = "cenik" AND m.target_type = "stock_item"');
        $stmt->execute([$supplierId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $this->items[(string) $row['abra_key']] = $row;
        $this->initializedSupplier = $supplierId;
    }
}
