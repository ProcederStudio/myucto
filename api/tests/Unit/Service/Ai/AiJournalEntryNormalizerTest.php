<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Service\Ai\AiJournalEntryNormalizer;
use PHPUnit\Framework\TestCase;

final class AiJournalEntryNormalizerTest extends TestCase
{
    private const CHART = ['353' => 'Pohledávky za upsaný základní kapitál', '411' => 'Základní kapitál', '221' => 'Bankovní účty'];

    public function testRoundsAmountsFillsNamesAndComputesBalance(): void
    {
        $out = AiJournalEntryNormalizer::normalize([[
            'entry_date' => '2026-03-15',
            'description' => 'Upsání základního kapitálu',
            'document_no' => '',
            'lines' => [
                ['account_code' => '353', 'side' => 'debit', 'amount' => 200000.004, 'note' => ''],
                ['account_code' => '411', 'side' => 'credit', 'amount' => '200000', 'note' => 'notářský zápis'],
            ],
        ]], self::CHART, '2026-10-11');

        self::assertCount(1, $out);
        self::assertSame('2026-03-15', $out[0]['entry_date']);
        self::assertNull($out[0]['document_no']);
        self::assertSame(200000.0, $out[0]['lines'][0]['amount']);
        self::assertSame('Pohledávky za upsaný základní kapitál', $out[0]['lines'][0]['account_name']);
        self::assertNull($out[0]['lines'][0]['note']);
        self::assertSame('notářský zápis', $out[0]['lines'][1]['note']);
        self::assertTrue($out[0]['balanced']);
        self::assertSame([], $out[0]['unknown_accounts']);
    }

    public function testDropsInvalidLinesAndReportsUnknownAccountsAndImbalance(): void
    {
        $out = AiJournalEntryNormalizer::normalize([[
            'entry_date' => '2026-02-30',
            'description' => '',
            'document_no' => 'PD-1',
            'lines' => [
                ['account_code' => '221', 'side' => 'debit', 'amount' => 150000, 'note' => ''],
                ['account_code' => '353', 'side' => 'credit', 'amount' => 0, 'note' => ''],
                ['account_code' => '353', 'side' => 'credit', 'amount' => -5, 'note' => ''],
                ['account_code' => '999', 'side' => 'sideways', 'amount' => 10, 'note' => ''],
                ['account_code' => '211 ', 'side' => 'credit', 'amount' => 100000.10, 'note' => ''],
            ],
        ], ['description' => 'bez řádků', 'lines' => []]], self::CHART, '2026-10-11');

        self::assertCount(1, $out, 'zápis bez platných řádků vypadne');
        self::assertSame('2026-10-11', $out[0]['entry_date'], 'neplatné datum → dnešek');
        self::assertSame('PD-1', $out[0]['document_no']);
        self::assertCount(2, $out[0]['lines']);
        self::assertSame('211', $out[0]['lines'][1]['account_code']);
        self::assertNull($out[0]['lines'][1]['account_name']);
        self::assertSame(['211'], $out[0]['unknown_accounts']);
        self::assertFalse($out[0]['balanced']);
    }

    /** Vyrovnanost se počítá v haléřích — float součet 0.1 + 0.2 nesmí shodit shodu. */
    public function testBalanceIsComparedInCents(): void
    {
        $out = AiJournalEntryNormalizer::normalize([[
            'entry_date' => '2026-01-01', 'description' => 'x', 'document_no' => '',
            'lines' => [
                ['account_code' => '221', 'side' => 'debit', 'amount' => 0.1, 'note' => ''],
                ['account_code' => '221', 'side' => 'debit', 'amount' => 0.2, 'note' => ''],
                ['account_code' => '411', 'side' => 'credit', 'amount' => 0.3, 'note' => ''],
            ],
        ]], self::CHART, '2026-10-11');

        self::assertTrue($out[0]['balanced']);
    }

    public function testNonArrayInputYieldsNothing(): void
    {
        self::assertSame([], AiJournalEntryNormalizer::normalize('nic', self::CHART, '2026-10-11'));
    }
}
