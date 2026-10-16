<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollJmhzWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEnforcementActivation;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla převodu z PAMICA, která rozhodují bez účetní: stav exekuce ze zdroje, zařazení
 * odměn do JMHZ podle četnosti, konec nároku na dítě z karty a peněžní „daně z obědů".
 */
final class PohodaPayrollTakeoverRulesTest extends TestCase
{
    public function testEnforcementFollowsSourceState(): void
    {
        $running = ['valid_to' => null, 'total_minor' => 500000, 'outstanding_minor' => 120000];
        self::assertSame('activate', PayrollTakeoverEnforcementActivation::decide($running, '2026-09-01')['action']);
        // Běžné výživné bez stanovené celkové částky běží dál.
        self::assertSame('activate', PayrollTakeoverEnforcementActivation::decide(['valid_to' => null, 'total_minor' => 0, 'outstanding_minor' => 0], '2026-09-01')['action']);
        self::assertSame('stop', PayrollTakeoverEnforcementActivation::decide(['valid_to' => '2026-04-01'] + $running, '2026-09-01')['action']);
        self::assertSame('stop', PayrollTakeoverEnforcementActivation::decide(['outstanding_minor' => 0] + $running, '2026-09-01')['action']);
        // Platnost do dne začátku vedení mezd a dál: případ běží.
        self::assertSame('activate', PayrollTakeoverEnforcementActivation::decide(['valid_to' => '2026-09-01'] + $running, '2026-09-01')['action']);
    }

    public function testBonusIsRegularWhenPaidAlmostEveryMonth(): void
    {
        self::assertSame('10330', PohodaPayrollImporter::bonusTarget(['Z1' => 8, 'Z2' => 7], 8));
        self::assertSame('10331', PohodaPayrollImporter::bonusTarget(['Z1' => 2, 'Z2' => 1], 8));
        self::assertSame('10331', PohodaPayrollImporter::bonusTarget([], 8));
    }

    public function testChildClaimEndsWithCardWhenReportsRunToTheLastMonth(): void
    {
        $children = [
            ['order' => 2, 'birth_number' => '1505150008', 'from' => '2026-01-01', 'to' => null],
            ['order' => 1, 'birth_number' => '1003030004', 'from' => '2026-01-01', 'to' => null],
            ['order' => 3, 'birth_number' => '1108080003', 'from' => '2026-01-01', 'to' => '2026-03-31'],
        ];
        $card = [
            ['birth_number' => '150515/0008', 'to' => '2026-08-01'],
            ['birth_number' => '110808/0003', 'to' => '2026-08-01'],
        ];

        $ended = PohodaPayrollJmhzWriter::withCardEnds($children, $card);

        self::assertSame('2026-08-31', $ended[0]['to'], 'Konec z karty se převezme na konec měsíce.');
        self::assertNull($ended[1]['to'], 'Dítě bez konce na kartě má nárok dál.');
        self::assertSame('2026-03-31', $ended[2]['to'], 'Konec z hlášení karta nepřepisuje.');
    }

    public function testMealTaxRefundIsCashBonusAndPremiumWorkIsWage(): void
    {
        $mealTax = PohodaPayrollCatalog::component('O01', 'daně z obědů', true);
        self::assertSame('bonus', $mealTax['kind']);
        self::assertNotSame(PohodaPayrollCatalog::TAXABLE_MEAL, $mealTax['code']);
        self::assertSame(PohodaPayrollCatalog::TAXABLE_MEAL, PohodaPayrollCatalog::component('O01', 'nadlimit obědy', true)['code']);
        self::assertSame('hourly_wage', PohodaPayrollCatalog::component('M03', 'Práce přesčas v den pracovního klidu', false)['kind']);
    }

    /**
     * Zařazení odměny podle četnosti z převodu roku bez hlášení (2025) opraví až
     * hlášení PAMICA dalšího roku; zařazení, které účetní upravila, zůstává.
     */
    public function testFrequencyGuessFromEarlierImportYieldsToReports(): void
    {
        $guess = ['is_active' => 1, 'is_current_package' => true, 'row_version' => 1, 'target_attribute_id' => '10331'];
        self::assertTrue(PohodaPayrollImporter::isFrequencyGuess('PAM_O01', $guess));
        self::assertFalse(PohodaPayrollImporter::isFrequencyGuess('PAM_O01', ['row_version' => 2] + $guess));
        self::assertFalse(PohodaPayrollImporter::isFrequencyGuess('ODMENA', $guess));
        self::assertFalse(PohodaPayrollImporter::isFrequencyGuess('PAM_O01', ['target_attribute_id' => '10332'] + $guess));
    }
}
