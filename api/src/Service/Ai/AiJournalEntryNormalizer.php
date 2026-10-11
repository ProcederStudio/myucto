<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

/**
 * Serverová kontrola návrhů zápisů do deníku od AI. Model se nevěří: částky se zaokrouhlí
 * na haléře, nekladné řádky a neplatné strany vypadnou, vyrovnanost MD = D se spočítá
 * v celých haléřích a kódy, které ve firmě neexistují, se vrátí v `unknown_accounts`.
 */
final class AiJournalEntryNormalizer
{
    public const MAX_ENTRIES = 20;
    public const MAX_LINES = 50;

    /**
     * @param mixed                $raw        pole zápisů tak, jak je vrátil model
     * @param array<string,string> $chartNames kód účtu => název (všechny účty firmy)
     * @return list<array{entry_date:string, description:string, document_no:?string,
     *   lines:list<array{account_code:string, account_name:?string, side:string, amount:float, note:?string}>,
     *   balanced:bool, unknown_accounts:list<string>}>
     */
    public static function normalize(mixed $raw, array $chartNames, string $today): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_ENTRIES) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $lines = [];
            $debit = 0;
            $credit = 0;
            $unknown = [];
            foreach (array_slice(array_values(is_array($entry['lines'] ?? null) ? $entry['lines'] : []), 0, self::MAX_LINES) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $code = preg_replace('/\s+/u', '', (string) ($line['account_code'] ?? '')) ?? '';
                $side = self::side($line['side'] ?? null);
                $amount = is_numeric($line['amount'] ?? null) ? round((float) $line['amount'], 2) : 0.0;
                if ($code === '' || mb_strlen($code) > 20 || $side === null || $amount <= 0) {
                    continue;
                }
                $cents = (int) round($amount * 100);
                if ($side === 'debit') {
                    $debit += $cents;
                } else {
                    $credit += $cents;
                }
                $known = array_key_exists($code, $chartNames);
                if (!$known && !in_array($code, $unknown, true)) {
                    $unknown[] = $code;
                }
                $note = trim((string) ($line['note'] ?? ''));
                $lines[] = [
                    'account_code' => $code,
                    'account_name' => $known ? $chartNames[$code] : null,
                    'side' => $side,
                    'amount' => (float) ($cents / 100),
                    'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
                ];
            }
            if ($lines === []) {
                continue;
            }
            $description = trim((string) ($entry['description'] ?? ''));
            $documentNo = trim((string) ($entry['document_no'] ?? ''));
            $out[] = [
                'entry_date' => self::date($entry['entry_date'] ?? null, $today),
                'description' => $description !== '' ? mb_substr($description, 0, 255) : 'Návrh AI asistenta',
                'document_no' => $documentNo !== '' ? mb_substr($documentNo, 0, 64) : null,
                'lines' => $lines,
                'balanced' => $debit > 0 && $debit === $credit,
                'unknown_accounts' => $unknown,
            ];
        }
        return $out;
    }

    private static function side(mixed $side): ?string
    {
        $s = mb_strtolower(trim((string) $side));
        return match ($s) {
            'debit', 'md', 'má dáti', 'ma dati' => 'debit',
            'credit', 'd', 'dal', 'dál' => 'credit',
            default => null,
        };
    }

    private static function date(mixed $value, string $today): string
    {
        $v = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $v;
        }
        return $today;
    }
}
