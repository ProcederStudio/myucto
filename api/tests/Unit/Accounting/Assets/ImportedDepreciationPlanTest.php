<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\DepreciationCalculator;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\FiscalCalendar;
use PHPUnit\Framework\TestCase;

final class ImportedDepreciationPlanTest extends TestCase
{
    public function testSourceMonthlyAmountsSurvivePlanAndYearCalculation(): void
    {
        $calculator = new DepreciationCalculator();
        $ctx = $this->context();
        $plan = $calculator->plan($ctx, 'by_accounting', 'straight_line');
        self::assertSame([8990.0, 8990.0, 8990.0, 9030.0], array_column($plan['accounting'][0]['months'], 'amount'));
        self::assertSame(36000.0, $plan['tax'][0]['amount']);
        self::assertSame(0.0, $plan['tax'][0]['residual_end']);
        self::assertSame($plan['accounting'][0], $calculator->accountingYearRow($ctx, 2098, 'straight_line', 'by_accounting'));
        self::assertSame($plan['tax'][0], $calculator->taxYearRow($ctx, 'by_accounting', 2098));
        self::assertNull($calculator->taxYearRow($ctx, 'by_accounting', 2097));
    }

    public function testDisposalKeepsExactSourceMonthsThroughDisposalMonth(): void
    {
        $plan = (new DepreciationCalculator())->plan($this->context('2098-02-12'), 'by_accounting', 'straight_line');
        self::assertSame([8990.0, 8990.0], array_column($plan['accounting'][0]['months'], 'amount'));
        self::assertSame(17980.0, $plan['tax'][0]['amount']);
        self::assertSame(18020.0, $plan['tax'][0]['residual_end']);
    }

    public function testFiscalCalendarGroupsSourceMonthsWithoutAssumingJanuary(): void
    {
        $source = $this->source();
        foreach ($source['accounting'] as $i => &$row) $row['month'] = sprintf('2097-%02d', 8 + $i);
        unset($row);
        $source['tax'][0]['fiscal_year'] = 2097;
        $ctx = new DepreciationContext(96000.0, null, 'none', false, false, false,
            '2096-03-05', null, 20, 0.0, 2, 60000.0, 16, 60000.0,
            [], [], new FiscalCalendar(8), $source);
        $plan = (new DepreciationCalculator())->plan($ctx, 'by_accounting', 'straight_line');
        self::assertSame(2097, $plan['tax'][0]['fiscal_year']);
        self::assertSame(36000.0, $plan['tax'][0]['amount']);
        self::assertSame(['2097-08', '2097-09', '2097-10', '2097-11'], array_column($plan['accounting'][0]['months'], 'month'));
    }

    public function testMalformedOrIncompletePlansFailInsteadOfRecalculating(): void
    {
        foreach (['gap', 'duplicate', 'float', 'negative', 'sum', 'year', 'metadata'] as $case) {
            $source = $this->source();
            if ($case === 'gap') $source['accounting'][1]['month'] = '2098-05';
            if ($case === 'duplicate') $source['accounting'][1]['month'] = '2098-01';
            if ($case === 'float') $source['accounting'][0]['amount_cents'] = 899000.0;
            if ($case === 'negative') $source['accounting'][0]['amount_cents'] = -1;
            if ($case === 'sum') $source['accounting'][0]['amount_cents']++;
            if ($case === 'year') $source['tax'][0]['fiscal_year'] = 2097;
            if ($case === 'metadata') $source['source_program'] = '';
            try {
                (new DepreciationCalculator())->plan($this->context(null, $source), 'by_accounting', 'straight_line');
                self::fail('Malformed source plan accepted: ' . $case);
            } catch (AssetException $e) {
                self::assertSame('imported_plan_invalid', $e->errorCode);
            }
        }
    }

    public function testConfirmedYearsKeepMonthlySourceAndRebookingRecalculatesOnlyRequestedYear(): void
    {
        $ctx = $this->context(null, null, [['kind' => 'accounting', 'fiscal_year' => 2098,
            'amount' => 36000.0, 'full_amount' => 36000.0, 'is_paused' => false, 'is_half' => false]]);
        $calculator = new DepreciationCalculator();
        $row = $calculator->plan($ctx, 'by_accounting', 'straight_line')['accounting'][0];
        self::assertSame('confirmed', $row['source']);
        self::assertSame([8990.0, 8990.0, 8990.0, 9030.0], array_column($row['months'], 'amount'));
        $disposal = $this->context('2098-02-12', null, $ctx->confirmedEntries);
        self::assertSame(17980.0, $calculator->accountingYearRow($disposal, 2098, 'straight_line', 'by_accounting')['amount']);
    }

    public function testDisposalCannotRewriteHistoricalOpeningMonths(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('Vyřazení nesmí zasáhnout');
        (new DepreciationCalculator())->plan($this->context('2097-12-15'), 'by_accounting', 'straight_line');
    }

    private function context(?string $disposal = null, ?array $source = null, array $confirmed = []): DepreciationContext
    {
        return new DepreciationContext(96000.0, null, 'none', false, false, false,
            '2096-08-05', $disposal, 20, 0.0, 2, 60000.0, 16, 60000.0,
            [], $confirmed, FiscalCalendar::calendar(), $source ?? $this->source());
    }

    private function source(): array
    {
        return ['version' => 1, 'source_program' => 'Synthetic source', 'source_tax_method' => 'source-tax',
            'source_accounting_method' => 'source-accounting', 'accounting' => [
                ['month' => '2098-01', 'amount_cents' => 899000], ['month' => '2098-02', 'amount_cents' => 899000],
                ['month' => '2098-03', 'amount_cents' => 899000], ['month' => '2098-04', 'amount_cents' => 903000],
            ], 'tax' => [['fiscal_year' => 2098, 'amount_cents' => 3600000]]];
    }
}
