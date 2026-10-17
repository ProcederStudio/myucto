<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DppoTurnoverPeriodTest extends TestCase
{
    #[DataProvider('cases')]
    public function testRealXmlBoundary(array $statement, ?string $expected, ?string $warning): void
    {
        $result = (new DppoXmlBuilder())->build(
            ['company_name' => 'Syntetický obrat', 'taxpayer_type' => 'po', 'country_iso2' => 'CZ', 'financial_office_code' => '451'],
            2025,
            (new DppoReturnCalculator())->compute(['vh' => 0, 'depreciation' => ['tax' => 0, 'accounting' => 0]], [], TaxConstants::forYear(2025)),
            ['zdobd_od' => '01.01.2025', 'zdobd_do' => '31.12.2025'],
            ['income_statement' => $statement, 'settings' => ['avg_employees' => 0]],
        );
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($result['xml']));
        $veta = $dom->getElementsByTagName('VetaS')->item(0);
        self::assertSame($expected !== null, $veta->hasAttribute('kc_dpp_i1'));
        if ($expected !== null) {
            self::assertSame($expected, $veta->getAttribute('kc_dpp_i1'));
        }
        $warnings = implode("\n", $result['warnings']);
        if ($warning !== null) {
            self::assertStringContainsString('1703', $warnings);
            self::assertStringContainsString($warning, $warnings);
        } else {
            self::assertStringNotContainsString('1703', $warnings);
        }
    }

    public static function cases(): array
    {
        $period = ['starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'];
        $base = ['period' => $period, 'as_of' => '2025-12-31', 'checks' => ['net_turnover' => '100.25']];
        return [
            'twelve months parity' => [$base, '100', null],
            'missing period is not tax-date fallback' => [['checks' => ['net_turnover' => 100]], null, 'období'],
            'partial period object' => [array_replace($base, ['period' => ['starts_on' => '2025-01-01']]), null, 'období'],
            'invalid date' => [array_replace($base, ['period' => ['starts_on' => '2025-02-30', 'ends_on' => '2025-12-31']]), null, 'období'],
            'reversed dates' => [array_replace($base, ['period' => ['starts_on' => '2026-01-01', 'ends_on' => '2025-12-31']]), null, 'období'],
            'interim statement' => [array_replace($base, ['as_of' => '2025-06-30']), null, 'konci'],
            'wrong as of type' => [array_replace($base, ['as_of' => []]), null, 'konci'],
            'legacy full aggregate with known period' => [['period' => $period, 'rows' => [['row_code' => 'OBRAT', 'amount' => '100.50']]], '101', null],
            'year label cannot change accounting period' => [array_replace($base, ['period' => ['starts_on' => '2024-07-01', 'ends_on' => '2025-12-31']]), '67', null],
            'exact max fourteen digits' => [array_replace($base, ['checks' => ['net_turnover' => '99999999999999.49']]), '99999999999999', null],
            'rounding over fourteen digits' => [array_replace($base, ['checks' => ['net_turnover' => '99999999999999.50']]), null, '14'],
            'annualization over fourteen digits' => [array_replace($base, ['period' => ['starts_on' => '2025-07-01', 'ends_on' => '2025-12-31'], 'checks' => ['net_turnover' => '50000000000000']]), null, '14'],
            'no binary float or intermediate cents rounding' => [array_replace($base, ['period' => ['starts_on' => '2024-07-01', 'ends_on' => '2025-12-31'], 'checks' => ['net_turnover' => '149999999999999.24']]), '99999999999999', null],
            'signed half away from zero' => [array_replace($base, ['period' => ['starts_on' => '2025-07-01', 'ends_on' => '2025-12-31'], 'checks' => ['net_turnover' => '-100.25']]), '-201', null],
            'zero is valid' => [array_replace($base, ['checks' => ['net_turnover' => '0']]), '0', null],
        ];
    }
}
