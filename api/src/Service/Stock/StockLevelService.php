<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * JEDINÉ místo mutace tabulky `stock_levels` (vynuceno architektonickým testem
 * StockLevelsMutationGuardsTest — žádný jiný soubor nesmí obsahovat
 * `UPDATE stock_levels` / `INSERT ... stock_levels`).
 *
 * Odpovědnosti:
 *  - materializace + zamčení řádků stavů v deterministickém pořadí (globální
 *    lock-order B3: `(warehouse_id, stock_item_id) ASC`),
 *  - aplikace příjmu/výdeje přes vážený klouzavý průměr (StockValuation),
 *  - tvrdý zákaz záporného stavu (A3) → StockException 409 `insufficient_stock`,
 *  - přepis stavu při replayi ledgeru (StockRecomputeService).
 *
 * Volající (StockDocumentService, StockIssueService) MUSÍ nejdřív zamknout VŠECHNY
 * dotčené stavy jedním `lockLevels()` (i u převodky oba sklady dohromady), teprve
 * pak volat apply* metody. Vše běží uvnitř transakce otevřené orchestrátorem.
 */
final class StockLevelService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Materializuje (INSERT IGNORE) a zamkne (SELECT … FOR UPDATE) řádky stavů pro
     * zadané dvojice skladů/karet v pořadí `(warehouse_id, stock_item_id) ASC`.
     * Vrací mapu aktuálních stavů klíčovanou `"warehouse_id:stock_item_id"`.
     *
     * @param list<array{warehouse_id:int,stock_item_id:int}> $pairs
     * @return array<string,array{qtyT:int,valueC:int}>
     */
    public function lockLevels(int $supplierId, array $pairs): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new StockException('no_transaction', 'lockLevels vyžaduje otevřenou transakci.', 500);
        }

        // Deduplikace + deterministické řazení (lock-order B3).
        $uniq = [];
        foreach ($pairs as $p) {
            $wh = (int) $p['warehouse_id'];
            $si = (int) $p['stock_item_id'];
            $uniq[$wh . ':' . $si] = ['warehouse_id' => $wh, 'stock_item_id' => $si];
        }
        $ordered = array_values($uniq);
        usort($ordered, static function (array $a, array $b): int {
            return [$a['warehouse_id'], $a['stock_item_id']] <=> [$b['warehouse_id'], $b['stock_item_id']];
        });

        if ($ordered === []) {
            return [];
        }

        // 1) Materializace nulových řádků, aby FOR UPDATE zamklo reálné řádky (ne gap).
        $insSql = 'INSERT IGNORE INTO stock_levels (supplier_id, warehouse_id, stock_item_id, qty, value_total, avg_unit_cost) VALUES ';
        $insVals = [];
        $insArgs = [];
        foreach ($ordered as $p) {
            $insVals[] = '(?, ?, ?, 0, 0, 0)';
            $insArgs[] = $supplierId;
            $insArgs[] = $p['warehouse_id'];
            $insArgs[] = $p['stock_item_id'];
        }
        $ins = $pdo->prepare($insSql . implode(', ', $insVals));
        $ins->execute($insArgs);

        // 2) Zámek v deterministickém pořadí.
        $where = [];
        $args  = [$supplierId];
        foreach ($ordered as $p) {
            $where[] = '(warehouse_id = ? AND stock_item_id = ?)';
            $args[]  = $p['warehouse_id'];
            $args[]  = $p['stock_item_id'];
        }
        $sql = 'SELECT warehouse_id, stock_item_id, qty, value_total FROM stock_levels '
             . 'WHERE supplier_id = ? AND (' . implode(' OR ', $where) . ') '
             . 'ORDER BY warehouse_id ASC, stock_item_id ASC FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = ((int) $row['warehouse_id']) . ':' . ((int) $row['stock_item_id']);
            $out[$key] = [
                'qtyT'   => StockValuation::qtyToT((string) $row['qty']),
                'valueC' => StockValuation::valueToC((string) $row['value_total']),
            ];
        }
        return $out;
    }

    /**
     * Aktuální stav karty na skladu BEZ zamykání. 0/0 když řádek neexistuje.
     * Pouze pro čtení MIMO transakci (availability badge). Uvnitř transakce
     * (post/reverse/replay) NEPOUŽÍVAT — pod REPEATABLE READ vrací stav ze stale
     * snapshotu; použij currentForUpdate() (review CRITICAL 1).
     *
     * @return array{qtyT:int,valueC:int}
     */
    public function current(int $supplierId, int $warehouseId, int $stockItemId): array
    {
        return $this->readRow($supplierId, $warehouseId, $stockItemId, false);
    }

    /**
     * Aktuální stav karty ČERSTVĚ (locking read, FOR UPDATE) — čte poslední
     * commitnutý stav bez ohledu na tx snapshot (obchází stale REPEATABLE READ,
     * CRITICAL 1). Řádek už je zamčen z lockLevels; re-lock je levný. Jen v tx.
     *
     * @return array{qtyT:int,valueC:int}
     */
    public function currentForUpdate(int $supplierId, int $warehouseId, int $stockItemId): array
    {
        return $this->readRow($supplierId, $warehouseId, $stockItemId, true);
    }

    /** @return array{qtyT:int,valueC:int} */
    private function readRow(int $supplierId, int $warehouseId, int $stockItemId, bool $forUpdate): array
    {
        $sql = 'SELECT qty, value_total FROM stock_levels WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?'
            . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $warehouseId, $stockItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['qtyT' => 0, 'valueC' => 0];
        }
        return [
            'qtyT'   => StockValuation::qtyToT((string) $row['qty']),
            'valueC' => StockValuation::valueToC((string) $row['value_total']),
        ];
    }

    /**
     * Příjem na sklad. Řádek stavu MUSÍ být předem zamčen (lockLevels).
     *
     * @param int $qtyInT     přijímané množství (tisíciny), > 0
     * @param int $lineValueC hodnota řádku v haléřích (round(qty×PC,2) + rozpuštěné extra náklady)
     * @return array{unit_cost:string,value_total:string,qtyT:int,valueC:int,lineValueC:int}
     */
    public function applyReceipt(int $supplierId, int $warehouseId, int $stockItemId, int $qtyInT, int $lineValueC): array
    {
        if ($lineValueC < 0) {
            // A3 hodnotový invariant — hodnota příjmu (vč. rozpuštěných nákladů)
            // nesmí být záporná (review MEDIUM 5).
            throw new StockException('invalid_document', 'Hodnota příjmového řádku nesmí být záporná.', 422, [[
                'stock_item_id' => $stockItemId,
                'value_total'   => StockValuation::cToDecimal($lineValueC),
            ]]);
        }
        $cur = $this->readLocked($supplierId, $warehouseId, $stockItemId);
        $res = StockValuation::receipt($cur['qtyT'], $cur['valueC'], $qtyInT, $lineValueC);
        $this->persist($supplierId, $warehouseId, $stockItemId, $res['qtyT'], $res['valueC'], $res['avgMicro']);

        return [
            'unit_cost'   => StockValuation::microToDecimal($res['lineUnitCostMicro']),
            'value_total' => StockValuation::cToDecimal($res['lineValueC']),
            'qtyT'        => $res['qtyT'],
            'valueC'      => $res['valueC'],
            'lineValueC'  => $res['lineValueC'],
        ];
    }

    /**
     * Výdej ze skladu za klouzavý průměr. Řádek stavu MUSÍ být předem zamčen.
     * Tvrdý zákaz minusu (A3): výdej nad dostupné → StockException 409.
     * `$context` (sku/name) obohatí payload chyby.
     *
     * @param array{sku?:string,name?:string} $context
     * @return array{unit_cost:string,value_total:string,qtyT:int,valueC:int,lineValueC:int}
     */
    public function applyIssue(int $supplierId, int $warehouseId, int $stockItemId, int $qtyOutT, array $context = []): array
    {
        $cur = $this->readLocked($supplierId, $warehouseId, $stockItemId);
        if ($qtyOutT > $cur['qtyT']) {
            throw new StockException(
                'insufficient_stock',
                'Nedostatek zásob pro výdej.',
                409,
                StockShortageDetails::enrich($this->db->pdo(), $supplierId, $warehouseId, [[
                    'stock_item_id' => $stockItemId,
                    'sku'           => $context['sku'] ?? null,
                    'name'          => $context['name'] ?? null,
                    'requested'     => StockValuation::tToDecimal($qtyOutT),
                    'available'     => StockValuation::tToDecimal($cur['qtyT']),
                ]]),
            );
        }

        $res = StockValuation::issue($cur['qtyT'], $cur['valueC'], $qtyOutT);
        $this->persist($supplierId, $warehouseId, $stockItemId, $res['qtyT'], $res['valueC'], $res['avgMicro']);

        return [
            'unit_cost'   => StockValuation::microToDecimal($res['lineUnitCostMicro']),
            'value_total' => StockValuation::cToDecimal($res['lineValueC']),
            'qtyT'        => $res['qtyT'],
            'valueC'      => $res['valueC'],
            'lineValueC'  => $res['lineValueC'],
        ];
    }

    /**
     * Přímý přepis stavu — VÝHRADNĚ pro replay ledgeru (StockRecomputeService),
     * kde se stav rekonstruuje z posted řádků. Řádek musí být zamčen.
     */
    public function setLevel(int $supplierId, int $warehouseId, int $stockItemId, int $qtyT, int $valueC): void
    {
        if ($qtyT < 0 || $valueC < 0) {
            throw new StockException('insufficient_stock', 'Replay by vytvořil záporný stav.', 409, [[
                'stock_item_id' => $stockItemId,
                'available'     => StockValuation::tToDecimal($qtyT),
            ]]);
        }
        $avg = StockValuation::avgUnitCostMicro($qtyT, $valueC);
        $this->persist($supplierId, $warehouseId, $stockItemId, $qtyT, $valueC, $avg);
    }

    // ── interní ─────────────────────────────────────────────────────────────────

    /** @return array{qtyT:int,valueC:int} */
    private function readLocked(int $supplierId, int $warehouseId, int $stockItemId): array
    {
        // Locking read (FOR UPDATE) — čte poslední commitnutý stav, ne stale
        // REPEATABLE READ snapshot (CRITICAL 1). Řádek je z lockLevels už zamčen,
        // takže re-lock nečeká.
        return $this->currentForUpdate($supplierId, $warehouseId, $stockItemId);
    }

    private function persist(int $supplierId, int $warehouseId, int $stockItemId, int $qtyT, int $valueC, int $avgMicro): void
    {
        // INSERT IGNORE zajistil existenci řádku v lockLevels; UPDATE je bezpečný.
        // Pro robustnost (setLevel po materializaci) použijeme upsert.
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO stock_levels (supplier_id, warehouse_id, stock_item_id, qty, value_total, avg_unit_cost) '
            . 'VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE qty = VALUES(qty), value_total = VALUES(value_total), avg_unit_cost = VALUES(avg_unit_cost)'
        );
        $stmt->execute([
            $supplierId,
            $warehouseId,
            $stockItemId,
            StockValuation::tToDecimal($qtyT),
            StockValuation::cToDecimal($valueC),
            StockValuation::microToDecimal($avgMicro),
        ]);
    }
}
