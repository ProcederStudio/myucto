<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeriodRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PohodaPayrollPeriodRangeTest extends TestCase
{
    public function testWithoutBoundsEverythingIsInRange(): void
    {
        $range = PohodaPayrollPeriodRange::fromParams([]);

        self::assertFalse($range->limited());
        self::assertTrue($range->contains('2019-01'));
        self::assertSame('2026-09', $range->capLast('2026-09'));
        self::assertNull($range->capLast(null));
    }

    public function testBoundsAreInclusiveAndCapTheLastMonth(): void
    {
        $range = PohodaPayrollPeriodRange::fromParams(['period_from' => '2026-01', 'period_to' => '2026-08']);

        self::assertTrue($range->limited());
        self::assertFalse($range->contains('2025-12'));
        self::assertTrue($range->contains('2026-01'));
        self::assertTrue($range->contains('2026-08'));
        self::assertFalse($range->contains('2026-09'));
        self::assertSame('2026-08', $range->capLast('2026-09'));
        self::assertSame('2026-05', $range->capLast('2026-05'));
        self::assertSame('1/2026 - 8/2026', $range->label());
        self::assertSame(['period_from' => '2026-01', 'period_to' => '2026-08'], $range->toParams());
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalid(): iterable
    {
        yield 'month 13' => [['period_to' => '2026-13']];
        yield 'day included' => [['period_from' => '2026-01-01']];
        yield 'not a string' => [['period_to' => 202601]];
        yield 'from after to' => [['period_from' => '2026-09', 'period_to' => '2026-08']];
    }

    /** @param array<string,mixed> $params */
    #[DataProvider('invalid')]
    public function testInvalidRangeIsRejected(array $params): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PohodaPayrollPeriodRange::fromParams($params);
    }
}
