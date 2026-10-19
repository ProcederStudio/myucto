<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Service\Nonprofit\Membership\MembershipJournalPlan;
use MyInvoice\Service\Nonprofit\Membership\MembershipRevisionTimeline;
use PHPUnit\Framework\TestCase;

final class MembershipDomainPolicyTest extends TestCase
{
    public function testFutureRevisionEndsEarlierStateWithoutChangingItsReceipt(): void
    {
        $history = [
            ['id' => 11, 'revision_no' => 1, 'effective_from' => '2026-01-01', 'status' => 'active', 'record_hash' => str_repeat('a', 64)],
            ['id' => 12, 'revision_no' => 2, 'effective_from' => '2027-01-01', 'status' => 'ended', 'record_hash' => str_repeat('b', 64)],
        ];

        self::assertSame(11, MembershipRevisionTimeline::at($history, '2026-12-31')['id']);
        self::assertSame(12, MembershipRevisionTimeline::at($history, '2027-01-01')['id']);
        self::assertSame('2027-01-01', MembershipRevisionTimeline::intervals($history)[0]['effective_to']);
        self::assertSame(str_repeat('a', 64), $history[0]['record_hash']);
    }

    public function testIssueAndReversePlansAreExactReciprocals(): void
    {
        $issue = MembershipJournalPlan::issue('315.100', '684.100', '40000.00');
        $reverse = MembershipJournalPlan::reverse($issue);

        self::assertSame([
            ['account_code' => '315.100', 'side' => 'debit', 'amount' => '40000.00'],
            ['account_code' => '684.100', 'side' => 'credit', 'amount' => '40000.00'],
        ], $issue);
        self::assertSame([
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => '40000.00'],
            ['account_code' => '684.100', 'side' => 'debit', 'amount' => '40000.00'],
        ], $reverse);
    }
}
