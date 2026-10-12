<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

final class StereoNxSourcePlanTest extends TestCase
{
    public function testCompleteSyntheticTaxEvidenceHasOnePhysicalMovementPerCashbookRow(): void
    {
        $plan = StereoNxSourcePlan::fromTables(SyntheticStereoNxTables::tables(), SyntheticStereoNxTables::identity());
        self::assertSame(1, $plan['counts']['issued']);
        self::assertSame(2, $plan['counts']['purchases']);
        self::assertSame(5, $plan['counts']['bank_transactions']);
        self::assertSame(1, $plan['counts']['cash_transactions']);
        self::assertSame(3, $plan['counts']['payments']);
        self::assertSame(1, $plan['counts']['requires_draft']);
        self::assertSame(6, count($plan['movement_classifications']));
        self::assertSame('income_taxable', $plan['movement_classifications'][0]['bucket']);
        self::assertSame('expense_taxable', $plan['movement_classifications'][1]['bucket']);
        self::assertSame('income_nontax', $plan['movement_classifications'][4]['bucket']);
        self::assertSame('income_nontax', $plan['movement_classifications'][5]['bucket']);
        self::assertSame(100.0, $plan['purchases'][1]['source_total_with_vat']);
        self::assertTrue($plan['purchases'][1]['requires_draft']);
        self::assertSame('24', $plan['purchases'][1]['items'][0]['vat_classification_code']);
        self::assertSame(0.0, $plan['purchases'][1]['total_vat']);
        self::assertSame(100.0, $plan['payments'][2]['amount']);
        self::assertSame($plan['purchases'][1]['source_key'], $plan['payments'][2]['document_key']);
    }

    public function testDocumentKeysFollowCompanyFiscalYearAndTaxEvidenceStaysCalendar(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $fiscal = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true, new FiscalCalendar(8));
        self::assertSame(['["2024","VF","1"]'], array_column($fiscal['issued'], 'source_key'));
        self::assertSame(['["2024","PF","1"]', '["2024","PF","2"]'], array_column($fiscal['purchases'], 'source_key'));

        $calendar = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        self::assertSame(['["2025","VF","1"]'], array_column($calendar['issued'], 'source_key'));

