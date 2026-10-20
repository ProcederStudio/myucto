<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class SecuritiesDebt504Terms
{
    public static function normalize(array $terms): array
    {
        self::need(array_diff(array_keys($terms),['lot_reference','instrument_identity','contract_reference','classification_reference','debt_type','classification','acquired_on','maturity_on','nominal','clean_cost','acquired_interest','clean_cost_czk','acquired_interest_czk','coupon_periods','amortisation'])===[], 'debt_terms_invalid');
        foreach (['lot_reference','instrument_identity','contract_reference','classification_reference'] as $key) { self::need(SecuritiesDebt504Policy::text($terms[$key] ?? null), 'debt_contract_required'); $terms[$key]=trim($terms[$key]); }
        self::need(mb_strlen($terms['lot_reference'])<=190,'debt_lot_reference_invalid');
        self::need(in_array($terms['debt_type'] ?? null, ['coupon','discount'], true) && in_array($terms['classification'] ?? null, ['trading','held_to_maturity'], true), 'debt_classification_review_required');
        foreach (['acquired_on','maturity_on'] as $key) SecuritiesDebt504Policy::date($terms[$key] ?? null);
        self::need($terms['acquired_on'] < $terms['maturity_on'], 'debt_acquisition_date_invalid');
        foreach (['nominal','clean_cost','acquired_interest'] as $key) { SecuritiesDebt504Policy::decimal($terms[$key] ?? null); $terms[$key] = JournalLineAmount::decimal($terms[$key]); }
        self::need(bccomp($terms['nominal'],'0',2)>0 && bccomp($terms['clean_cost'],'0',2)>0, 'debt_nominal_required');
        self::need(is_array($terms['coupon_periods'] ?? null) && array_is_list($terms['coupon_periods']), 'debt_coupon_period_required');
        if ($terms['debt_type'] === 'discount') self::need($terms['coupon_periods'] === [] && $terms['acquired_interest'] === '0.00', 'debt_acquired_interest_mismatch');
        else {
            self::need($terms['coupon_periods'] !== [], 'debt_coupon_period_required');
            $previous = null;
            foreach ($terms['coupon_periods'] as $period) {
                self::need(is_array($period) && array_diff(array_keys($period),['from','to_exclusive','annual_rate_fraction','day_count','frequency','rounding','terms_reference'])===[], 'debt_coupon_period_required');
                SecuritiesDebt504Policy::coupon($period,$terms['nominal'],$period['from'] ?? '',$period['to_exclusive'] ?? '');
                self::need(($previous === null || $previous === $period['from']) && $period['to_exclusive'] <= $terms['maturity_on'], 'debt_coupon_period_invalid');
                $previous = $period['to_exclusive'];
            }
            self::need($terms['coupon_periods'][0]['from'] <= $terms['acquired_on'] && $previous === $terms['maturity_on'], 'debt_coupon_period_invalid');
            self::need(self::acquiredInterest($terms) === $terms['acquired_interest'], 'debt_acquired_interest_mismatch');
        }
        if ($terms['classification'] === 'held_to_maturity') {
            self::need(is_array($terms['amortisation'] ?? null) && array_diff(array_keys($terms['amortisation']),['method','policy_reference','rounding'])===[], 'debt_amortisation_review_required');
            SecuritiesDebt504Policy::amortisation($terms['amortisation'] + ['acquired_on'=>$terms['acquired_on'],'maturity_on'=>$terms['maturity_on']], $terms['clean_cost'],$terms['nominal'],$terms['acquired_on'],$terms['maturity_on']);
        }
        else self::need(!array_key_exists('amortisation',$terms),'debt_amortisation_ineligible');
        return $terms;
    }

    public static function acquiredInterest(array $terms): string
    {
        foreach ($terms['coupon_periods'] as $period) if ($period['from'] <= $terms['acquired_on'] && $period['to_exclusive'] > $terms['acquired_on'])
            return SecuritiesDebt504Policy::coupon($period,$terms['nominal'],$period['from'],$terms['acquired_on']);
        return '0.00';
    }

    public static function earned(array $terms,string $date): string
    {
        SecuritiesDebt504Policy::date($date);
        $end=min(SecuritiesDebt504Policy::dayAfter($date),$terms['maturity_on']); $amount='0.00';
        foreach ($terms['coupon_periods'] as $period) {
            $from=max($period['from'],$terms['acquired_on']); $to=min($period['to_exclusive'],$end);
            if ($from<$to) $amount=bcadd($amount,SecuritiesDebt504Policy::coupon($period,$terms['nominal'],$from,$to),2);
        }
        return $amount;
    }

    public static function amortised(array $terms,string $date): string
    {
        self::need($terms['classification']==='held_to_maturity','debt_amortisation_review_required');
        return SecuritiesDebt504Policy::amortisation($terms['amortisation'] + ['acquired_on'=>$terms['acquired_on'],'maturity_on'=>$terms['maturity_on']],$terms['clean_cost'],$terms['nominal'],$terms['acquired_on'],min(SecuritiesDebt504Policy::dayAfter($date),$terms['maturity_on']));
    }

    public static function need(bool $condition,string $code): void
    {
        if (!$condition) throw new ClosingException($code,'Podklady dluhového cenného papíru nejsou úplné nebo aktuální.',409);
    }
}