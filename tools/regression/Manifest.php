<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Manifest
{
    public static function discover(\PDO $pdo, string $asOf): array
    {
        $periods = $pdo->query('SELECT p.id, p.supplier_id, p.fiscal_year, p.starts_on, p.ends_on, s.taxpayer_type FROM accounting_periods p JOIN supplier s ON s.id = p.supplier_id ORDER BY p.supplier_id, p.starts_on, p.id')->fetchAll(\PDO::FETCH_ASSOC);
        $cases = [];
        $taxYears = [];
        $vatMonths = [];
        $notApplicable = ['future_accounting_periods' => 0, 'incomplete_tax_periods' => 0, 'non_po_tax_periods' => 0];
        foreach ($periods as $period) {
            $from = (string) $period['starts_on'];
            $to = min((string) $period['ends_on'], $asOf);
            if ($from > $to) {
                ++$notApplicable['future_accounting_periods'];
                continue;
            }
            $sid = (int) $period['supplier_id'];
            $pid = (int) $period['id'];
            foreach (['balance_sheet', 'income_statement', 'income_statement_function'] as $kind) {
                foreach (['full', 'small', 'micro'] as $scope) {
                    self::add($cases, $kind, [$sid, $pid, $to, $scope, null]);
                }
            }
            foreach ([false, true] as $analytics) {
                foreach ([false, true] as $afterClosing) {
                    self::add($cases, 'trial_balance', [$sid, $pid, $from, $to, $analytics, $afterClosing, null]);
                    self::add($cases, 'general_ledger', [$sid, $pid, $from, $to, $analytics, [], $afterClosing]);
                }
            }
            if ($period['ends_on'] > $asOf) {
                ++$notApplicable['incomplete_tax_periods'];
            } elseif ($period['taxpayer_type'] === 'po') {
                $taxYears[$sid . ':' . $period['fiscal_year']] = [$sid, (int) $period['fiscal_year']];
            } else {
                ++$notApplicable['non_po_tax_periods'];
            }
            $month = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
            while ($month->format('Y-m-d') <= $to) {
                // Partial months are not valid VAT periods; no implicit future data filter.
                if ($month->format('Y-m-t') <= $asOf) {
                    $vatMonths[$sid . ':' . $month->format('Y-m')] = [$sid, (int) $month->format('Y'), (int) $month->format('m')];
                }
                $month = $month->modify('+1 month');
            }
        }
        foreach ($taxYears as [$sid, $year]) {
            self::add($cases, 'dppo_preview', [$sid, $year, 'po']);
            self::add($cases, 'dppo_inputs', [$sid, $year, 'po']);
            self::add($cases, 'dppo_balance_due', [$sid, $year, 'po']);
            self::add($cases, 'dppo_data', [$sid, $year]);
        }
        foreach ($vatMonths as [$sid, $year, $month]) {
            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = (new \DateTimeImmutable($from))->format('Y-m-t');
            self::add($cases, 'vat_ledger', [$sid, $from, $to, false]);
            self::add($cases, 'vat_return_ledger', [$sid, $from, $to, false]);
            foreach (['vat_book', 'vat_return', 'control_statement', 'recapitulative_statement'] as $kind) {
                self::add($cases, $kind, [$sid, $year, $month, 'monthly']);
            }
        }
        ksort($cases);
        return [
            'cases' => $cases,
            'coverage' => [
                'complete' => false,
                'implemented' => ['accounting_period_reports', 'dppo_read_only', 'monthly_vat'],
                'not_implemented' => ['dpfo', 'tax_evidence', 'dimensions', 'closing_previews', 'vat_quarterly', 'xml_numeric_projection', 'suppliers_without_accounting_periods'],
                'not_applicable' => $notApplicable,
                'period_count' => count($periods),
            ],
        ];
    }

    private static function add(array &$cases, string $kind, array $args): void
    {
        $case = ['kind' => $kind, 'args' => $args];
        $cases[substr(hash('sha256', json_encode($case, JSON_THROW_ON_ERROR)), 0, 24)] = $case;
    }

    public static function invoke(object $container, array $case): mixed
    {
        $accounting = 'MyInvoice\\Service\\Accounting\\Reports\\';
        $tax = 'MyInvoice\\Service\\Tax\\Return\\';
        $vat = 'MyInvoice\\Service\\Report\\';
        [$class, $method] = match ($case['kind']) {
            'balance_sheet' => [$accounting . 'FinancialStatementService', 'balanceSheet'],
            'income_statement' => [$accounting . 'FinancialStatementService', 'incomeStatement'],
            'income_statement_function' => [$accounting . 'FinancialStatementService', 'incomeStatementByFunction'],
            'trial_balance' => [$accounting . 'TrialBalanceService', 'build'],
            'general_ledger' => [$accounting . 'GeneralLedgerService', 'build'],
            'dppo_preview' => [$tax . 'TaxReturnService', 'previewReadOnly'],
            'dppo_inputs' => [$tax . 'TaxReturnService', 'readOnlyInputs'],
            'dppo_balance_due' => [$tax . 'TaxReturnService', 'balanceDueReadOnly'],
            'dppo_data' => [$tax . 'DppoReturnDataProvider', 'gather'],
            'vat_ledger' => [$vat . 'VatLedgerService', 'rows'],
            'vat_return_ledger' => [$vat . 'VatLedgerService', 'returnRows'],
            'vat_book' => [$vat . 'DphBookBuilder', 'build'],
            'vat_return' => [$vat . 'DphPriznaniBuilder', 'build'],
            'control_statement' => [$vat . 'KontrolniHlaseniBuilder', 'build'],
            'recapitulative_statement' => [$vat . 'SouhrnneHlaseniBuilder', 'build'],
            default => throw new \RuntimeException('unknown_manifest_case'),
        };
        $args = $case['args'];
        if ($case['kind'] === 'dppo_data') {
            $args[] = $container->get($tax . 'TaxReturnService')->readOnlyInputs($args[0], $args[1], 'po')['inputs'];
        }
        return $container->get($class)->$method(...$args);
    }

    public static function normalize(mixed $data, string $asOf, string $path = ''): mixed
    {
        if (!is_array($data)) {
            if (is_object($data)) {
                throw new \RuntimeException('unreviewed_result_object');
            }
            return $data;
        }
        foreach ($data as $key => $value) {
            if ($key === 'missing_rates' && $value !== []) {
                throw new \RuntimeException('missing_exchange_rates');
            }
            // Only report presentation metadata is normalized. Dates in financial data remain intact.
            if ($key === 'prepared_at' && $path === '/entity') {
                $data[$key] = $asOf . ' 00:00';
            } else {
                $data[$key] = self::normalize($value, $asOf, $path . '/' . $key);
            }
        }
        return $data;
    }
}
