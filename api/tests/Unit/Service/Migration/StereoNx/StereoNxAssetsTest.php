<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\StereoNx\StereoNxAssets;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use PHPUnit\Framework\TestCase;

final class StereoNxAssetsTest extends TestCase
{
    public function testBuildsLongTermAndSmallCardsWithoutRepostingHistoricalDepreciation(): void
    {
        $tables = $this->tables();
        $tables['JMajetek'][] = $this->asset();
        $tables['JDanOdpisy'] = [
            $this->depreciation('M-001', '2020-12-31', 20_000.0, '2021-01-04'),
            $this->depreciation('M-001', '2021-12-31', 30_000.0, ''),
        ];
        $tables['JUcOdpisy'] = $tables['JDanOdpisy'];
        $tables['JDrobMaj'][] = $this->smallAsset();

        $plan = StereoNxAssets::fromTables($tables, $this->identity(), 1);

        self::assertSame(1, $plan['counts']['assets_ready']);
        self::assertSame(1, $plan['counts']['small_assets_ready']);
        self::assertSame(1, $plan['counts']['tax_depreciation_applied']);
        self::assertSame(1, $plan['counts']['accounting_depreciation_applied']);
        $card = $plan['records']['assets'][0]['card'];
        self::assertSame('accelerated', $card['tax_method']);
        self::assertSame('by_tax', $card['acc_method']);
        self::assertSame(20_000.0, $card['opening_tax_amount']);
        self::assertSame(20_000.0, $card['opening_acc_amount']);
        self::assertSame(11, $card['opening_acc_months']);
        self::assertSame('in_use', $card['status']);
        self::assertArrayNotHasKey('depreciation_entries', $plan['records']['assets'][0]);

        $small = $plan['records']['small_assets'][0]['card'];
        self::assertSame(3.0, $small['quantity']);
        self::assertSame(12_000.0, $small['price']);
        self::assertSame('disposed', $small['status']);
        self::assertSame('2024-06-30', $small['disposed_at']);
        self::assertNotSame('', $plan['records']['assets'][0]['source_hash']);
        self::assertNotSame('', $plan['records']['small_assets'][0]['source_hash']);
    }

    public function testDifferentFutureAccountingPlanCreatesReviewDraftWithExactOpeningAmounts(): void
    {
        $tables = $this->tables();
        $tables['JMajetek'][] = $this->asset();
        $tables['JDanOdpisy'] = [
            $this->depreciation('M-001', '2020-12-31', 20_000.0, '2021-01-04'),
            $this->depreciation('M-001', '2021-12-31', 30_000.0, ''),
        ];
        $tables['JUcOdpisy'] = [
            $this->depreciation('M-001', '2020-12-31', 20_000.0, '2021-01-04'),
            $this->depreciation('M-001', '2022-12-31', 30_000.0, ''),
        ];

        $card = StereoNxAssets::fromTables($tables, $this->identity(), 0)['records']['assets'][0]['card'];

        self::assertSame('draft', $card['status']);
        self::assertSame('straight_line', $card['acc_method']);
        self::assertSame(35, $card['acc_useful_life_months']);
        self::assertStringContainsString('budoucí účetní odpisový plán', $card['description']);
    }

    public function testUnsupportedAndOrphanRecordsAreCountedAndReported(): void
    {
        $tables = $this->tables();
        $tables['JMajetek'][] = $this->asset(['Typ' => 'X']);
        $tables['JDrobMaj'][] = $this->smallAsset(['Mnozstvi' => 0.0]);
        $tables['JDanOdpisy'][] = $this->depreciation('NEEXISTUJE', '2020-12-31', 1_000.0, '2021-01-04');
        $tables['JTechZhod'][] = ['InvCislo' => 'M-001', 'Castka' => 5_000.0];

        $plan = StereoNxAssets::fromTables($tables, $this->identity(), 2);

        self::assertSame(0, $plan['counts']['assets_ready']);
        self::assertSame(1, $plan['counts']['assets_skipped']);
        self::assertSame(1, $plan['counts']['small_assets_skipped']);
        self::assertSame(1, $plan['counts']['technical_improvements_skipped']);
        $codes = array_column($plan['warnings'], 'code');
        self::assertContains('asset_required_value_unsupported', $codes);
        self::assertContains('small_asset_required_value_unsupported', $codes);
        self::assertContains('asset_tax_depreciation_orphan', $codes);
        self::assertContains('asset_improvements_not_imported', $codes);
    }

    public function testDepreciationMismatchSkipsCardInsteadOfInventingOpeningBalance(): void
    {
        $tables = $this->tables();
        $tables['JMajetek'][] = $this->asset();
        $tables['JDanOdpisy'][] = $this->depreciation('M-001', '2020-12-31', 19_999.0, '2021-01-04');
        $tables['JUcOdpisy'][] = $this->depreciation('M-001', '2020-12-31', 20_000.0, '2021-01-04');

        $plan = StereoNxAssets::fromTables($tables, $this->identity(), 0);

        self::assertSame(0, $plan['counts']['assets_ready']);
        self::assertSame(1, $plan['counts']['assets_skipped']);
        self::assertContains('asset_depreciation_not_reconciled', array_column($plan['warnings'], 'code'));
    }

