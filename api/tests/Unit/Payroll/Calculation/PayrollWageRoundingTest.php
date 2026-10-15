<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use MyInvoice\Service\Payroll\Absence\LeaveCompensationCalculator;
use MyInvoice\Service\Payroll\Absence\PaidObstacleCompensationCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyWageProration;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use PHPUnit\Framework\TestCase;

/**
 * Volba firmy „matematicky jako předchozí program" mění jen zaokrouhlení výsledku
 * krácené mzdy a náhrad; bez volby zůstává zákonné nahoru (§ 142 odst. 2 ZP).
 */
final class PayrollWageRoundingTest extends TestCase
{
    /** 22 000 × 10 / 21 = 10 476,19 Kč: nahoru 10 477, matematicky 10 476. */
    public function testProrationFollowsChosenRounding(): void
    {
        $byTitle = ['sickness_compensation' => 11 * 8 * 60];

        self::assertSame(1_047_700, MonthlyWageProration::calculate(2_200_000, 21 * 8 * 60, $byTitle)->amountMinor);
        self::assertSame(
            1_047_600,
            MonthlyWageProration::calculate(2_200_000, 21 * 8 * 60, $byTitle, PayrollWageRounding::HalfUp)->amountMinor,
        );
    }

    /** Hodina dovolené za průměr 200,10 Kč: nahoru 201, matematicky 200; 200,50 Kč matematicky 201. */
    public function testLeaveCompensationFollowsChosenRounding(): void
    {
        self::assertSame(20_100, LeaveCompensationCalculator::calculateMinutes(20_010, 60));
        self::assertSame(20_000, LeaveCompensationCalculator::calculateMinutes(20_010, 60, 100, PayrollWageRounding::HalfUp));
        self::assertSame(20_100, LeaveCompensationCalculator::calculateMinutes(20_050, 60, 100, PayrollWageRounding::HalfUp));

        $result = LeaveCompensationCalculator::calculate(
            20_010,
            [['shift_id' => null, 'local_date' => '2026-08-03', 'planned_minutes' => 60, 'eligible_minutes' => 60]],
            PayrollWageRounding::HalfUp,
        );
        self::assertSame(20_000, $result->totalMinor());
        self::assertSame('half-up-to-czk-on-period-total', $result->trace('2026-08-01', 1, 1)['rounding']);
    }

    public function testObstacleCompensationFollowsChosenRounding(): void
    {
        self::assertSame(20_100, PaidObstacleCompensationCalculator::amount(20_010, 60, 10_000));
        self::assertSame(20_000, PaidObstacleCompensationCalculator::amount(20_010, 60, 10_000, PayrollWageRounding::HalfUp));
    }
}
