<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\JournalLineAmount;

final class SecuritiesDebt504Policy
{
    public const LEGAL_REFERENCE = 'https://www.mfcr.cz/assets/cs/media/Ucetnictvi_2016_Ceske-ucetni-standardy-pro-504-2002.pdf#page=14';
    public const DAY_COUNT_REFERENCE = 'https://www.sc.com/en/uploads/sites/66/content/docs/2006-ISDA-Definitions.pdf#page=22';

    public static function coupon(array $period, string $nominal, string $from, string $toExclusive): string
    {
        self::date($from);
        self::date($toExclusive);
        self::date($period['from'] ?? null);
        self::date($period['to_exclusive'] ?? null);
        self::need($period['from'] < $period['to_exclusive'] && $from >= $period['from'] && $toExclusive <= $period['to_exclusive'] && $from <= $toExclusive, 'debt_coupon_period_invalid');
        self::need(self::text($period['terms_reference'] ?? null), 'debt_contract_required');
        self::need(($period['rounding'] ?? null) === 'half_away_from_zero_2_cumulative', 'debt_rounding_review_required');
        $denominator = match ($period['day_count'] ?? null) {
            'ACT/365F' => 365,
            'ACT/360' => 360,
            default => throw new \DomainException('debt_day_count_review_required'),
        };
        self::decimal($nominal);
        self::decimal($period['annual_rate_fraction'] ?? null);
        $annual = bcmul($nominal, $period['annual_rate_fraction'], 24);
        $cumulative = static fn (string $date): string => JournalLineAmount::decimal(bcdiv(bcmul($annual, (string) self::days($period['from'], $date), 24), (string) $denominator, 24));
        return bcsub($cumulative($toExclusive), $cumulative($from), 2);
    }

    public static function amortisation(array $schedule, string $cleanCost, string $nominal, string $from, string $toExclusive): string
    {
        self::date($from);
        self::date($toExclusive);
        self::date($schedule['acquired_on'] ?? null);
        self::date($schedule['maturity_on'] ?? null);
        self::need(($schedule['method'] ?? null) === 'actual_days_linear' && self::text($schedule['policy_reference'] ?? null), 'debt_amortisation_review_required');
        self::need(($schedule['rounding'] ?? null) === 'half_away_from_zero_2_cumulative', 'debt_rounding_review_required');
        self::need($schedule['acquired_on'] < $schedule['maturity_on'] && $from >= $schedule['acquired_on'] && $toExclusive <= $schedule['maturity_on'] && $from <= $toExclusive, 'debt_amortisation_period_invalid');
        self::decimal($cleanCost);
        self::decimal($nominal);
        $difference = bcsub($nominal, $cleanCost, 24);
        $days = self::days($schedule['acquired_on'], $schedule['maturity_on']);
        $cumulative = static fn (string $date): string => JournalLineAmount::decimal(bcdiv(bcmul($difference, (string) self::days($schedule['acquired_on'], $date), 24), (string) $days, 24));
        return bcsub($cumulative($toExclusive), $cumulative($from), 2);
    }

    public static function discountTradingChange(string $marketValue, string $previousValue): array
    {
        self::decimal($marketValue);
        self::decimal($previousValue);
        return ['amount' => bcsub(JournalLineAmount::decimal($marketValue), JournalLineAmount::decimal($previousValue), 2),
            'account' => (new AccountingRoleProfile('cz_504'))->account('interest.income'), 'legal_point' => 'CUS406 3.2.3(b)'];
    }

    public static function interestAccount(string $amount): string
    {
        self::decimal($amount, true);
        return (new AccountingRoleProfile('cz_504'))->account(bccomp($amount, '0', 12) < 0 ? 'interest.expense' : 'interest.income');
    }

    public static function dayAfter(string $date): string
    {
        self::date($date);
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }

    public static function date(mixed $date): void
    {
        self::need(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1, 'debt_date_invalid');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        self::need($parsed !== false && $parsed->format('Y-m-d') === $date, 'debt_date_invalid');
    }

    public static function decimal(mixed $amount, bool $signed = false): void
    {
        self::need(is_string($amount) && preg_match($signed ? '/^-?\d{1,12}(?:\.\d{1,12})?$/D' : '/^\d{1,12}(?:\.\d{1,12})?$/D', $amount) === 1, 'debt_amount_invalid');
    }

    public static function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 4000;
    }

    private static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->format('%a');
    }

    private static function need(bool $condition, string $code): void
    {
        if (!$condition) throw new \DomainException($code);
    }
}
