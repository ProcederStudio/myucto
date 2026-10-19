<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Accounting\Reports\Statement504Builder;
use MyInvoice\Service\Accounting\Reports\Statement504Catalog;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoComputation;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonprofitDppoStoredProofRebaseTest extends TestCase
{
    public static function profiles(): array
    {
        return [['v3'], ['raw_v3'], ['v2'], ['legacy']];
    }

    #[DataProvider('profiles')]
    public function testHistoricalRebasePreservesVersionHashModeAndFinancialProjection(string $profile): void
    {
        [$computed, $record] = $this->fixture($profile);
        $data = $record['data'];
        $inputs = $record['inputs'];
        $entityProof = $record['entity_proof'];
        $entityProof['supplier']['company_name'] = 'Synthetic Clone 504 s.r.o.';

        $rebased = NonprofitDppoStoredProof::rebaseHistorical($record, $data, $inputs, $entityProof);

        self::assertSame($profile === 'raw_v3' ? 'v3' : $profile, NonprofitDppoStoredProof::comparisonProfile($rebased));
        self::assertSame('Synthetic Clone 504 s.r.o.', $rebased['entity_proof']['supplier']['company_name']);
        self::assertSame($record['inputs'], $rebased['inputs']);
        self::assertSame($record['constants'], $rebased['constants']);
        self::assertSame(isset($record['proof_hash_mode']), isset($rebased['proof_hash_mode']));
        self::assertSame(isset($record['form_manifest']), isset($rebased['form_manifest']));
        self::assertSame(isset($record['line_coverage']), isset($rebased['line_coverage']));
        self::assertSame(isset($record['data']['j6_receipt']), isset($rebased['data']['j6_receipt']));
        self::assertSame(isset($record['data']['loss_appendix']), isset($rebased['data']['loss_appendix']));
        self::assertSame(NonprofitDppoStoredProof::filingProofHash($rebased), $rebased['filing_proof_hash']);
        NonprofitDppoStoredProof::assertCalculation($computed, $rebased);
    }

    public function testHistoricalRebaseRejectsChangedStatementCellEvenWithRecomputedOuterProof(): void
    {
        [, $record] = $this->fixture('v3');
        $data = $record['data'];
        $data['statements']['balance_sheet']['assets'][2]['end']++;

        $this->expectException(TaxReturnException::class);
        NonprofitDppoStoredProof::rebaseHistorical($record, $data, $record['inputs'], $record['entity_proof']);
    }

    public function testHistoricalRebaseRejectsInputChangeWithoutMatchingDerivedData(): void
    {
        [, $record] = $this->fixture('v3');
        $inputs = $record['inputs'];
        $inputs['nonprofit']['reduction_evidence'] = 'Synthetic orphaned election';

        $this->expectException(TaxReturnException::class);
        NonprofitDppoStoredProof::rebaseHistorical($record, $record['data'], $inputs, $record['entity_proof']);
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function fixture(string $profile): array
    {
        $inputs = ['nonprofit' => ['reduction_kc' => 300000, 'reduction_evidence' => 'Synthetic historical election']];
        $data = (new \ReflectionMethod(NonprofitDppoCalculatorTest::class, 'data'))
            ->invoke(new NonprofitDppoCalculatorTest('testReductionAboveLimitRejectedInsteadOfClipped'), $inputs);
        $data['period']['fiscal_year'] = 2026;
        $statementMeta = [
            'accounting_framework' => 'cz_504',
            'version_code' => Statement504Catalog::VERSION,
            'scope' => 'full',
            'period' => [
                'id' => $data['period']['id'],
                'starts_on' => $data['period']['starts_on'],
                'ends_on' => $data['period']['ends_on'],
            ],
        ];
        $statementBuilder = new Statement504Builder();
        $data['statements'] = [
            'balance_sheet' => $statementMeta + $statementBuilder->balanceSheet([], [], 'full'),
            'income_statement' => $statementMeta + $statementBuilder->incomeStatement([], 'full'),
        ];
        $data['source_manifest']['statements_hash'] = NonprofitTaxRules::hash($data['statements']);
        $constants = TaxConstants::forYear(2026);
        $computed = (new DppoReturnCalculator())->compute($data, $inputs, $constants);
        $record = NonprofitDppoStoredProof::capture(
            $data,
            $inputs,
            $constants,
            ['supplier' => ['company_name' => 'Synthetic Original 504 s.r.o.']],
        );
        if ($profile === 'raw_v3') {
            unset($record['proof_hash_mode'], $record['filing_proof_hash']);
            $record['filing_proof_hash'] = NonprofitTaxRules::hash($record);
        } elseif (in_array($profile, ['v2', 'legacy'], true)) {
            $record['version'] = 2;
            unset(
                $record['proof_hash_mode'],
                $record['filing_proof_hash'],
                $record['form_manifest'],
                $record['line_coverage'],
                $record['data']['j6_receipt'],
                $record['data']['loss_appendix'],
                $record['data']['nonprofit']['j6_receipt'],
                $record['data']['source_manifest']['j6_receipt_hash'],
                $record['data']['source_manifest']['loss_appendix_hash'],
            );
            $record['data']['nonprofit'] = NonprofitDppoComputation::seal($record['data']['nonprofit']);
            $record['data']['source_manifest']['nonprofit_proof_hash'] = $record['data']['nonprofit']['proof_hash'];
            if ($profile === 'legacy') {
                unset($record['version']);
            }
            $record['filing_proof_hash'] = NonprofitTaxRules::hash($record);
        }
        NonprofitDppoStoredProof::assertCalculation($computed, $record);
        return [$computed, $record];
    }
}