    public function testCardWithoutAnyDepreciationPlanIsKeptAsReviewDraft(): void
    {
        $tables = $this->tables();
        $tables['JMajetek'][] = $this->asset(['DanoveOdepsano' => 0.0, 'UcetneOdepsano' => 0.0]);

        $plan = StereoNxAssets::fromTables($tables, $this->identity(), 0);

        self::assertSame(1, $plan['counts']['assets_ready']);
        self::assertSame(0, $plan['counts']['assets_skipped']);
        self::assertSame('draft', $plan['records']['assets'][0]['card']['status']);
        self::assertSame('by_tax', $plan['records']['assets'][0]['card']['acc_method']);
        self::assertContains('asset_depreciation_plan_missing', array_column($plan['warnings'], 'code'));
    }

    public function testAppliedTaxYearsFollowCompanyFiscalYear(): void
    {
        $fiscal = new FiscalCalendar(8);
        foreach ([
            'two_fiscal_years_in_one_calendar_year' => [['2025-01-31', '2025-08-31'], 2, 1],
            'one_fiscal_year_across_two_calendar_years' => [['2024-12-31', '2025-06-30'], 1, 2],
        ] as $case => [$dates, $fiscalYears, $calendarYears]) {
            $tables = $this->tables();
            $tables['JMajetek'][] = $this->asset(['DatumPorizeni' => '2024-09-01', 'DatumZarazeni' => '2024-09-01']);
            $tables['JDanOdpisy'] = [
                $this->depreciation('M-001', $dates[0], 10_000.0, $dates[0]),
                $this->depreciation('M-001', $dates[1], 10_000.0, $dates[1]),
            ];
            $tables['JUcOdpisy'] = $tables['JDanOdpisy'];

            $card = StereoNxAssets::fromTables($tables, $this->identity(), 0, $fiscal)['records']['assets'][0]['card'];
            self::assertSame($fiscalYears, $card['opening_tax_years'], $case);
            self::assertSame(20_000.0, $card['opening_tax_amount'], $case);

            $calendar = StereoNxAssets::fromTables($tables, $this->identity(), 0)['records']['assets'][0]['card'];
            self::assertSame($calendarYears, $calendar['opening_tax_years'], $case);
        }
    }

    public function testDuplicateInventoryNumberIsFatal(): void
    {
        $tables = $this->tables();
        $tables['JMajetek'] = [$this->asset(), $this->asset()];

        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('duplicitní inventární číslo');
        StereoNxAssets::fromTables($tables, $this->identity(), 0);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function tables(): array
    {
        return ['JMajetek' => [], 'JDrobMaj' => [], 'JDanOdpisy' => [], 'JUcOdpisy' => [], 'JTechZhod' => []];
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function asset(array $override = []): array
    {
        return $override + [
            'InvCislo' => 'M-001', 'Nazev' => 'Výrobní zařízení', 'Typ' => 'H',
            'CenaPorizovaci' => 100_000.0, 'DatumPorizeni' => '2020-01-15',
            'DatumZarazeni' => '2020-01-15', 'DatumVyrazeni' => '',
            'UcetMDZarazeni' => '022', 'UcetDalZarazeni' => '042', 'UcetDalOdpisu' => '082',
            'ZpusobDanOdepisovani' => 'Z', 'OdpisovaSkupina' => '2', 'ZvyseniSazProc' => 0,
            'DanoveOdepsano' => 20_000.0, 'UcetneOdepsano' => 20_000.0,
            'Poznamka' => '', 'VyrobniCislo' => '',
        ];
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function smallAsset(array $override = []): array
    {
        return $override + [
            'InvCislo' => 'D-001', 'Nazev' => 'Kancelářská židle', 'Typ' => 'H',
            'JednCena' => 4_000.0, 'Mnozstvi' => 3.0, 'DatumPorizeni' => '2023-05-02',
            'DatumZarazeni' => '2023-05-03', 'DatumVyrazeni' => '2024-06-30',
            'DokladPorizeni' => 'PF-100', 'Firma' => 'Syntetický dodavatel s.r.o.',
            'Pracoviste' => 'Kancelář', 'Pracovnik' => 'Odpovědná osoba',
            'ZpusobVyrazeni' => 'likvidace', 'DruhVyrazeni' => '', 'DokladVyrazeni' => 'V-1',
            'Poznamka' => '', 'VyrobniCislo' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function depreciation(string $number, string $date, float $amount, string $applied): array
    {
        return ['InvCislo' => $number, 'Datum' => $date, 'Castka' => $amount, 'DatumUplatneni' => $applied];
    }

    /** @return array{ico:string,dic:string,name:string,vat_payer:bool} */
    private function identity(): array
    {
        return ['ico' => '12345679', 'dic' => 'CZ12345679', 'name' => 'Syntetická firma s.r.o.', 'vat_payer' => true];
    }
}
