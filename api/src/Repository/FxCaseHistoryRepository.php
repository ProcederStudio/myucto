<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Closing\FxCaseDocumentIdentity;
use MyInvoice\Service\Accounting\Closing\FxCaseTuple;
use PDO;

final class FxCaseHistoryRepository
{
    public function __construct(private readonly Connection $db, private readonly ClosingRepository $closing) {}

    public function snapshot(int $supplierId, array $period, bool $currentRead = false): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($currentRead && $own) throw new \LogicException('Current FX case history requires a transaction.');
        if ($own) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
        }
        try {
            if ($currentRead) (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            return $this->read($supplierId, $period, $currentRead);
        } finally {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
        }
    }

    private function read(int $supplierId, array $period, bool $current): array
    {
        $asOf = (string) $period['ends_on'];
        $periods = $this->rows('SELECT * FROM accounting_periods WHERE supplier_id=? AND starts_on<=? ORDER BY starts_on,id', [$supplierId, $asOf], $current);
        $steps = $this->rows("SELECT s.* FROM accounting_closing_steps s JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id WHERE s.supplier_id=? AND p.starts_on<=? AND s.step_key IN ('fx_revaluation','close_books','open_next') ORDER BY p.starts_on,s.id", [$supplierId, $asOf], $current);
        $byPeriod = [];
        foreach ($steps as $step) {
            $step['payload'] = $step['payload'] === null ? null : json_decode($step['payload'], true, 512, JSON_THROW_ON_ERROR);
            $byPeriod[(int) $step['period_id']][$step['step_key']] = $step;
        }
        $reversalLock = $current ? ' FOR UPDATE' : '';
        $entries = $this->rows("SELECT e.*, EXISTS(SELECT 1 FROM journal_entries original
            WHERE original.supplier_id=e.supplier_id AND original.reversed_by=e.id{$reversalLock}) AS is_reversal
            FROM journal_entries e WHERE e.supplier_id=? AND e.entry_date<=?
            AND e.source_type IN ('fx_revaluation','closing','opening') ORDER BY e.entry_date,e.id", [$supplierId, $asOf], $current);
        $entryMap = [];
        foreach ($entries as $entry) {
            $entry['lines'] = $this->rows('SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY l.line_no,l.id', [$supplierId, $entry['id']], $current);
            $entry['lines'] = $this->tuples($supplierId, $entry['lines'], $current);
            $entryMap[(int) $entry['id']] = $entry;
        }
        $documents = [
            'invoice' => array_column($this->rows('SELECT id,invoice_type,parent_invoice_id,issue_date,client_id AS partner_id FROM invoices WHERE supplier_id=? ORDER BY id', [$supplierId], $current), null, 'id'),
            'purchase_invoice' => array_column($this->rows('SELECT id,document_kind,parent_purchase_invoice_id,issue_date,vendor_id AS partner_id FROM purchase_invoices WHERE supplier_id=? ORDER BY id', [$supplierId], $current), null, 'id'),
        ];
        $enrich = function (array $items) use ($documents, $supplierId, $current): array {
            $sourceRows = $this->tuples($supplierId, array_map(static fn (array $p): array => ['id' => $p['item']['source_line_id'], ...$p['item']], $items), $current);
            foreach ($items as $index => &$projection) {
                $item = &$projection['item'];
                $item['case_document_id'] = FxCaseDocumentIdentity::resolve($item['doc_type'], (int) $item['doc_id'], $documents[$item['doc_type']]);
                $item['source_tuple'] = $sourceRows[$index]['source_tuple'];
                if ($item['case_document_id'] !== null) $item['source_proof_hash'] = FxCaseTuple::sourceHash($item);
                unset($item);
            }
            unset($projection);
            return $items;
        };
        $history = [];
        foreach ($periods as $prior) {
            if ((string) $prior['ends_on'] >= $asOf || (int) $prior['id'] === (int) $period['id']) continue;
            $priorSteps = $byPeriod[(int) $prior['id']] ?? [];
            $fx = $priorSteps['fx_revaluation'] ?? null;
            if (($fx['status'] ?? null) !== 'done') continue;
            $history[] = ['period' => $prior, 'steps' => $priorSteps, 'items' => $enrich($this->closing->fxItemsWithSettlement($supplierId, (string) $prior['ends_on'], $current))];
        }
        $items = $enrich($this->closing->fxItemsWithSettlement($supplierId, $asOf, $current));
        $accountIds = [];
        foreach ([$items, ...array_column($history, 'items')] as $projections) {
            foreach ($projections as $projection) $accountIds[(int) $projection['item']['account_id']] = true;
        }
        foreach ($entries as $entry) {
            if ($entry['source_type'] !== 'fx_revaluation' || (int) $entry['source_id'] % 10 === 2) continue;
            foreach ($entryMap[(int) $entry['id']]['lines'] as $line) {
                if ($line['currency_code'] !== null && $line['currency_code'] !== 'CZK') $accountIds[(int) $line['account_id']] = true;
            }
        }
        $ledger = [];
        if ($accountIds !== []) {
            $ids = array_keys($accountIds);
            sort($ids, SORT_NUMERIC);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $ledger = $this->rows("SELECT l.*,e.source_type,e.source_id,e.entry_date,e.period_id,e.reversed_by,e.posted_at FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id WHERE e.supplier_id=? AND e.entry_date<=? AND l.account_id IN ({$marks}) ORDER BY e.entry_date,e.id,l.line_no", [$supplierId, $asOf, ...$ids], $current);
        }
        $ledger = $this->tuples($supplierId, $ledger, $current);
        return ['items' => $items, 'history' => $history, 'entries' => $entryMap, 'ledger' => $ledger, 'periods' => $periods];
    }

    private function tuples(int $supplierId, array $rows, bool $current): array
    {
        $ids = array_map('intval', array_column($rows, 'id'));
        $assignments = new DimensionAssignmentRepository($this->db);
        $dimensions = $assignments->lineDimensions($supplierId, $ids, $current);
        $splits = $assignments->lineSplits($supplierId, $ids, $current);
        foreach ($rows as &$row) $row['source_tuple'] = FxCaseTuple::fromRow($row, $dimensions[(int) $row['id']] ?? [], $splits[(int) $row['id']] ?? []);
        unset($row);
        return $rows;
    }

    private function rows(string $sql, array $params, bool $current): array
    {
        $stmt = $this->db->pdo()->prepare($sql . ($current ? ' FOR UPDATE' : ''));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
