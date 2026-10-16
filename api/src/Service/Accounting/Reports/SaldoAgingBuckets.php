<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

/**
 * Členění otevřených položek saldokonta podle doby po splatnosti (podklad pro banky,
 * typicky do 30 / 60 / 120 dnů). Hranice volí uživatel, každá banka chce jiné rozmezí.
 *
 * Počet dní po splatnosti bere z položky sestavy (SaldoService počítá k rozvahovému
 * dni), takže členění sedí na tentýž den jako protokol. Do pásma „do splatnosti"
 * patří i záloha čekající na vyúčtování: nemá splatnost a bez ní by součet pásem
 * neseděl na Σ otevřených položek účtu.
 */
final class SaldoAgingBuckets
{
    public const DEFAULT_BOUNDARIES = [30, 60, 120];
    public const MAX_BOUNDARIES = 6;
    public const MAX_DAYS = 3650;

    /**
     * Hranice z parametru `aging` („30,60,120"). Vrací rostoucí unikátní seznam,
     * nebo null, když vstup není 1–6 celých čísel 1–3650.
     *
     * @return list<int>|null
     */
    public static function parse(string $raw): ?array
    {
        $parts = array_map('trim', explode(',', $raw));
        $out = [];
        foreach ($parts as $part) {
            if (preg_match('/^[0-9]{1,4}$/', $part) !== 1) {
                return null;
            }
            $days = (int) $part;
            if ($days < 1 || $days > self::MAX_DAYS) {
                return null;
            }
            $out[$days] = $days;
        }
        if ($out === [] || count($out) > self::MAX_BOUNDARIES) {
            return null;
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Pásma pro dané hranice: „do splatnosti", „1–30 dní", …, „nad 120 dní".
     *
     * @param list<int> $boundaries
     * @return list<array{key:string, label:string}>
     */
    public static function buckets(array $boundaries): array
    {
        $out = [['key' => 'not_due', 'label' => 'Do splatnosti']];
        $from = 1;
        foreach ($boundaries as $to) {
            $out[] = ['key' => 'd' . $to, 'label' => $from === $to ? "{$to} dní" : "{$from}–{$to} dní"];
            $from = $to + 1;
        }
        $last = $boundaries[count($boundaries) - 1];
        $out[] = ['key' => 'over', 'label' => "Nad {$last} dní"];
        return $out;
    }

    /**
     * Klíč pásma pro počet dní po splatnosti.
     *
     * @param list<int> $boundaries
     */
    public static function bucketOf(int $daysOverdue, array $boundaries): string
    {
        if ($daysOverdue <= 0) {
            return 'not_due';
        }
        foreach ($boundaries as $to) {
            if ($daysOverdue <= $to) {
                return 'd' . $to;
            }
        }
        return 'over';
    }

    /**
     * Rozpad sestavy SaldoService::build() do pásem po účtech a partnerech.
     *
     * @param array<string,mixed> $report
     * @param list<int> $boundaries
     * @return array{boundaries:list<int>, buckets:list<array{key:string,label:string}>, accounts:list<array<string,mixed>>}
     */
    public static function build(array $report, array $boundaries): array
    {
        $buckets = self::buckets($boundaries);
        $zero = array_fill_keys(array_column($buckets, 'key'), 0.0);
        $accounts = [];
        foreach (($report['accounts'] ?? []) as $block) {
            $totals = $zero;
            $partners = [];
            foreach (($block['partners'] ?? []) as $partner) {
                $amounts = $zero;
                foreach (($partner['items'] ?? []) as $item) {
                    $key = self::bucketOf((int) ($item['days_overdue'] ?? 0), $boundaries);
                    $amounts[$key] = round($amounts[$key] + (float) $item['remaining_czk'], 2);
                }
                foreach ($amounts as $key => $amount) {
                    $totals[$key] = round($totals[$key] + $amount, 2);
                }
                $partners[] = [
                    'partner_name' => (string) $partner['partner_name'],
                    'amounts'      => $amounts,
                    'total'        => round(array_sum($amounts), 2),
                ];
            }
            $accounts[] = [
                'account'  => $block['account'],
                'partners' => $partners,
                'totals'   => $totals,
                'total'    => round(array_sum($totals), 2),
            ];
        }
        return ['boundaries' => $boundaries, 'buckets' => $buckets, 'accounts' => $accounts];
    }
}
