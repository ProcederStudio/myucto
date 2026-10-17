<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class FxCaseOriginReadRepository
{
    public function __construct(private readonly Connection $db) {}

    public function snapshot(int $supplierId, array $rows, bool $currentRead = false): array
    {
        if ($currentRead) (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        $facts = [];
        $queries = [
            'invoices' => 'SELECT d.id, d.invoice_type, d.parent_invoice_id, d.status, d.issue_date, d.client_id AS partner_id, c.code AS currency_code FROM invoices d JOIN currencies c ON c.id=d.currency_id AND c.supplier_id=d.supplier_id WHERE d.supplier_id=? ORDER BY d.id',
            'purchase_invoices' => 'SELECT d.id, d.document_kind, d.parent_purchase_invoice_id, d.status, d.issue_date, d.vendor_id AS partner_id, c.code AS currency_code FROM purchase_invoices d JOIN currencies c ON c.id=d.currency_id AND c.supplier_id=d.supplier_id WHERE d.supplier_id=? ORDER BY d.id',
            'bank_transactions' => 'SELECT b.id, b.matched_invoice_id, b.posted_at, b.amount, b.currency FROM bank_transactions b JOIN bank_statements s ON s.id=b.statement_id WHERE s.supplier_id=? ORDER BY b.id',
            'invoice_payments' => 'SELECT id, bank_transaction_id, invoice_id, source, paid_on, amount, currency FROM invoice_payments WHERE supplier_id=? ORDER BY id',
            'payment_matches' => 'SELECT id, bank_transaction_id, invoice_id, purchase_invoice_id, amount FROM payment_matches WHERE supplier_id=? ORDER BY id',
            'cash_documents' => 'SELECT id, invoice_id, purchase_invoice_id, status FROM cash_documents WHERE supplier_id=? ORDER BY id',
            'invoice_settlements' => 'SELECT id, doc_type, doc_id, journal_entry_id, status, amount FROM invoice_settlements WHERE supplier_id=? ORDER BY id',
            'offset_agreements' => 'SELECT id, journal_entry_id, status FROM offset_agreements WHERE supplier_id=? ORDER BY id',
            'offset_agreement_items' => 'SELECT id, agreement_id, doc_type, doc_id, amount FROM offset_agreement_items WHERE supplier_id=? ORDER BY id',
            'journal_entry_document_links' => 'SELECT id, entry_id, doc_type, doc_id FROM journal_entry_document_links WHERE supplier_id=? ORDER BY id',
        ];
        foreach ($queries as $key => $sql) {
            $stmt = $this->db->pdo()->prepare($sql . ($currentRead ? ' FOR UPDATE' : ''));
            $stmt->execute([$supplierId]);
            $facts[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $documents = ['invoice' => array_column($facts['invoices'], null, 'id'), 'purchase_invoice' => array_column($facts['purchase_invoices'], null, 'id')];
        $canonical = [];
        foreach ($documents as $type => $items) {
            foreach ($items as $id => $document) {
                $canonical[$type][$id] = \MyInvoice\Service\Accounting\Closing\FxCaseDocumentIdentity::resolve($type, (int) $id, $items);
            }
        }
        $accounts = [];
        foreach ($rows as $row) {
            if (!isset($documents[$row['source_type']][(int) $row['source_id']])) continue;
            $root = $canonical[$row['source_type']][(int) $row['source_id']] ?? null;
            if ($root !== null) $accounts[$row['source_type'] . ':' . $root][$row['account_id'] . ':' . (($row['currency_code'] === null || $row['currency_code'] === 'CZK') ? $documents[$row['source_type']][$root]['currency_code'] : $row['currency_code'])] = true;
        }
        $entries = [];
        $add = static function (string $type, int $sourceId, string $docType, int $docId) use (&$entries, $documents, $canonical): void {
            if ($sourceId <= 0 || !isset($documents[$docType][$docId])) return;
            $docId = $canonical[$docType][$docId] ?? null;
            if ($docId === null) return;
            $entries[$type . ':' . $sourceId][$docType . ':' . $docId] = ['kind' => 'document', 'doc_type' => $docType, 'doc_id' => $docId];
        };
        foreach ($facts['invoice_payments'] as $payment) if ($payment['source'] === 'bank') $add('bank', (int) $payment['bank_transaction_id'], 'invoice', (int) $payment['invoice_id']);
        foreach ($facts['payment_matches'] as $match) {
            $add('bank', (int) $match['bank_transaction_id'], 'invoice', (int) $match['invoice_id']);
            $add('bank', (int) $match['bank_transaction_id'], 'purchase_invoice', (int) $match['purchase_invoice_id']);
        }
        foreach ($facts['bank_transactions'] as $bank) if (!isset($entries['bank:' . $bank['id']])) $add('bank', (int) $bank['id'], 'invoice', (int) $bank['matched_invoice_id']);
        foreach ($facts['cash_documents'] as $cash) {
            if ($cash['status'] !== 'posted') continue;
            $add('cash', (int) $cash['id'], 'invoice', (int) $cash['invoice_id']);
            $add('cash', (int) $cash['id'], 'purchase_invoice', (int) $cash['purchase_invoice_id']);
        }
        foreach ($facts['invoice_settlements'] as $settlement) if ($settlement['status'] === 'confirmed') $add('entry', (int) $settlement['journal_entry_id'], $settlement['doc_type'], (int) $settlement['doc_id']);
        $agreements = array_column($facts['offset_agreements'], null, 'id');
        foreach ($facts['offset_agreement_items'] as $item) {
            $agreement = $agreements[$item['agreement_id']] ?? null;
            if (($agreement['status'] ?? null) !== 'confirmed') continue;
            $add('entry', (int) $agreement['journal_entry_id'], $item['doc_type'], (int) $item['doc_id']);
        }
        foreach ($facts['journal_entry_document_links'] as $link) $add('manual', (int) $link['entry_id'], $link['doc_type'], (int) $link['doc_id']);
        $origins = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($row['currency_code'] !== null && $row['currency_code'] !== 'CZK' && in_array(substr($row['account_code'], 0, 3), ['211', '221'], true)) {
                $origins[$id] = [['kind' => 'cash_account', 'account_id' => (int) $row['account_id'], 'currency_code' => $row['currency_code']]];
                continue;
            }
            $type = $row['source_type'];
            $source = (int) $row['source_id'];
            if (isset($documents[$type][$source])) $candidates = isset($canonical[$type][$source]) ? [['kind' => 'document', 'doc_type' => $type, 'doc_id' => $canonical[$type][$source]]] : [];
            elseif ($type === 'manual') $candidates = array_values($entries['manual:' . $row['entry_id']] ?? []);
            else $candidates = array_values($entries[$type . ':' . $source] ?? $entries['entry:' . $row['entry_id']] ?? []);
            $origins[$id] = array_values(array_filter($candidates, static fn (array $origin): bool => isset($accounts[$origin['doc_type'] . ':' . $origin['doc_id']][$row['account_id'] . ':' . (($row['currency_code'] === null || $row['currency_code'] === 'CZK') ? $documents[$origin['doc_type']][$origin['doc_id']]['currency_code'] : $row['currency_code'])])));
            if (count($candidates) > 1 && count($origins[$id]) === 1) $origins[$id] = [];
        }
        return ['origins' => $origins, 'facts_hash' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR)), 'facts' => $facts];
    }
}
