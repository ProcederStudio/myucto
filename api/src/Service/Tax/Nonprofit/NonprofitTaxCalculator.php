<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

final class NonprofitTaxCalculator
{
    private readonly J6AuthoritativeCalculator $authoritative;

    public function __construct(?J6AuthoritativeCalculator $authoritative = null)
    {
        $this->authoritative = $authoritative ?? new J6AuthoritativeCalculator();
    }

    public function calculate(array $snapshot, array $assessment): array
    {
        return $this->authoritative->calculateLegacyArray($snapshot, $assessment);
    }

    public function fundingIncomePools(array $result, array $snapshot, array $assessment): array
    {
        return $this->authoritative->fundingIncomePools($result, $snapshot, $assessment);
    }

    public function fundingCostPools(array $result, array $assessment): array
    {
        return $this->authoritative->fundingCostPools($result, $assessment);
    }
}
