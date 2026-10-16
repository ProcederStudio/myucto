<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyUctoRegression\Manifest;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionManifestTest extends TestCase
{
    public function testDppoProviderReceivesInputsInsteadOfReadOnlyEnvelope(): void
    {
        $container = new class {
            public function get(string $class): object
            {
                return new class {
                    public function readOnlyInputs(int $supplier, int $year, string $type): array
                    {
                        return ['inputs' => ['bank_account_id' => 5, 'tax_paid_advances' => 1234.56], 'status' => 'draft', 'advances_source' => 'return'];
                    }
                    public function gather(int $supplier, int $year, array $inputs): array
                    {
                        return $inputs;
                    }
                };
            }
        };
        self::assertSame(['bank_account_id' => 5, 'tax_paid_advances' => 1234.56], Manifest::invoke($container, ['kind' => 'dppo_data', 'args' => [1, 2025]]));
    }

    public function testDiscoveryIncludesCurrentFullYearForecastAndCompanyWithoutPeriod(): void
    {
        $pdo = self::discoveryDatabase();
        $manifest = Manifest::discover($pdo, '2026-06-30');
        $cases = array_values($manifest['cases']);
        $kinds = array_column($cases, 'kind');
        foreach (['dppo_forecast', 'closing_income_tax', 'closing_pl_balances', 'closing_bs_balances', 'opening_balances', 'dpfo_preview', 'cash_journal', 'tax_estimate'] as $kind) {
            self::assertContains($kind, $kinds);
        }
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'income_statement' && $c['args'][2] === '2026-12-31'));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'vat_return' && $c['args'] === [2, 2025, 12, 'quarterly']));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dpfo_preview' && $c['args'] === [2, 2025, 'fo']));
        self::assertEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'control_statement' && $c['args'][0] === 1 && $c['args'][3] === 'quarterly'));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'control_statement' && $c['args'][0] === 2 && $c['args'][3] === 'quarterly'));
        self::assertFalse($manifest['coverage']['complete']);
    }

    public function testDiscoveryIncludesInactiveBranchFiltersAndJointCompanyDimensions(): void
    {
        $manifest = Manifest::discover(self::discoveryDatabase(), '2026-06-30');
        $cases = array_values($manifest['cases']);
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'income_statement' && ($c['dimension']['value_id'] ?? null) === 11 && $c['dimension']['descendants'] === true));
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dimension_profit' && $c['args'][4] === [1, 2]));
    }

    public function testXmlProjectionPreservesDuplicatesZerosAndExactDecimalStrings(): void
    {
        $xml = '<Pisemnost><VetaA dic="12345678" zakl_dane1="123456789012345.67" dan1="0"/><VetaA zakl_dane1="10.01" dan1="2.10"/></Pisemnost>';
        $values = Manifest::xmlNumericProjection($xml);
        self::assertSame('123456789012345.67', $values['/Pisemnost/VetaA[1]/@zakl_dane1']);
        self::assertSame('0', $values['/Pisemnost/VetaA[1]/@dan1']);
        self::assertSame('10.01', $values['/Pisemnost/VetaA[2]/@zakl_dane1']);
        self::assertCount(4, $values);
        $this->expectException(\RuntimeException::class);
        Manifest::xmlNumericProjection('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///unread">]><x amount="1"/>');
    }

    public function testHistoricalTaxYearStillHasReturnButNoUnsupportedForecastCall(): void
    {
        $pdo = self::discoveryDatabase();
        $pdo->exec('INSERT INTO income_tax_returns VALUES (1, 2019)');
        $manifest = Manifest::discover($pdo, '2026-06-30');
        $cases = array_values($manifest['cases']);
        self::assertNotEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dppo_preview' && $c['args'] === [1, 2019, 'po']));
        self::assertEmpty(array_filter($cases, static fn (array $c): bool => $c['kind'] === 'dppo_forecast' && $c['args'] === [1, 2019]));
        self::assertSame(1, $manifest['coverage']['not_applicable']['dppo_forecast_year_outside_2020_2100']);
    }

    public function testApproximateCashJournalRatesCannotPassAsNumericalEvidence(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing_exchange_rates');
        Manifest::normalize(['warnings' => [['type' => 'fx_rate_approximate', 'blocking' => false]]], '2026-06-30');
    }

    private static function discoveryDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE supplier (id INTEGER, taxpayer_type TEXT, accounting_mode TEXT, supplier_group_id INTEGER)',
            "INSERT INTO supplier VALUES (1, 'po', 'double_entry', 7), (2, 'fo', 'tax_evidence', 7)",
            'CREATE TABLE accounting_periods (id INTEGER, supplier_id INTEGER, fiscal_year INTEGER, starts_on TEXT, ends_on TEXT)',
            "INSERT INTO accounting_periods VALUES (3, 1, 2026, '2026-01-01', '2026-12-31')",
            'CREATE TABLE dimension_types (id INTEGER, supplier_id INTEGER, supplier_group_id INTEGER)',
            'INSERT INTO dimension_types VALUES (10, NULL, 7)',
            'CREATE TABLE dimension_values (id INTEGER, type_id INTEGER, is_active INTEGER)',
            'INSERT INTO dimension_values VALUES (11, 10, 0)',
            'CREATE TABLE invoices (supplier_id INTEGER, issue_date TEXT, tax_date TEXT)',
            "INSERT INTO invoices VALUES (2, '2025-12-15', '2025-12-15')",
            'CREATE TABLE purchase_invoices (supplier_id INTEGER, issue_date TEXT, tax_date TEXT)',
            'CREATE TABLE cash_documents (supplier_id INTEGER, issue_date TEXT)',
            'CREATE TABLE bank_transactions (statement_id INTEGER, posted_at TEXT)',
            'CREATE TABLE bank_statements (id INTEGER, supplier_id INTEGER)',
            'CREATE TABLE income_tax_returns (supplier_id INTEGER, year INTEGER)',
            'CREATE TABLE tax_profiles (supplier_id INTEGER, year INTEGER)',
        ] as $sql) {
            $pdo->exec($sql);
        }
        return $pdo;
    }
}
