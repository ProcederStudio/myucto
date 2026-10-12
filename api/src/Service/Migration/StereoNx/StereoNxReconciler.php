<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\Shared\ReconciliationCriteria;
use MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation;

/** Zdrojovou předvahu sestaví adaptér, porovnání a kritéria patří společné vrstvě. */
final class StereoNxReconciler
{
    public function __construct(
        private readonly AccountingPeriodRepository $periods,
        private readonly TrialBalanceService $trialBalance,
    ) {}

    public function run(int $supplierId, array $plan): array
    {
        $years = [];
        foreach ($plan['entries'] as $entry) $years[(int) ($entry['posting_year'] ?? substr($entry['date'], 0, 4))] = true;
        ksort($years);
        $out = [];
        foreach (array_keys($years) as $year) {
            $period = $this->periods->findByYear($supplierId, $year);
            if ($period === null) throw new StereoNxException('reconciliation_period_missing', 'Chybí období pro kontrolu převodu.');
            $tb = $this->trialBalance->build($supplierId, (int) $period['id'], null, null, false, false);
            $source = self::sourceBalance($plan, (string) $period['starts_on'], (string) $period['ends_on']);
            $diffs = TrialBalanceReconciliation::compare(TrialBalanceReconciliation::synthetic($tb['rows']), $source);
            $checks = TrialBalanceReconciliation::checks($tb, 'stereo_nx_journal', $diffs, count($source));
            $out[] = ['year' => $year, 'period_id' => (int) $period['id'],
                'ok' => TrialBalanceReconciliation::allOk($checks), 'checks' => $checks,
                'totals' => $tb['totals'], 'journal_diffs' => $diffs, 'money_report' => null, 'documents' => []];
        }
        return ['reconciliation' => $out, 'criteria' => ReconciliationCriteria::summarize($out)];
    }

    /** @return array<string,array{float,float,float}> netto PS, obrat, KS po syntetikách */
    public static function sourceBalance(array $plan, string $from, string $to): array
    {
        $types = [];
        foreach ($plan['chart'] as $account) $types[$account['code']] = $account['source_type'];
        $anchor = null;
        foreach ($plan['entries'] as $entry) {
            if ($entry['is_opening'] && $entry['date'] <= $from) $anchor = max($anchor ?? '', $entry['date']);
        }
        $out = [];
        foreach ($plan['entries'] as $entry) {
            $date = $entry['date'];
            if ($date > $to) continue;
            $opening = $date < $from || ($date === $from && $entry['is_opening']);
            foreach ([[$entry['debit'], 1], [$entry['credit'], -1]] as [$code, $side]) {
                if ($opening && in_array($types[$code] ?? null, ['N', 'V'], true) && $date < $from) continue;
                if ($opening && $anchor !== null && $date < $anchor) continue;
                $synthetic = substr($code, 0, 3);
                $out[$synthetic] ??= [0.0, 0.0, 0.0];
                $amount = $side * $entry['amount_cents'] / 100 * ($entry['is_red_storno'] ? -1 : 1);
                $out[$synthetic][$opening ? 0 : 1] += $amount;
                $out[$synthetic][2] += $amount;
            }
        }
        foreach ($out as &$totals) $totals = array_map(static fn (float $value): float => round($value, 2), $totals);
        return $out;
    }
}
