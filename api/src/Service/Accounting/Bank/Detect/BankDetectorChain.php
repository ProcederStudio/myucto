<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank\Detect;

use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use MyInvoice\Service\Accounting\Bank\TransferPairService;

final class BankDetectorChain
{
    public function __construct(
        private readonly TaxRemittanceDetector $taxRemittance,
        private readonly TransferPairService $transfers,
        private readonly BankPostingRuleRepository $rules,
        private readonly BankRuleMatcher $matcher,
        private readonly BankPostingSuggestionRepository $suggestions,
        private readonly AutoPostingPolicyService $policy,
        private readonly \MyInvoice\Service\Accounting\CreditCard\CreditCardChargeDetector $creditCards,
    ) {}

    /** @return DetectionResult|array{action:string,reason?:string,entry_id?:int,suggestion_id?:int}|null */
    public function run(int $supplierId, array $tx, ?int $userId, bool $suggestOnly): DetectionResult|array|null
    {
        $direction = (float) ($tx['amount'] ?? 0) > 0 ? 'incoming' : 'outgoing';
        $currency = strtoupper((string) ($tx['currency'] ?? $tx['statement_currency'] ?? 'CZK'));
        foreach ($this->rules->findActive($supplierId, $direction) as $rule) {
            if (!BankRuleMatcher::overridesDetectors($rule)) {
                continue;
            }
            if (strtoupper((string) ($rule['applies_currency'] ?? 'CZK')) !== $currency) {
                continue;
            }
            if ($this->suggestions->hasRejected($supplierId, (int) $tx['id'], (int) $rule['id'])) {
                continue;
            }
            if ($this->matcher->matching($rule, $tx)) {
                return null;
            }
        }

        if ($this->policy->levelFor($supplierId, OperationType::DETECTOR_TAX_REMITTANCE) !== 'off'
            && !$this->suggestions->hasRejectedDetector($supplierId, (int) $tx['id'], $this->taxRemittance->key())) {
            $detected = $this->taxRemittance->detect($supplierId, $tx);
            if ($detected !== null) {
                return $detected;
            }
        }
        $transfer = $this->transfers->handle($supplierId, $tx, $userId, $suggestOnly);
        if ($transfer !== null) {
            return $transfer;
        }
        // Kreditní karta (úrok, poplatek, splátka bez protiúčtu …) až po vlastních převodech:
        // splátku z vlastního účtu páruje TransferPairService i s druhou nohou.
        if (!$this->suggestions->hasRejectedDetector($supplierId, (int) $tx['id'], \MyInvoice\Service\Accounting\CreditCard\CreditCardChargeDetector::KEY)) {
            return $this->creditCards->detect($supplierId, $tx);
        }
        return null;
    }
}
