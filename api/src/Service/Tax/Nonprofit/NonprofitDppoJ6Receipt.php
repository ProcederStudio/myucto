<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoJ6Receipt
{
    public static function capture(array $period, array $currentSnapshot, array $assessment): array
    {
        if (($assessment['status'] ?? null) !== 'confirmed'
            || ($assessment['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || ($assessment['period_id'] ?? null) !== ($period['id'] ?? null)
            || !is_array($assessment['snapshot'] ?? null)
            || !is_array($assessment['assessment'] ?? null)
            || !is_array($assessment['result'] ?? null)
            || ($assessment['result']['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || !is_array($assessment['result']['rows'] ?? null)
            || !is_int($assessment['id'] ?? null)
            || !is_string($assessment['input_hash'] ?? null)
            || !is_string($assessment['request_hash'] ?? null)
            || !hash_equals($assessment['input_hash'], (string) ($currentSnapshot['input_hash'] ?? ''))
            || !hash_equals($assessment['input_hash'], (string) ($assessment['snapshot']['input_hash'] ?? ''))) {
            self::invalid();
        }
        $receipt = [
            'version' => 1,
            'ruleset' => NonprofitTaxRules::VERSION,
            'assessment_id' => $assessment['id'],
            'period' => [
                'id' => $period['id'],
                'fiscal_year' => $period['fiscal_year'],
                'starts_on' => $period['starts_on'],
                'ends_on' => $period['ends_on'],
            ],
            'input_hash' => $assessment['input_hash'],
            'request_hash' => $assessment['request_hash'],
            'snapshot_hash' => NonprofitTaxRules::hash($assessment['snapshot']),
            'assessment_hash' => NonprofitTaxRules::hash($assessment['assessment']),
            'result_hash' => NonprofitTaxRules::hash($assessment['result']),
        ];
        return $receipt + ['receipt_hash' => NonprofitTaxRules::hash($receipt)];
    }

    public static function verify(array $receipt, array $period, array $result): void
    {
        $hash = $receipt['receipt_hash'] ?? null;
        unset($receipt['receipt_hash']);
        if (($receipt['version'] ?? null) !== 1
            || ($receipt['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || !is_string($hash)
            || !hash_equals($hash, NonprofitTaxRules::hash($receipt))
            || ($receipt['period'] ?? null) !== [
                'id' => $period['id'] ?? null,
                'fiscal_year' => $period['fiscal_year'] ?? null,
                'starts_on' => $period['starts_on'] ?? null,
                'ends_on' => $period['ends_on'] ?? null,
            ]
            || !is_int($receipt['assessment_id'] ?? null)
            || !is_string($receipt['request_hash'] ?? null)
            || !is_string($receipt['input_hash'] ?? null)
            || !is_string($receipt['snapshot_hash'] ?? null)
            || !is_string($receipt['assessment_hash'] ?? null)
            || !is_string($receipt['result_hash'] ?? null)
            || ($result['ruleset'] ?? null) !== NonprofitTaxRules::VERSION
            || !is_array($result['rows'] ?? null)
            || !hash_equals($receipt['result_hash'], NonprofitTaxRules::hash($result))) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new TaxReturnException('nonprofit_dppo_j6_receipt_invalid', 'DPPO 504 vyžaduje úplný aktuální a neměnný receipt potvrzeného posouzení J6.', 409);
    }
}
