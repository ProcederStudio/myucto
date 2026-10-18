<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\JournalLineAmount;

final readonly class FxOpeningCarryAnchor
{
    private function __construct(
        public array $entries,
        public int $periodId,
        public string $startsOn,
        public ?int $legacyEntryId,
    ) {}

    public static function fromSnapshot(array $snapshot, array $prior, array $open): self
    {
        $payload = $open['payload'];
        $periodId = self::id($payload['next_period_id'] ?? null);
        $period = null;
        foreach ($snapshot['periods'] as $candidate) if ((int) $candidate['id'] === $periodId) $period = $candidate;
        self::check($period !== null && (int) $period['supplier_id'] === (int) $prior['supplier_id']
            && $period['starts_on'] === (new \DateTimeImmutable($prior['ends_on']))->modify('+1 day')->format('Y-m-d'));
        $retained = ($payload['opening_source'] ?? null) === 'taken_over';
        if ($retained) {
            self::check(($payload['entry_id'] ?? null) === null && is_array($payload['taken_over_entry_ids'] ?? null)
                && array_is_list($payload['taken_over_entry_ids']) && $payload['taken_over_entry_ids'] !== []);
            $ids = array_map(self::id(...), $payload['taken_over_entry_ids']);
        } else {
            $ids = [self::id($payload['entry_id'] ?? null)];
        }
        self::check(count(array_unique($ids)) === count($ids));
        sort($ids, SORT_NUMERIC);
        $live = [];
        foreach ($snapshot['entries'] as $entry) {
            if ((int) $entry['supplier_id'] === (int) $prior['supplier_id'] && (int) $entry['period_id'] === $periodId
                && $entry['source_type'] === 'opening' && $entry['posted_at'] !== null && $entry['reversed_by'] === null
                && isset($entry['is_reversal']) && !(bool) $entry['is_reversal']) $live[] = (int) $entry['id'];
        }
        sort($live, SORT_NUMERIC);
        self::check($live === $ids);
        $entries = [];
        $lineIds = [];
        foreach ($ids as $id) {
            $entry = $snapshot['entries'][$id] ?? null;
            self::check($entry !== null && (int) $entry['id'] === $id && $entry['entry_date'] >= $period['starts_on']
                && $entry['entry_date'] <= $period['ends_on'] && $entry['lines'] !== []);
            if (!$retained) self::check((int) $entry['source_id'] === $periodId);
            $balance = 0;
            foreach ($entry['lines'] as $line) {
                $lineId = self::id($line['id']);
                self::check(!isset($lineIds[$lineId]) && (int) $line['entry_id'] === $id
                    && (int) $line['supplier_id'] === (int) $prior['supplier_id']);
                $lineIds[$lineId] = true;
                $balance += ($line['side'] === 'debit' ? 1 : -1) * JournalLineAmount::signedCents($line);
            }
            if ($balance !== 0) throw new \DomainException('historical_opening_anchor_mismatch');
            $entries[$id] = $entry;
        }
        return new self($entries, $periodId, $period['starts_on'], $retained ? null : $ids[0]);
    }

    public function entryIds(): array
    {
        return array_keys($this->entries);
    }

    public function key(): string
    {
        return implode(':', $this->entryIds());
    }

    public function assertLedgerMembership(array $ledger, array $accounts, string $asOf): void
    {
        $expected = [];
        foreach ($this->entries as $entry) {
            self::check($entry['entry_date'] <= $asOf);
            foreach ($entry['lines'] as $line) if (isset($accounts[(int) $line['account_id']])) $expected[] = (int) $line['id'];
        }
        $observed = [];
        foreach ($ledger as $line) if (isset($this->entries[(int) $line['entry_id']], $accounts[(int) $line['account_id']])
            && $line['entry_date'] <= $asOf && $line['posted_at'] !== null) $observed[] = (int) $line['id'];
        sort($expected, SORT_NUMERIC);
        sort($observed, SORT_NUMERIC);
        self::check($expected === $observed);
    }

    private static function id(mixed $value): int
    {
        self::check(is_int($value) && $value > 0);
        return $value;
    }

    private static function check(bool $condition): void
    {
        if (!$condition) throw new \DomainException('historical_carry_chain_unproven');
    }
}