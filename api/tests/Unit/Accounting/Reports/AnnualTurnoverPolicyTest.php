<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\EntityCategoryPeriodPolicy;
use MyInvoice\Service\Accounting\Reports\NetTurnoverProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnnualTurnoverPolicyTest extends TestCase
{
    #[DataProvider('roundingCases')]
    public function testOneWholeUnitRounding(string $amount, string $from, string $to, string $expected): void
    {
        self::assertSame($expected, EntityCategoryPeriodPolicy::annualTurnoverWhole($amount, $from, $to));
    }

    public static function roundingCases(): array
    {
        return [
            ['100.25', '2025-01-01', '2025-12-31', '100'],
            ['100.50', '2025-01-01', '2025-12-31', '101'],
            ['-100.50', '2025-01-01', '2025-12-31', '-101'],
            ['100.25', '2025-07-01', '2025-12-31', '201'],
            ['100.25', '2024-07-01', '2025-12-31', '67'],
            ['100.25', '2024-02-29', '2024-03-01', '602'],
            ['100.25', '2025-01-31', '2025-01-31', '1203'],
            ['149999999999999.24', '2024-07-01', '2025-12-31', '99999999999999'],
            ['149999999999999.25', '2024-07-01', '2025-12-31', '100000000000000'],
            ['-149999999999999.24', '2024-07-01', '2025-12-31', '-99999999999999'],
            ['0', '2025-01-01', '2025-06-30', '0'],
        ];
    }

    public function testDecimalProjectionPreservesExactCentsAndLegacyShape(): void
    {
        $statement = ['checks' => ['net_turnover' => '149999999999999.24'], 'rows' => [['row_code' => 'OBRAT', 'amount' => '149999999999999.24']]];
        self::assertSame(['amount' => '149999999999999.24', 'finding' => null], NetTurnoverProjection::fromStatementDecimal($statement));
        self::assertSame(['amount' => 14999999999999924 / 100, 'finding' => null], NetTurnoverProjection::fromStatement($statement));
        $statement['rows'][0]['amount'] = '149999999999999.25';
        self::assertSame(['amount' => null, 'finding' => 'net_turnover_conflict'], NetTurnoverProjection::fromStatementDecimal($statement));
    }

    public function testInvalidIntervalIsNotAnnualized(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntityCategoryPeriodPolicy::annualTurnoverWhole('100', '2025-02-29', '2025-12-31');
    }
}
