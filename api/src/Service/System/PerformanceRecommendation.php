<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Database\Connection;
use Throwable;

/**
 * Objem dat celé instance pro doporučení výkonnostní instance.
 *
 * Počítá se přes všechny firmy: výkon hostingu sdílí celá instance, ne jedna
 * firma. `COUNT(*)` nad statisíci řádků není zadarmo a stránka hostingu ho
 * nepotřebuje čerstvý, proto se výsledek drží v `app_meta` po
 * {@see self::TTL_SECONDS}.
 */
final class PerformanceRecommendation
{
    public const KEY = 'performance_usage';
    public const TTL_SECONDS = 3600;
    public const THRESHOLD_INVOICES = 100000;
    public const THRESHOLD_JOURNAL_ENTRIES = 500000;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{invoices:int,journal_entries:int,recommended:bool,thresholds:array{invoices:int,journal_entries:int}}
     */
    public function usage(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $counts = $this->cached($now) ?? $this->measure($now);

        return [
            'invoices' => $counts['invoices'],
            'journal_entries' => $counts['journal_entries'],
            'recommended' => self::isRecommended($counts['invoices'], $counts['journal_entries']),
            'thresholds' => [
                'invoices' => self::THRESHOLD_INVOICES,
                'journal_entries' => self::THRESHOLD_JOURNAL_ENTRIES,
            ],
        ];
    }

    public static function isRecommended(int $invoices, int $journalEntries): bool
    {
        return $invoices > self::THRESHOLD_INVOICES || $journalEntries > self::THRESHOLD_JOURNAL_ENTRIES;
    }

    /** @return array{invoices:int,journal_entries:int}|null */
    private function cached(\DateTimeImmutable $now): ?array
    {
        try {
            if (!$this->db->hasTable('app_meta')) {
                return null;
            }
            $stmt = $this->db->pdo()->prepare('SELECT v FROM app_meta WHERE k = ?');
            $stmt->execute([self::KEY]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !is_int($decoded['invoices'] ?? null)
            || !is_int($decoded['journal_entries'] ?? null)
            || !is_string($decoded['measured_at'] ?? null)
        ) {
            return null;
        }
        $measured = \DateTimeImmutable::createFromFormat(DATE_ATOM, $decoded['measured_at']);
        if (!$measured instanceof \DateTimeImmutable
            || $now->getTimestamp() - $measured->getTimestamp() >= self::TTL_SECONDS
            || $measured > $now
        ) {
            return null;
        }

        return ['invoices' => $decoded['invoices'], 'journal_entries' => $decoded['journal_entries']];
    }

    /** @return array{invoices:int,journal_entries:int} */
    private function measure(\DateTimeImmutable $now): array
    {
        $counts = [
            'invoices' => $this->count('invoices'),
            'journal_entries' => $this->count('journal_entries'),
        ];
        try {
            if ($this->db->hasTable('app_meta')) {
                $this->db->pdo()->prepare(
                    'INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
                )->execute([self::KEY, json_encode($counts + ['measured_at' => $now->format(DATE_ATOM)], JSON_THROW_ON_ERROR)]);
            }
        } catch (Throwable) {
        }

        return $counts;
    }

    private function count(string $table): int
    {
        try {
            if (!$this->db->hasTable($table)) {
                return 0;
            }
            $stmt = $this->db->pdo()->query('SELECT COUNT(*) FROM ' . $table);

            return $stmt === false ? 0 : (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}
