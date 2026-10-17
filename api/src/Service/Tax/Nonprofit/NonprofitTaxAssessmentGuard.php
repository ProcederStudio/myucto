<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSourceRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;

final class NonprofitTaxAssessmentGuard
{
    public function __construct(
        private readonly NonprofitTaxSourceRepository $sources,
        private readonly NonprofitTaxAssessmentRepository $assessments,
        private readonly AccountingFrameworkService $frameworks,
    ) {}

    public function closingCheck(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        $period = $this->sources->period($supplierId, $periodId, $currentRead);
        $frameworks = array_values(array_unique($this->frameworks->forInterval($supplierId, $period['starts_on'], $period['ends_on'], $currentRead)));
        if ($frameworks === [AccountingFrameworkService::CZ_500]) return ['applicable' => false, 'complete' => true, 'assessment_id' => null, 'findings' => []];
        if ($frameworks !== [AccountingFrameworkService::CZ_504]) return ['applicable' => true, 'complete' => false, 'assessment_id' => null, 'findings' => [['code' => 'mixed_framework']]];
        $snapshot = $this->sources->snapshot($supplierId, $periodId, $currentRead);
        $latest = $this->assessments->latest($supplierId, $periodId, $currentRead);
        $findings = $snapshot['findings'];
        if ($latest === null) $findings[] = ['code' => 'assessment_missing'];
        elseif ($latest['status'] !== 'confirmed') $findings[] = ['code' => 'assessment_withdrawn'];
        elseif ($latest['ruleset'] !== NonprofitTaxRules::VERSION || !hash_equals($latest['input_hash'], $snapshot['input_hash'])) $findings[] = ['code' => 'assessment_stale'];
        return ['applicable' => true, 'complete' => $findings === [], 'assessment_id' => $latest['id'] ?? null,
            'input_hash' => $snapshot['input_hash'], 'findings' => $findings, 'dppo_supported' => false];
    }
}
