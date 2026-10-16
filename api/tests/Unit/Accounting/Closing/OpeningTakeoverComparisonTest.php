<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\OpeningTakeoverComparison;
use PHPUnit\Framework\TestCase;

final class OpeningTakeoverComparisonTest extends TestCase
{
    public function testDimensionalDifferenceShowsOriginalBalances(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [
                ['account_code' => '378', 'side' => 'debit', 'amount' => 100.0, 'dimensions' => [1 => 11]],
                ['account_code' => '378', 'side' => 'debit', 'amount' => 100.0, 'dimensions' => [1 => 12]],
            ],
            [
                ['account_code' => '378', 'bal' => 90.0, 'dimensions' => [1 => 11]],
                ['account_code' => '378', 'bal' => 110.0, 'dimensions' => [1 => 12]],
            ],
        );
        $rows = [];
        foreach ($result['diff'] as $row) {
            $rows[$row['dimensions'][1]] = [$row['expected'], $row['existing'], $row['difference']];
        }
        self::assertEquals([11 => [100.0, 90.0, 10.0], 12 => [100.0, 110.0, -10.0]], $rows);
    }

    public function testUnresolvedProfilesDoNotDisappearWhenTheirMarginalTotalsMatch(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [
                ['account_code' => '378', 'side' => 'debit', 'amount' => 100.0,
                    'dimension_splits' => [1 => [11 => 0.5, 12 => 0.5], 2 => [21 => 0.5, 22 => 0.5]]],
                ['account_code' => '378', 'side' => 'debit', 'amount' => 100.0,
                    'dimension_splits' => [1 => [11 => 0.75, 12 => 0.25], 2 => [21 => 0.25, 22 => 0.75]]],
            ],
            [['account_code' => '378', 'bal' => 200.0,
                'dimension_splits' => [1 => [11 => 0.625, 12 => 0.375], 2 => [21 => 0.375, 22 => 0.625]]]],
        );
        self::assertNotEmpty($result['diff']);
    }

    public function testEquivalentSplitAndMaterializedLinesMatch(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [['account_code' => '378', 'side' => 'debit', 'amount' => 100.0, 'dimension_splits' => [1 => [11 => 0.5, 12 => 0.5]]]],
            [
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 11]],
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 12]],
            ],
        );
        self::assertSame([], $result['diff']);
    }

    public function testMaterializedMultiTypeSplitMatchesItsMarginals(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [[
                'account_code' => '378', 'side' => 'debit', 'amount' => 100.0,
                'dimension_splits' => [1 => [11 => 0.5, 12 => 0.5], 2 => [21 => 0.75, 22 => 0.25]],
            ]],
            [
                ['account_code' => '378', 'bal' => 25.0, 'dimensions' => [1 => 11, 2 => 21]],
                ['account_code' => '378', 'bal' => 25.0, 'dimensions' => [1 => 11, 2 => 22]],
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 12, 2 => 21]],
            ],
        );
        self::assertSame([], $result['diff']);
    }

    public function testMixedKnownCellAndMaterializedMultiTypeSplitMatch(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [
                ['account_code' => '378', 'side' => 'debit', 'amount' => 40.0, 'dimensions' => [1 => 11, 2 => 21]],
                [
                    'account_code' => '378', 'side' => 'debit', 'amount' => 100.0,
                    'dimension_splits' => [1 => [11 => 0.5, 12 => 0.5], 2 => [21 => 0.75, 22 => 0.25]],
                ],
            ],
            [
                ['account_code' => '378', 'bal' => 65.0, 'dimensions' => [1 => 11, 2 => 21]],
                ['account_code' => '378', 'bal' => 25.0, 'dimensions' => [1 => 11, 2 => 22]],
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 12, 2 => 21]],
            ],
        );
        self::assertSame([], $result['diff']);
    }

    public function testOneMultiTypeSplitMatchesTwoProportionalSplits(): void
    {
        $split = [1 => [11 => 0.5, 12 => 0.5], 2 => [21 => 0.75, 22 => 0.25]];
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [['account_code' => '378', 'side' => 'debit', 'amount' => 100.0, 'dimension_splits' => $split]],
            [
                ['account_code' => '378', 'bal' => 50.0, 'dimension_splits' => $split],
                ['account_code' => '378', 'bal' => 50.0, 'dimension_splits' => $split],
            ],
        );
        self::assertSame([], $result['diff']);
    }

    public function testCreditMultiTypeSplitMatchesMaterializedCredits(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [[
                'account_code' => '378', 'side' => 'credit', 'amount' => 100.0,
                'dimension_splits' => [1 => [11 => 0.5, 12 => 0.5], 2 => [21 => 0.75, 22 => 0.25]],
            ]],
            [
                ['account_code' => '378', 'bal' => -25.0, 'dimensions' => [1 => 11, 2 => 21]],
                ['account_code' => '378', 'bal' => -25.0, 'dimensions' => [1 => 11, 2 => 22]],
                ['account_code' => '378', 'bal' => -50.0, 'dimensions' => [1 => 12, 2 => 21]],
            ],
        );
        self::assertSame([], $result['diff']);
    }

    public function testAmbiguousMaterializationOfSeveralUnknownProfilesDoesNotMatch(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [
                [
                    'account_code' => '378', 'side' => 'debit', 'amount' => 100.0,
                    'dimension_splits' => [1 => [11 => 0.5, 12 => 0.5], 2 => [21 => 0.5, 22 => 0.5]],
                ],
                [
                    'account_code' => '378', 'side' => 'debit', 'amount' => 100.0,
                    'dimension_splits' => [1 => [11 => 0.75, 12 => 0.25], 2 => [21 => 0.25, 22 => 0.75]],
                ],
            ],
            [
                ['account_code' => '378', 'bal' => 75.0, 'dimensions' => [1 => 11, 2 => 21]],
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 11, 2 => 22]],
                ['account_code' => '378', 'bal' => 75.0, 'dimensions' => [1 => 12, 2 => 22]],
            ],
        );
        self::assertNotEmpty($result['diff']);
    }

    public function testMatchingSmallSplitDoesNotHideLargerSwappedKnownCells(): void
    {
        $split = [1 => [11 => 0.5, 12 => 0.5], 2 => [21 => 0.5, 22 => 0.5]];
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [
                ['account_code' => '378', 'side' => 'debit', 'amount' => 100.0, 'dimensions' => [1 => 11, 2 => 21]],
                ['account_code' => '378', 'side' => 'debit', 'amount' => 100.0, 'dimensions' => [1 => 12, 2 => 22]],
                ['account_code' => '378', 'side' => 'debit', 'amount' => 10.0, 'dimension_splits' => $split],
            ],
            [
                ['account_code' => '378', 'bal' => 100.0, 'dimensions' => [1 => 11, 2 => 22]],
                ['account_code' => '378', 'bal' => 100.0, 'dimensions' => [1 => 12, 2 => 21]],
                ['account_code' => '378', 'bal' => 10.0, 'dimension_splits' => $split],
            ],
        );
        self::assertNotEmpty($result['diff']);
    }

    public function testDifferentCombinedDirectCoordinatesDoNotMatch(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [
                ['account_code' => '378', 'side' => 'debit', 'amount' => 50.0, 'dimensions' => [1 => 11, 2 => 21]],
                ['account_code' => '378', 'side' => 'debit', 'amount' => 50.0, 'dimensions' => [1 => 12, 2 => 22]],
            ],
            [
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 11, 2 => 22]],
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 12, 2 => 21]],
            ],
        );
        self::assertNotEmpty($result['diff']);
    }

    public function testLinkedCenterColumnsDoNotOverrideFormalDimensionComparison(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [['account_code' => '378', 'side' => 'debit', 'amount' => 100.0, 'dimension_splits' => [1 => [11 => 0.5, 12 => 0.5]]]],
            [
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 11], 'cost_center' => 'A'],
                ['account_code' => '378', 'bal' => 50.0, 'dimensions' => [1 => 12], 'cost_center' => 'B'],
            ],
            [1 => 'cost_center'],
        );
        self::assertSame([], $result['diff']);
    }

    public function testDifferentLegacyCentersDoNotMatch(): void
    {
        $result = OpeningTakeoverComparison::compareWithDimensions(
            [['account_code' => '378', 'side' => 'debit', 'amount' => 100.0, 'cost_center' => 'A']],
            [['account_code' => '378', 'bal' => 100.0, 'cost_center' => 'B']],
        );
        self::assertNotEmpty($result['diff']);
    }

    public function testMatchingBalancesGiveNoDiff(): void
    {
        $result = OpeningTakeoverComparison::compare(
            [
                ['account_code' => '311', 'side' => 'debit', 'amount' => 7100.00],
                ['account_code' => '701', 'side' => 'credit', 'amount' => 7100.00],
                ['account_code' => '701', 'side' => 'debit', 'amount' => 7100.00],
                ['account_code' => '431', 'side' => 'credit', 'amount' => 7100.00],
            ],
            ['311' => 7100.00, '431' => -7100.00, '701' => 0.00],
        );

        self::assertSame([], $result['diff']);
        self::assertSame(2, $result['accounts']);
    }

    public function testRetainedResultComparedAtSyntheticLevelAndClearingAccountsSkipped(): void
    {
        $result = OpeningTakeoverComparison::compare(
            [
                ['account_code' => '221', 'side' => 'debit', 'amount' => 500.00],
                ['account_code' => '701', 'side' => 'credit', 'amount' => 500.00],
                ['account_code' => '701', 'side' => 'debit', 'amount' => 500.00],
                ['account_code' => '431', 'side' => 'credit', 'amount' => 500.00],
            ],
            ['221' => 500.00, '431100' => -300.00, '431200' => -200.00, '701' => 999.00, '702' => -1.00],
        );

        self::assertSame([], $result['diff'], 'Analytiky 431 se sčítají na syntetiku, 70x se nesrovnávají.');
    }

    public function testDiffListsAccountsSortedWithSignedAmounts(): void
    {
        $result = OpeningTakeoverComparison::compare(
            [
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
                ['account_code' => '311', 'side' => 'debit', 'amount' => 100.00],
            ],
            ['311' => 90.00, '221' => 10.00, '321' => -100.00],
        );

        self::assertSame([
            ['account_code' => '221', 'expected' => 0, 'existing' => 10, 'difference' => -10],
            ['account_code' => '311', 'expected' => 100, 'existing' => 90, 'difference' => 10],
        ], $result['diff']);
    }

    public function testHalfPennyNoiseIsNotADifference(): void
    {
        $result = OpeningTakeoverComparison::compare(
            [['account_code' => '311', 'side' => 'debit', 'amount' => 0.1 + 0.2]],
            ['311' => 0.30],
        );

        self::assertSame([], $result['diff']);
    }
}