        $taxEvidence = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertSame($taxEvidence, StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(),
            false, false, FiscalCalendar::calendar()));
        self::assertSame('["2025","B","1"]', $taxEvidence['bank_statements'][0]['source_key']);
    }

    public function testSameDocumentNumberInTwoFiscalPeriodsStaysAmbiguousForPayments(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $next = $tables['Svfh'][0];
        $next['KdyVyhotUD'] = '2025-09-10';
        $tables['Svfh'][] = $next;

        // Úhrady a položky odkazují na doklad jen řadou a číslem, takže ani rok
        // hospodářského období v klíči nesmí dva stejně číslované doklady připustit.
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('Nejednoznačná identita dokladu');
        StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true, new FiscalCalendar(8));
    }

    public function testUnknownPartnerCountryRequiresDraftUnlessSourceBlankIsExplicitlyCzech(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['LAdresy'][0]['Stat'] = '';
        $tables['LAdresy'][0]['DIC'] = '';
        $tables['Svfh'][0]['FirmaStat'] = '';
        $tables['Svfh'][0]['FirmaDIC'] = '';
        $conservative = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertContains('partner_country_unresolved', $conservative['issued'][0]['review_codes']);
        self::assertTrue($conservative['issued'][0]['requires_draft']);
        $confirmed = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true);
        self::assertNotContains('partner_country_unresolved', $confirmed['issued'][0]['review_codes']);
        self::assertSame('CZ', $confirmed['clients'][0]['country_code']);
        $tables['LAdresy'][0]['Stat'] = 'EU';
        $eu = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true);
        self::assertContains('partner_country_eu_unspecified', $eu['issued'][0]['review_codes']);
        self::assertNotContains('partner_country_unresolved', $eu['issued'][0]['review_codes']);
        self::assertTrue($eu['issued'][0]['requires_draft']);

        $tables['Svfh'][0]['Firma'] = '';
        $tables['Svfh'][0]['FirmaNazev'] = 'Syntetická historická protistrana';
        $tables['Svfh'][0]['FirmaStat'] = 'EU';
        $snapshot = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true);
        self::assertContains('partner_country_eu_unspecified', $snapshot['issued'][0]['review_codes']);
        self::assertTrue($snapshot['issued'][0]['requires_draft']);
    }

    public function testBillingCountryTakesPriorityOverBlankMainCountryEvenWithCzechDefault(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['LAdresy'][0]['Stat'] = '';
        $tables['LAdresy'][0]['FakStat'] = 'PL';
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true);
        self::assertSame('PL', $plan['clients'][0]['country_code']);
        self::assertFalse($plan['clients'][0]['country_unresolved']);
        $tables['LAdresy'][0]['Stat'] = 'CZ';
        $conflict = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true);
        self::assertSame('PL', $conflict['clients'][0]['country_code']);
        self::assertTrue($conflict['clients'][0]['country_unresolved']);
        self::assertContains('partner_country_unresolved', $conflict['issued'][0]['review_codes']);
    }

    public function testGermanCountryMarkIsRecognizedWithoutDefaultingToCzechia(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['LAdresy'][0]['Stat'] = 'D';
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertSame('DE', $plan['clients'][0]['country_code']);
        self::assertFalse($plan['clients'][0]['country_unresolved']);
        self::assertNotContains('partner_country_unresolved', $plan['issued'][0]['review_codes']);
    }

    public function testAccountingCreditNoteKeepsSourceLinesAndTargetKind(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['TypDokladu'] = 'D';
        $tables['Svfh'][0]['ZaklDPHz'] = -100.0;
        $tables['Svfh'][0]['DPHz'] = -21.0;
        $tables['Svfh'][0]['Celkem'] = -121.0;
        $tables['Svfp'][0]['Mnozstvi'] = -1.0;
        $tables['Svfp'][0]['ZakladDPH'] = -100.0;
        $tables['Svfp'][0]['CelkemDPH'] = -21.0;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertSame('credit_note', $plan['issued'][0]['target_document_kind']);
        self::assertSame('source_lines', $plan['issued'][0]['origin']);
        self::assertFalse($plan['issued'][0]['requires_draft']);
        self::assertNotContains('cancelled_document_review', $plan['issued'][0]['review_codes']);
    }

    public function testUnknownIssuedTypeNeverPretendsToBeCancelled(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['TypDokladu'] = 'M';
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertContains('document_kind_unverified', $plan['issued'][0]['review_codes']);
        self::assertNotContains('cancelled_document_review', $plan['issued'][0]['review_codes']);
    }

    public function testAccountingProformaKeepsSourceLinesOutsideVatReview(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['TypDokladu'] = 'P';
        $tables['Svfh'][0]['ZpracovatDPH'] = false;
        $tables['Svfp'][0]['ZalohaProforma'] = true;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertSame('proforma', $plan['issued'][0]['target_document_kind']);
        self::assertSame('source_lines', $plan['issued'][0]['origin']);
        self::assertFalse($plan['issued'][0]['requires_draft']);
        self::assertNotContains('vat_participation_disabled', $plan['issued'][0]['review_codes']);
    }

    public function testProformaWithUnexpectedVatFlagNeedsReview(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['TypDokladu'] = 'P';
        $tables['Svfp'][0]['ZalohaProforma'] = true;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);

        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertContains('document_tax_mapping_unverified', $plan['issued'][0]['review_codes']);
    }

    public function testUnlinkedVatMovementIsBlockedBeforeTaxableGrossIsClassified(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['CBankap'][3]['DPHz'] = 1.74;
        $tables['Cdenik'][3]['DPH'] = 1.74;
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('samostatný daňový doklad');
        StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
    }

    public function testCashbookProjectionMustMatchPhysicalTaxAndDirection(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Cdenik'][0]['SmerPlatby'] = 'V';
        $this->expectException(StereoNxException::class);
        StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
    }

    public function testReceivableTotalMustEqualStoredDocumentTotal(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Cpz'][1]['Celkem'] = 120.0;
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('rozdílný');
        StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
    }

    public function testMissingIssuedLinesStayDraftAndRetainVatRecap(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfp'] = [];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertContains('issued_lines_missing', $plan['issued'][0]['review_codes']);
        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertSame(121.0, $plan['issued'][0]['source_total_with_vat']);
        self::assertSame('1', $plan['issued'][0]['items'][0]['vat_classification_code']);
    }

    public function testExplicitVatExclusionRemainsDraft(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['ZpracovatDPH'] = false;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertTrue($plan['issued'][0]['requires_draft']);
        self::assertContains('vat_participation_disabled', $plan['issued'][0]['review_codes']);
    }

    public function testIssuedDiscountUsesEffectiveUnitPriceWhileKeepingOriginalTerms(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfp'][0]['JednCena'] = 200.0;
        $tables['Svfp'][0]['ProcSlevy'] = 50.0;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertSame(100.0, $plan['issued'][0]['items'][0]['unit_price']);
        self::assertSame(200.0, $plan['issued'][0]['items'][0]['source_unit_price']);
        self::assertSame(50.0, $plan['issued'][0]['items'][0]['source_discount_percent']);
    }

    public function testGrossPricedIssuedLineUsesStoredGrossUnitPrice(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['CenySDPH'] = true;
        $tables['Svfp'][0]['JednCena'] = 121.0;
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertTrue($plan['issued'][0]['prices_include_vat']);
        self::assertSame(121.0, $plan['issued'][0]['items'][0]['unit_price']);
        self::assertSame(121.0, $plan['issued'][0]['items'][0]['total_with_vat']);
    }

    public function testDifferentTaxAndSupplyDateRequiresReviewWithoutInventingReceiptDate(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['SPFH'][0]['DatumDPH'] = '2025-02-28';
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertTrue($plan['purchases'][0]['requires_draft']);
        self::assertContains('tax_date_supply_mismatch', $plan['purchases'][0]['review_codes']);
        self::assertSame('2025-02-28', $plan['purchases'][0]['tax_date']);
        self::assertSame('2025-03-15', $plan['purchases'][0]['supply_date']);
        self::assertArrayNotHasKey('received_at', $plan['purchases'][0]);
    }

    public function testVatControlRecordMismatchCannotProduceConfirmedDocument(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['ZAZPVDPH'] = [['Doklad' => 'PF-1', 'Agenda' => 'PF',
            'DatumDPH' => '2025-03-15', 'Zaklad' => 99.0, 'DPH' => 21.0]];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertTrue($plan['purchases'][0]['requires_draft']);
        self::assertContains('vat_register_mismatch', $plan['purchases'][0]['review_codes']);
    }
}
