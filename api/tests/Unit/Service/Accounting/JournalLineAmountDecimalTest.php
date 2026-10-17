<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionAccountMask;
use MyInvoice\Service\Accounting\Dimension\DimensionAccountRouter;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class JournalLineAmountDecimalTest extends TestCase
{
    public function testDocumentDimensionSplitUsesTheSameRoundedSourceCents(): void
    {
        $result = DimensionStamper::assign([
            ['account_id' => 518, 'side' => 'debit', 'amount' => '1.005'],
        ], [], [
            ['dims' => [7 => 71], 'weight' => 1.0, 'account_id' => 518],
            ['dims' => [7 => 72], 'weight' => 1.0, 'account_id' => 518],
        ], [518 => 'expense'], true);
        self::assertSame(101, array_sum(array_map(JournalLineAmount::signedCents(...), $result['lines'])));
        self::assertSame([0.50, 0.51], array_column($result['lines'], 'amount'));
    }

    public function testAccountDimensionSplitUsesTheSameRoundedSourceCents(): void
    {
        $result = DimensionAccountRouter::route([
            ['account_id' => 518, 'side' => 'debit', 'amount' => '1.005', 'dimension_splits' => [7 => [71 => 0.5, 72 => 0.5]]],
        ], 7, DimensionAccountMask::parse('5, 6'), [
            518 => ['code' => '518', 'parent_id' => null, 'account_type' => 'expense'],
            5181 => ['code' => '518.100', 'parent_id' => 518, 'account_type' => 'expense'],
            5182 => ['code' => '518.200', 'parent_id' => 518, 'account_type' => 'expense'],
        ], [518 => [71 => 5181, 72 => 5182]]);
        self::assertSame(101, array_sum(array_map(JournalLineAmount::signedCents(...), $result)));
        self::assertSame([0.50, 0.51], array_column($result, 'amount'));
    }

    public static function amounts(): array
    {
        return [
            ['1000000000000.0049', '1000000000000.00', 100000000000000],
            ['9999999999999.994', '9999999999999.99', 999999999999999],
            ['-1000000000000.0049', '-1000000000000.00', -100000000000000],
            ['1.005', '1.01', 101],
            ['-1.005', '-1.01', -101],
            ['+1.23456789012345e12', '1234567890123.45', 123456789012345],
            ['000.005e0', '0.01', 1],
            ['5e-3', '0.01', 1],
            ['-5E-3', '-0.01', -1],
            ['5e-10000', '0.00', 0],
            ['.15e1', '1.50', 150],
            ['0015e-1', '1.50', 150],
            [0.1 + 0.2, '0.30', 30],
            [1234567890123.45, '1234567890123.45', 123456789012345],
            [9999999999999.99, '9999999999999.99', 999999999999999],
            [42, '42.00', 4200],
        ];
    }

    #[DataProvider('amounts')]
    public function testSignedCentsUseExactDecimalRounding(float|int|string $amount, string $decimal, int $cents): void
    {
        self::assertSame($cents, JournalLineAmount::signedCents(['amount' => $amount]));
        self::assertSame(-$cents, JournalLineAmount::signedCents(['amount' => $amount, 'is_red_storno' => true]));
        self::assertSame(['debit' => $cents, 'credit' => $cents], PostingService::balanceCents([
            ['side' => 'debit', 'amount' => $amount], ['side' => 'credit', 'amount' => $amount],
        ]));
    }

    #[DataProvider('amounts')]
    public function testCanonicalPdoValueDoesNotDependOnPhpPrecision(float|int|string $amount, string $decimal, int $cents): void
    {
        $before = ini_get('precision');
        try {
            foreach ([8, 14, 17] as $precision) {
                ini_set('precision', (string) $precision);
                self::assertSame($decimal, JournalLineAmount::decimal($amount));
                self::assertSame($cents, JournalLineAmount::cents($amount));
            }
        } finally {
            ini_set('precision', $before);
        }
    }
}
