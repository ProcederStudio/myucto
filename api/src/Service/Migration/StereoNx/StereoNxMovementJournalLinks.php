<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use MyInvoice\Service\Bank\BankTransactionPostingScope;

/** Fyzické pohyby používají původní kontace; bez doložené vazby se nesmějí automaticky doúčtovat. */
final class StereoNxMovementJournalLinks
{
    public function __construct(private readonly Connection $db, private readonly StereoNxImportMap $map) {}

    /**
     * Rok klíče je účetní období kontace (posting_year podle kalendáře firmy), stejně jako
     * rok v identitě pohybu ze {@see StereoNxAccountingPayments}. Bez posting_year
     * (plán bez kalendáře firmy) se použije kalendářní rok data.
     *
     * @param array<string,mixed> $journalPlan @return array<string,array<string,list<array<string,mixed>>>>
     */
    public static function indexMovementEntries(array $journalPlan): array
    {
        $entries = [];
        foreach ($journalPlan['accounting_plan']['entries'] ?? [] as $entry) {
            if ($entry['is_opening']) continue;
            $parts = json_decode($entry['source_key'], true, flags: JSON_THROW_ON_ERROR);
            if (!in_array($parts[0] ?? '', ['B', 'P'], true)) continue;
            $kind = $parts[0] === 'B' ? 'bank' : 'cash';
            $year = is_int($entry['posting_year'] ?? null) ? (string) $entry['posting_year'] : substr($entry['date'], 0, 4);
            $key = [$year, $parts[1], $parts[2]];
            if ($kind === 'bank') $key[] = $parts[3];
            $entries[$kind][json_encode($key, JSON_THROW_ON_ERROR)][] = $entry;
        }
        return $entries;
    }

    /** @param array<string,mixed> $movement @param list<array<string,mixed>> $entries */
    public static function verifiesNonVatCash(array $movement, array $entries): bool
    {
        if ($entries === [] || ($movement['currency'] ?? null) !== 'CZK') return false;
        $amount = $movement['amount'] ?? null;
        if ((!is_int($amount) && !is_float($amount)) || $amount == 0.0) return false;
        $expected = (int) round(abs($amount) * 100, 0, PHP_ROUND_HALF_UP);
        $sum = 0;
        $seen = [];
        foreach ($entries as $entry) {
            $key = (string) ($entry['source_key'] ?? '');
            if ($key === '' || isset($seen[$key]) || ($entry['date'] ?? null) !== $movement['date']
                || ($entry['is_opening'] ?? null) !== false || ($entry['is_red_storno'] ?? null) !== false
                || !is_int($entry['amount_cents'] ?? null) || $entry['amount_cents'] <= 0) return false;
            $seen[$key] = true;
            $debit = trim((string) ($entry['debit'] ?? ''));
            $credit = trim((string) ($entry['credit'] ?? ''));
            $debitCash = preg_match('/^211[0-9A-Za-z]*$/D', $debit) === 1;
            $creditCash = preg_match('/^211[0-9A-Za-z]*$/D', $credit) === 1;
            if ($debitCash === $creditCash || ($amount > 0 && !$debitCash) || ($amount < 0 && !$creditCash)) return false;
            $sum += $entry['amount_cents'];
            if ($sum > $expected) return false;
        }
        return $sum === $expected;
    }

    /** @return list<array<string,mixed>> Pohyby bez ověřené kontace pro protokol. */
    public function write(array $context, array $plan): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new StereoNxException('transaction_required', 'Vazby pohybů vyžadují transakci.');
        $supplier = $context['supplier_id'];
        $ico = $context['ico'];
        $company = $context['company_index'];
        $entries = self::indexMovementEntries((array) ($plan['journal_plan'] ?? []));
        $reviews = [];
        $linker = new JournalEntryLinker($this->db, 'Stereo NX', true);
        foreach (['bank' => 'bank_transactions', 'cash' => 'cash_transactions'] as $kind => $section) {
            foreach ($plan[$section] ?? [] as $movement) {
                $key = $movement['source_key'];
                $id = $context['ids'][$kind][$key] ?? null;
                if ($id === null) continue;
                $matched = $entries[$kind][$key] ?? [];
                if ($kind === 'cash' && ($movement['verified_non_vat_cash'] ?? false) === true
                    && !self::verifiesNonVatCash($movement, $matched)) {
                    throw new StereoNxException('cash_posting_unverified', 'Samostatný pokladní pohyb nemá ověřenou kontaci.');
                }
                if ($matched === []) {
                    $linker->markUnverifiedMovement($supplier, $kind, $id, BankTransactionPostingScope::MIGRATION_REVIEW_REASON,
                        'Před obnovením automatického účtování ověřte vazbu na převzatý deník Stereo NX.');
                    $reviews[] = ['kind' => $kind, 'source_key' => $key,
                        'document_no' => (string) ($movement['document_no'] ?? ''),
                        'review_codes' => ['movement_journal_unverified'], 'target_id' => $id,
                        'statement_key' => $movement['statement_key'] ?? null];
                    continue;
                }
                usort($matched, static fn (array $a, array $b): int => [$a['date'], $a['source_key']] <=> [$b['date'], $b['source_key']]);
                $ids = [];
                foreach ($matched as $entry) {
                    $mapped = $this->map->get($supplier, $ico, $company, 'accounting_journal', $entry['source_key']);
                    if ($mapped === null || !hash_equals($mapped['source_hash'], $entry['source_hash'])) {
                        throw new StereoNxException('source_changed', 'Zdrojová kontace pohybu se změnila nebo nebyla převedena.');
                    }
                    if (!$linker->hasLinkableEntry($supplier, $mapped['target_id'], $entry['date'], $kind, $id)) {
                        throw new StereoNxException('mapped_target_changed', 'Převzatá kontace pohybu byla změněna.');
                    }
                    $mapKind = 'accounting_' . $kind . '_link';
                    $hash = StereoNxImportMap::fingerprint(['movement_key' => $key, 'entry_key' => $entry['source_key']]);
                    $previous = $this->map->get($supplier, $ico, $company, $mapKind, $entry['source_key']);
                    if ($previous !== null && ($previous['target_id'] !== $id || !hash_equals($previous['source_hash'], $hash))) {
                        throw new StereoNxException('source_changed', 'Zdrojová vazba pohybu se změnila.');
                    }
                    if ($previous === null) $this->map->put($supplier, $ico, $company, $mapKind, $entry['source_key'], $hash, $id);
                    $ids[] = $mapped['target_id'];
                }
                $linker->attach($supplier, $context['user_id'], $kind, 'manual', $kind, $id, $ids);
            }
        }
        return $reviews;
    }
}
