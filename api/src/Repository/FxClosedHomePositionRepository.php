<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

final class FxClosedHomePositionRepository
{
    public function __construct(private readonly Connection $db) {}

    public function proves(int $supplierId, array $item, array $document, string $asOf, array $evidence, bool $currentRead = false): bool
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Closed FX position proof requires the caller snapshot.');
        if ($currentRead) (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        if (strtoupper((string) $document['currency_code']) !== 'CZK'
            || $evidence['findings'] !== ['document_currency_conflict']
            || $evidence['advance_document'] !== '0.00' || $evidence['manual_members'] !== []
            || $evidence['remaining_foreign'] !== '0.00' || $evidence['booked_czk'] !== '0.00'
            || bccomp($evidence['nominal_document'], '0', 2) === 0
            || bccomp($evidence['nominal_document'], $evidence['settled_document'], 2) !== 0) return false;
        $type = (string) $item['doc_type'];
        $id = (int) $item['doc_id'];
        if (!in_array($type, ['invoice', 'purchase_invoice'], true)) throw new \InvalidArgumentException('Invalid FX document type.');
        $sources = [];
        foreach ($evidence['members'] as $member) {
            if (!$member['included_as_of']) continue;
            $bank = (int) ($member['bank_transaction_id'] ?? 0);
            $cash = (int) ($member['cash_document_id'] ?? 0);
            if (($bank > 0) === ($cash > 0)) return false;
            $sourceType = $bank > 0 ? 'bank' : 'cash';
            $sourceId = $bank ?: $cash;
            $key = $sourceType . ':' . $sourceId;
            if (isset($sources[$key])) continue;
            if (!$this->belongsOnlyTo($supplierId, $sourceType, $sourceId, $type, $id, $currentRead)) return false;
            $source = $evidence['posted_sources'][$key] ?? null;
            if ($source === null || $source['effective_date'] === null || $source['effective_date'] > $asOf) return false;
            $sources[$key] = $source;
        }
        if ($sources === []) return false;
        $entries = $this->rows("SELECT e.id,e.entry_date,e.posted_at,r.entry_date AS reversed_on FROM journal_entries e LEFT JOIN journal_entries r ON r.id=e.reversed_by AND r.supplier_id=e.supplier_id WHERE e.supplier_id=? AND e.source_type=? AND e.source_id=? ORDER BY e.id", [$supplierId, $type, $id], $currentRead);
        $original = $this->activeLines($supplierId, $entries, $asOf, [[$type, $id]], $currentRead);
        if ($original === null || $original === []) return false;
        $settlements = [];
        foreach ($sources as $source) {
            $lines = $this->activeLines($supplierId, $source['entries'], $asOf, [[$type, $id], [$source['source_type'], $source['source_id']]], $currentRead);
            if ($lines === null || $lines === []) return false;
            array_push($settlements, ...$lines);
        }
        $accountId = (int) $item['account_id'];
        $original = array_values(array_filter($original, static fn (array $line): bool => (int) $line['account_id'] === $accountId));
        $settlements = array_values(array_filter($settlements, static fn (array $line): bool => (int) $line['account_id'] === $accountId));
        if ($original === [] || $settlements === []) return false;
        $originalCents = 0;
        foreach ($original as $line) {
            if ($line['currency_code'] !== $item['currency_code'] || $line['amount_foreign'] === null || $line['fx_rate'] === null) return false;
            $originalCents += self::signedCents($line);
        }
        $orientation = $type === 'invoice' ? 1 : -1;
        if ($originalCents * $orientation !== JournalLineAmount::cents($evidence['nominal_document'])) return false;
        foreach ($settlements as $line) {
            if (!in_array($line['currency_code'], [null, '', 'CZK'], true)
                || ($line['amount_foreign'] !== null && bccomp((string) $line['amount_foreign'], '0', 2) !== 0)) return false;
        }
        $all = [...$original, ...$settlements];
        $ids = array_map(static fn (array $line): int => (int) $line['id'], $all);
        if (count($ids) !== count(array_unique($ids))) return false;
        $dimensions = new DimensionAssignmentRepository($this->db);
        $assignments = $dimensions->lineDimensions($supplierId, $ids, $currentRead);
        $splits = $dimensions->lineSplits($supplierId, $ids, $currentRead);
        $balances = [];
        foreach ($all as $line) {
            $lineId = (int) $line['id'];
            $tuple = json_encode([$line['cost_center'], $line['project_id'], $assignments[$lineId] ?? [], $splits[$lineId] ?? []], JSON_THROW_ON_ERROR);
            $balances[$tuple] = ($balances[$tuple] ?? 0) + self::signedCents($line);
        }
        return array_all($balances, static fn (int $amount): bool => $amount === 0);
    }

