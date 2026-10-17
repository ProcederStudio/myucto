<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\NetTurnoverProjection;
use MyInvoice\Service\Accounting\Reports\StatementMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NetTurnoverProjectionTest extends TestCase
{
    public function testParentChildAndRepeatedMappingClaimOneActualBalanceOnly(): void
    {
        $rows = [
            ['row_code' => 'III.', 'row_type' => 'subtotal', 'parent_row_code' => null, 'section' => 'income'],
            ['row_code' => 'III.3.', 'row_type' => 'line', 'parent_row_code' => 'III.', 'section' => 'income'],
        ];
        $map = [
            ['row_code' => 'III.', 'account_prefix' => '648', 'target' => 'gross', 'balance_condition' => 'any', 'sign' => 1],
            ['row_code' => 'III.3.', 'account_prefix' => '648', 'target' => 'gross', 'balance_condition' => 'any', 'sign' => 1],
        ];
        $balance = ['account_id' => 42, 'code' => '648', 'name' => 'Synthetic revenue', 'account_type' => 'revenue', 'md' => 12.34, 'd' => 100];
        $result = (new NetTurnoverProjection(new StatementMapper()))->project($rows, $map, [$balance], 'income_statement', '2025-01-01', ['III.', 'III.3.']);
        self::assertSame(87.66, $result['amount']);
        self::assertSame(['42:648:credit' => 8766], $result['members']);
        self::assertSame([], $result['findings']);
    }

    public function testOppositeSignedMappingIsNotAcceptedAsRevenueEvidence(): void
    {
        $rows = [['row_code' => 'I.', 'row_type' => 'line', 'parent_row_code' => null, 'section' => 'income']];
        $map = [['row_code' => 'I.', 'account_prefix' => '602', 'target' => 'gross', 'balance_condition' => 'any', 'sign' => -1]];
        $balance = ['account_id' => 42, 'code' => '602', 'name' => 'Synthetic revenue', 'account_type' => 'revenue', 'md' => 0, 'd' => 100];
        $result = (new NetTurnoverProjection(new StatementMapper()))->project($rows, $map, [$balance], 'income_statement', '2025-01-01');
        self::assertSame(-100, $result['amount']);
        self::assertSame('ambiguous_revenue', $result['findings'][0]['code']);
    }

    #[DataProvider('statementCases')]
    public function testSerializedStatementAggregate(array $statement, ?float $expected, ?string $finding): void
    {
        $result = NetTurnoverProjection::fromStatement($statement);
        self::assertSame($expected, $result['amount'] === null ? null : (float) $result['amount']);
        self::assertSame($finding, $result['finding']);
    }

    public static function statementCases(): array
    {
        return [
            'computed zero' => [['checks' => ['net_turnover' => 0]], 0.0, null],
            'computed amount' => [['checks' => ['net_turnover' => '123.45']], 123.45, null],
            'legacy aggregate' => [['rows' => [['row_code' => 'OBRAT', 'amount' => 123.45]]], 123.45, null],
            'consistent aggregate' => [['checks' => ['net_turnover' => 123.45], 'rows' => [['row_code' => 'OBRAT', 'amount' => '123.45']]], 123.45, null],
            'conflicting aggregate' => [['checks' => ['net_turnover' => 123.45], 'rows' => [['row_code' => 'OBRAT', 'amount' => 100]]], null, 'net_turnover_conflict'],
            'duplicated aggregate' => [['rows' => [['row_code' => 'OBRAT', 'amount' => 1], ['row_code' => 'OBRAT', 'amount' => 1]]], null, 'net_turnover_conflict'],
            'missing aggregate' => [['rows' => [['row_code' => 'I.', 'amount' => 100]]], null, 'net_turnover_missing'],
            'null amount' => [['checks' => ['net_turnover' => null]], null, 'net_turnover_invalid'],
            'invalid amount' => [['checks' => ['net_turnover' => 'missing']], null, 'net_turnover_invalid'],
            'boolean amount' => [['checks' => ['net_turnover' => false]], null, 'net_turnover_invalid'],
            'infinite amount' => [['checks' => ['net_turnover' => INF]], null, 'net_turnover_invalid'],
        ];
    }
}
