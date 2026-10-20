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
        private readonly \MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CurrentProviderFactory $current,
        private readonly \MyInvoice\Infrastructure\Database\Connection $db,
    ) {}

    public function closingCheck(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        $period = $this->sources->period($supplierId, $periodId, $currentRead);
        $frameworks = array_values(array_unique($this->frameworks->forInterval($supplierId, $period['starts_on'], $period['ends_on'], $currentRead)));
        if ($frameworks === [AccountingFrameworkService::CZ_500]) return ['applicable' => false, 'complete' => true, 'assessment_id' => null, 'findings' => []];
        if ($frameworks !== [AccountingFrameworkService::CZ_504]) return ['applicable' => true, 'complete' => false, 'assessment_id' => null, 'findings' => [['code' => 'mixed_framework']]];
        $latest = $this->assessments->latest($supplierId, $periodId, $currentRead);
        if (NonprofitDppoAssessmentSource::pagedHistory($this->db, $supplierId, $periodId, $currentRead)) {
            // Stránkovaná revize se ověří proti otisku podkladů; legacy snímek ji doložit neumí.
            // Legacy revize za stránkovaným obdobím aktuální být nemůže.
            $fingerprint = $this->current->fingerprint($supplierId, $periodId, $currentRead);
            $findings = $fingerprint->findingCount === 0 ? [] : [['code' => 'sources_incomplete']];
            if ($latest === null) $findings[] = ['code' => 'assessment_missing'];
            elseif ($latest['status'] !== 'confirmed') $findings[] = ['code' => 'assessment_withdrawn'];
            elseif (!NonprofitDppoAssessmentSource::pagedOnly($latest) || $latest['ruleset'] !== NonprofitTaxRules::VERSION
                || !hash_equals($latest['input_hash'], $fingerprint->inputHash)) $findings[] = ['code' => 'assessment_stale'];
            return ['applicable' => true, 'complete' => $findings === [], 'assessment_id' => $latest['id'] ?? null,
                'input_hash' => $fingerprint->inputHash, 'findings' => $findings, 'dppo_supported' => false];
        }
        $snapshot = $this->sources->snapshot($supplierId, $periodId, $currentRead);
        $findings = $snapshot['findings'];
        if ($latest === null) $findings[] = ['code' => 'assessment_missing'];
        elseif ($latest['status'] !== 'confirmed') $findings[] = ['code' => 'assessment_withdrawn'];
        elseif ($latest['ruleset'] !== NonprofitTaxRules::VERSION || !hash_equals($latest['input_hash'], $snapshot['input_hash'])) $findings[] = ['code' => 'assessment_stale'];
        return ['applicable' => true, 'complete' => $findings === [], 'assessment_id' => $latest['id'] ?? null,
            'input_hash' => $snapshot['input_hash'], 'findings' => $findings, 'dppo_supported' => false];
    }
}
