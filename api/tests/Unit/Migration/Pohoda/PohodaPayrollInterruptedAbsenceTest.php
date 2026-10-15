<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use PHPUnit\Framework\TestCase;

/**
 * Řádek nepřítomnosti v PAMICA nese začátek oříznutý na měsíc mzdy a konec celého
 * případu. Ošetřování 29. 7. až 6. 8., při kterém zaměstnanec 1.–5. 8. pracoval, má
 * v srpnové mzdě řádek až od 6. 8.; spojené do souvislé nepřítomnosti by krátilo
 * srpnovou mzdu o dny, které odpracoval.
 */
final class PohodaPayrollInterruptedAbsenceTest extends TestCase
{
    public function testRowEndsWithItsMonthWhenTheCaseResumesLater(): void
    {
        $rows = PohodaPayrollPeople::interruptedAbsences([
            self::row('ocr', '2026-07-29', '2026-08-06', 7),
            self::row('ocr', '2026-08-06', '2026-08-06', 8),
        ], 2026);

        self::assertSame(['2026-07-29', '2026-07-31'], [$rows[0]['from'], $rows[0]['to']]);
        self::assertSame(['2026-08-06', '2026-08-06'], [$rows[1]['from'], $rows[1]['to']]);
        self::assertArrayNotHasKey('payslip_month', $rows[0]);
    }

    public function testContinuousCaseKeepsItsEnd(): void
    {
        $rows = PohodaPayrollPeople::interruptedAbsences([
            self::row('sickness', '2026-07-24', '2026-08-06', 7),
            self::row('sickness', '2026-08-01', '2026-08-06', 8),
            self::row('ppm', '2026-07-01', '2027-01-20', 7),
        ], 2026);

        self::assertSame('2026-08-06', $rows[0]['to']);
        self::assertSame('2027-01-20', $rows[2]['to']);
    }

    public function testCaseWithoutRowInNextMonthKeepsItsEnd(): void
    {
        $rows = PohodaPayrollPeople::interruptedAbsences([
            self::row('sickness', '2026-07-24', '2026-08-06', 7),
        ], 2026);

        self::assertSame('2026-08-06', $rows[0]['to']);
    }

    /** @return array<string,mixed> */
    private static function row(string $type, string $from, string $to, int $month): array
    {
        return ['type' => $type, 'from' => $from, 'to' => $to, 'childbirth' => null, 'hours' => 8.0, 'payslip_month' => $month, 'lone_carer' => false];
    }
}
