<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;

final class NonprofitTaxAssessmentFixture
{
    public static function confirm(ContainerInterface $container, int $supplier, int $period, int $actor): array
    {
        $service = $container->get(NonprofitTaxAssessmentService::class);
        $snapshot = $service->read($supplier, $period)['snapshot'];
        Assert::assertSame([], $snapshot['findings']);
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Synthetic statutes and taxpayer assessment'],
            'activities' => [], 'decisions' => [], 'funding' => []];
        $manual = !$snapshot['assessment_support']['automatic'];
        if ($manual) {
            $assessment['profile']['kind'] = 'documented';
            $assessment['method'] = ['mode' => 'documented_manual', 'valid_from' => $snapshot['period']['starts_on'], 'valid_to' => $snapshot['period']['ends_on'],
                'legal_basis' => 'Synthetic legislation applicable to this fiscal period', 'evidence' => 'Synthetic complete classification working paper'];
        }
        foreach ($snapshot['sources'] as $source) {
            $assessment['activities'][$source['activity_kind_id']] = ['evidence' => 'Synthetic documented activity'];
            $assessment['decisions'][$source['id']] = ['title' => $source['kind'] === 'revenue' ? 'rent' : 'cost',
                'evidence' => 'Synthetic source decision', 'legal_basis' => 'zdp_24_1',
                'deductible_cents' => $source['mandatory_non_deductible'] ? 0 : $source['amount_cents']];
            if ($manual) {
                $assessment['decisions'][$source['id']]['final_regime'] = 'subject';
                if ($source['kind'] === 'revenue') $assessment['decisions'][$source['id']]['final_method'] = 'return';
            }
        }
        return $service->confirm($supplier, $period, $assessment, $snapshot['input_hash'],
            'Synthetic reviewed accounting evidence', 'synthetic-closing-' . $period, $actor);
    }
}
