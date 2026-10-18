<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntityCategoryTransitionTest extends TestCase
{
    #[DataProvider('histories')]
    public function testEveryFollowingPeriodUsesOnlyConfirmedBoundaries(array $raws, array $expected): void
    {
        foreach ($expected as $i => $category) {
            $history = array_slice($raws, 0, $i + 1);
            foreach (['micro', 'small', 'medium', 'large'] as $current) {
                self::assertSame($category, EntityCategoryService::categoryFromHistory($current, $history));
            }
        }
    }

    public static function histories(): array
    {
        return [
            'mixed rise' => [['micro', 'small', 'medium'], ['micro', 'micro', 'small']],
            'reverse mixed rise' => [['micro', 'medium', 'small'], ['micro', 'micro', 'small']],
            'mixed fall' => [['large', 'small', 'micro'], ['large', 'large', 'small']],
            'reverse mixed fall' => [['large', 'micro', 'small'], ['large', 'large', 'small']],
            'skip two upward' => [['micro', 'medium', 'large'], ['micro', 'micro', 'medium']],
            'skip two downward' => [['large', 'small', 'micro'], ['large', 'large', 'small']],
            'skip all upward' => [['micro', 'large', 'large'], ['micro', 'micro', 'large']],
            'skip all downward' => [['large', 'micro', 'micro'], ['large', 'large', 'micro']],
            'upward staircase' => [['micro', 'small', 'medium', 'large', 'large'], ['micro', 'micro', 'small', 'medium', 'large']],
            'downward staircase' => [['large', 'medium', 'small', 'micro', 'micro'], ['large', 'large', 'medium', 'small', 'micro']],
            'opposite crossings' => [['small', 'micro', 'large', 'micro', 'large'], ['small', 'small', 'small', 'small', 'small']],
            'opposite medium crossings' => [['medium', 'large', 'small', 'large'], ['medium', 'medium', 'medium', 'medium']],
            'rise then fall' => [['micro', 'small', 'medium', 'small', 'micro', 'micro'], ['micro', 'micro', 'small', 'small', 'small', 'micro']],
            'fall then rise' => [['large', 'medium', 'small', 'medium', 'large', 'large'], ['large', 'large', 'medium', 'medium', 'medium', 'large']],
            'interrupted evidence' => [['micro', 'small', 'micro', 'small'], ['micro', 'micro', 'micro', 'micro']],
            'same category parity' => [['small', 'small', 'small'], ['small', 'small', 'small']],
        ];
    }

    public function testNoHistoryRetainsExistingCurrentRawPolicy(): void
    {
        foreach (['micro', 'small', 'medium', 'large'] as $category) {
            self::assertSame($category, EntityCategoryService::categoryFromHistory($category, []));
        }
    }

    public function testEqualityAndDifferentPairsOfCriteria(): void
    {
        $limits = self::limits();
        self::assertSame('micro', self::classify(11000000, 22000000, 10, $limits));
        self::assertSame('micro', self::classify(11000001, 22000000, 10, $limits));
        $first = self::classify(11000001, 22000001, 0, $limits);
        $second = self::classify(0, 22000001, 11, $limits);
        self::assertSame('small', $first);
        self::assertSame('small', $second);
        self::assertSame('small', EntityCategoryService::categoryFromHistory('micro', ['micro', $first, $second]));
        self::assertSame('small', self::classify(120000000, 240000000, 50, $limits));
        self::assertSame('medium', self::classify(120000001, 240000001, 50, $limits));
        self::assertSame('medium', self::classify(600000000, 1200000000, 250, $limits));
        self::assertSame('large', self::classify(600000001, 1200000001, 250, $limits));
    }

    public function testDated2023And2024LimitsAreNotRebasedToOneCommonYear(): void
    {
        $old = self::limits();
        $old['micro'] = ['assets_net' => 9000000, 'net_turnover' => 18000000, 'employees' => 10];
        $raw2023 = self::classify(10000000, 20000000, 5, $old);
        $raw2024 = self::classify(10000000, 20000000, 5, self::limits());
        self::assertSame('small', $raw2023);
        self::assertSame('micro', $raw2024);
        self::assertSame('small', EntityCategoryService::categoryFromHistory('micro', ['small', $raw2023, $raw2024]));
        self::assertSame('micro', EntityCategoryService::categoryFromHistory('large', ['small', $raw2023, $raw2024, 'micro']));
    }

    public function testAlreadyAnnualizedShortPeriodFactsRespectEitherPermitted2024ThresholdChoice(): void
    {
        $old = self::limits();
        $old['micro'] = ['assets_net' => 9000000, 'net_turnover' => 18000000, 'employees' => 10];
        $annualizedTurnover = 10000000 / 6 * 12;
        $oldRaw = self::classify(10000000, $annualizedTurnover, 5, $old);
        $newRaw = self::classify(10000000, $annualizedTurnover, 5, self::limits());
        self::assertSame('small', $oldRaw);
        self::assertSame('micro', $newRaw);
        self::assertSame('small', EntityCategoryService::categoryFromHistory('large', ['micro', 'small', $oldRaw]));
        self::assertSame('micro', EntityCategoryService::categoryFromHistory('large', ['micro', 'small', $newRaw]));
    }

    public function testUnknownHistoricalCategoryFailsExplicitly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntityCategoryService::categoryFromHistory('micro', ['micro', 'unproven']);
    }

    private static function classify(float $assets, float $turnover, int $employees, array $limits): string
    {
        return EntityCategoryService::classifyCriteria(
            ['assets_net' => $assets, 'net_turnover' => $turnover, 'employees' => $employees],
            $limits,
        );
    }

    private static function limits(): array
    {
        return [
            'micro' => ['assets_net' => 11000000, 'net_turnover' => 22000000, 'employees' => 10],
            'small' => ['assets_net' => 120000000, 'net_turnover' => 240000000, 'employees' => 50],
            'medium' => ['assets_net' => 600000000, 'net_turnover' => 1200000000, 'employees' => 250],
        ];
    }
}
