<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollJmhzTargets;
use PHPUnit\Framework\TestCase;

/**
 * Zařazení vlastních složek PAMICA do JMHZ podle odeslaných hlášení: kolonka, do které
 * PAMICA částku složky započetla spolu s dalšími položkami téže mzdy.
 */
final class PohodaPayrollJmhzTargetsTest extends TestCase
{
    public function testComponentGoesToTheColumnItsAmountComposes(): void
    {
        $items = [];
        $reports = [];
        foreach (['01', '02', '03'] as $month) {
            $key = '7|2026-' . $month;
            $items[$key] = [
                ['code' => null, 'amount' => 30_000],
                ['code' => 'PAM_O01_BOZP', 'amount' => 1_300],
                ['code' => 'PAM_O01_PRISPEVEK', 'amount' => 2_000],
                ['code' => 'PRIPLATEK_VIKEND', 'amount' => 450],
            ];
            // Prémie i příspěvek v pravidelných odměnách, víkend v úhrnu příplatků i svém detailu.
            $reports[$key] = ['10329' => 30_000, '10330' => 3_300, '10332' => 450, '10335' => 450];
        }

        self::assertSame(
            ['PAM_O01_BOZP' => '10330', 'PAM_O01_PRISPEVEK' => '10330', 'PRIPLATEK_VIKEND' => '10335'],
            PohodaPayrollJmhzTargets::infer($items, $reports, ['PAM_O01_BOZP', 'PAM_O01_PRISPEVEK', 'PRIPLATEK_VIKEND']),
        );
    }

    public function testTooFewOrConflictingVotesLeaveTheComponentUnclassified(): void
    {
        $items = [
            '7|2026-01' => [['code' => 'PAM_O01_X', 'amount' => 500]],
            '8|2026-01' => [['code' => 'PAM_O01_Y', 'amount' => 700]],
            '8|2026-02' => [['code' => 'PAM_O01_Y', 'amount' => 700]],
            '8|2026-03' => [['code' => 'PAM_O01_Y', 'amount' => 700]],
        ];
        $reports = [
            '7|2026-01' => ['10330' => 500],
            '8|2026-01' => ['10330' => 700],
            '8|2026-02' => ['10331' => 700],
            '8|2026-03' => ['10330' => 700],
        ];

        self::assertSame([], PohodaPayrollJmhzTargets::infer($items, $reports, ['PAM_O01_X', 'PAM_O01_Y']));
    }

    public function testAmountMatchingTwoColumnsDoesNotVote(): void
    {
        $items = [];
        $reports = [];
        foreach (['01', '02', '03'] as $month) {
            $items['7|2026-' . $month] = [['code' => 'PAM_O01_X', 'amount' => 500]];
            $reports['7|2026-' . $month] = ['10330' => 500, '10331' => 500];
        }

        self::assertSame([], PohodaPayrollJmhzTargets::infer($items, $reports, ['PAM_O01_X']));
    }
}