    private function belongsOnlyTo(int $supplierId, string $sourceType, int $sourceId, string $type, int $id, bool $currentRead): bool
    {
        $owners = [];
        if ($sourceType === 'bank') {
            foreach ($this->rows('SELECT invoice_id,purchase_invoice_id FROM payment_matches WHERE supplier_id=? AND bank_transaction_id=? ORDER BY id', [$supplierId, $sourceId], $currentRead) as $row) {
                if ($row['invoice_id'] !== null) $owners[] = ['invoice', (int) $row['invoice_id']];
                if ($row['purchase_invoice_id'] !== null) $owners[] = ['purchase_invoice', (int) $row['purchase_invoice_id']];
            }
            foreach ($this->rows('SELECT invoice_id FROM invoice_payments WHERE supplier_id=? AND bank_transaction_id=? ORDER BY id', [$supplierId, $sourceId], $currentRead) as $row) $owners[] = ['invoice', (int) $row['invoice_id']];
        } else {
            foreach ($this->rows('SELECT invoice_id,purchase_invoice_id,invoice_payment_id FROM cash_documents WHERE supplier_id=? AND id=?', [$supplierId, $sourceId], $currentRead) as $row) {
                if ($row['invoice_id'] !== null) $owners[] = ['invoice', (int) $row['invoice_id']];
                if ($row['purchase_invoice_id'] !== null) $owners[] = ['purchase_invoice', (int) $row['purchase_invoice_id']];
                if ($row['invoice_payment_id'] !== null) {
                    foreach ($this->rows('SELECT invoice_id FROM invoice_payments WHERE supplier_id=? AND id=?', [$supplierId, (int) $row['invoice_payment_id']], $currentRead) as $payment) $owners[] = ['invoice', (int) $payment['invoice_id']];
                }
            }
        }
        return $owners !== [] && array_all($owners, static fn (array $owner): bool => $owner === [$type, $id]);
    }

    private function activeLines(int $supplierId, array $entries, string $asOf, array $allowedLinks, bool $currentRead): ?array
    {
        $result = [];
        foreach ($entries as $entry) {
            if ($entry['posted_at'] === null || $entry['entry_date'] > $asOf || ($entry['reversed_on'] !== null && $entry['reversed_on'] <= $asOf)) continue;
            foreach ($this->rows('SELECT doc_type,doc_id FROM journal_entry_document_links WHERE supplier_id=? AND entry_id=? ORDER BY id', [$supplierId, (int) $entry['id']], $currentRead) as $link) {
                if (!in_array([$link['doc_type'], (int) $link['doc_id']], $allowedLinks, true)) return null;
            }
            $lines = $this->rows('SELECT id,account_id,side,amount,is_red_storno,currency_code,amount_foreign,fx_rate,cost_center,project_id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id', [$supplierId, (int) $entry['id']], $currentRead);
            if ($lines === [] || array_sum(array_map(self::signedCents(...), $lines)) !== 0) return null;
            array_push($result, ...$lines);
        }
        return $result;
    }

    private static function signedCents(array $line): int
    {
        return ($line['side'] === 'debit' ? 1 : -1) * JournalLineAmount::signedCents($line);
    }

    private function rows(string $sql, array $params, bool $currentRead): array
    {
        $stmt = $this->db->pdo()->prepare($sql . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
