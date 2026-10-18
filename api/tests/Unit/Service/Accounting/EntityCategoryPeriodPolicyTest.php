<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Reports\EntityCategoryPeriodPolicy;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntityCategoryPeriodPolicyTest extends TestCase
{
    #[DataProvider('intervals')]
    public function testCommencedMonthsAndDatedRegime(string $from, string $to, int $months, int $year): void
    {
        self::assertSame($months, EntityCategoryPeriodPolicy::commencedMonths($from, $to));
        self::assertSame($year, EntityCategoryPeriodPolicy::thresholdYear($from, $to));
    }

    public static function intervals(): array
    {
        return [
            ['2025-01-15', '2025-06-01', 6, 2025],
            ['2024-02-29', '2024-02-29', 1, 2024],
            ['2025-01-31', '2025-02-01', 2, 2025],
            ['2024-07-01', '2025-12-31', 18, 2025],
            ['2023-07-01', '2024-06-30', 12, 2023],
            ['2024-01-01', '2024-06-30', 6, 2024],
            ['2024-12-31', '2025-01-01', 2, 2025],
            ['2025-01-01', '2025-12-31', 12, 2025],
            ['2024-07-01', '2025-06-30', 12, 2025],
            ['2023-01-01', '2023-12-31', 12, 2023],
            ['2019-07-01', '2020-06-30', 12, 2020],
        ];
    }

    #[DataProvider('invalidIntervals')]
    public function testInvalidPeriodFailsExplicitly(string $from, string $to): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntityCategoryPeriodPolicy::commencedMonths($from, $to);
    }

    public static function invalidIntervals(): array
    {
        return [
            ['2025-02-29', '2025-03-01'],
            ['2025-01-01', '2025-13-01'],
            ['2025-02-01', '2025-01-31'],
            ['', '2025-01-01'],
            ['0000-01-01', '2025-01-01'],
            ['2025-1-1', '2025-01-31'],
            ["2025-01-01\0", '2025-01-31'],
        ];
    }

    #[DataProvider('amounts')]
    public function testTurnoverComparisonDoesNotRoundTheAnnualizedAmount(string $amount, string $limit, int $months, bool $exceeds): void
    {
        self::assertSame($exceeds, EntityCategoryPeriodPolicy::turnoverExceeds($amount, $limit, $months));
    }

    public static function amounts(): array
    {
        return [
            ['11000000.00', '22000000.00', 6, false],
            ['11000000.01', '22000000.00', 6, true],
            ['10999999.99', '22000000.00', 6, false],
            ['33000000.00', '22000000.00', 18, false],
            ['33000000.01', '22000000.00', 18, true],
            ['12833333.33', '22000000.00', 7, false],
            ['12833333.34', '22000000.00', 7, true],
            ['15000000000000000.00', '10000000000000000.00', 18, false],
            ['15000000000000000.01', '10000000000000000.00', 18, true],
            ['-12000000.00', '22000000.00', 6, false],
        ];
    }

    public function testClassificationDoesNotAnnualizeAssetsOrEmployees(): void
    {
        $limits = ['micro' => ['assets_net' => 11000000, 'net_turnover' => 22000000, 'employees' => 10]];
        self::assertSame('micro', EntityCategoryService::classifyCriteria(
            ['assets_net' => 10000000, 'net_turnover' => 0, 'employees' => 11], $limits, 1,
        ));
        self::assertSame('micro', EntityCategoryService::classifyCriteria(
            ['assets_net' => 12000000, 'net_turnover' => 0, 'employees' => 5], $limits, 1,
        ));
        self::assertSame('large', EntityCategoryService::classifyCriteria(
            ['assets_net' => 12000000, 'net_turnover' => 0, 'employees' => 11], $limits, 18,
        ));
    }

    public function testTwelveMonthDefaultPreservesOriginalFractionalComparison(): void
    {
        $facts = ['assets_net' => 0, 'net_turnover' => 22000000.001, 'employees' => 11];
        $limits = ['micro' => ['assets_net' => 11000000, 'net_turnover' => 22000000, 'employees' => 10]];
        self::assertSame('large', EntityCategoryService::classifyCriteria($facts, $limits));
        self::assertSame('large', EntityCategoryService::classifyCriteria($facts, $limits, 12));
    }

    public function testInvalidMonthCountIsRejectedByBothCallables(): void
    {
        foreach ([0, -1] as $months) {
            try {
                EntityCategoryPeriodPolicy::turnoverExceeds(1, 1, $months);
                self::fail('Invalid month count accepted by policy.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
            try {
                EntityCategoryService::classifyCriteria([], [], $months);
                self::fail('Invalid month count accepted by classifier.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
