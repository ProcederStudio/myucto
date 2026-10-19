<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SqliteFundingResolver;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SqliteWorkingStore;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use PHPUnit\Framework\TestCase;

final class J6SqliteFundingResolverTest extends TestCase
{
    private string $base;
    private J6SqliteWorkingStore $store;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'j6-funding-test-' . bin2hex(random_bytes(12));
        mkdir($this->base, 0700);
        $this->store = new J6SqliteWorkingStore($this->base);
    }

    protected function tearDown(): void
    {
        if (isset($this->store)) $this->store->closeAndDelete();
        if (isset($this->base) && is_dir($this->base)) rmdir($this->base);
    }

    public function testNetPoolAndIndexedLinkStayExactWithoutMemberArray(): void
    {
        $this->source('grant', 'revenue', 10000, 0, 'public_operating_grant', 'out_of_scope');
        $this->source('refund', 'revenue', -4000, 0, 'public_operating_grant', 'out_of_scope');
        $this->source('cost', 'expense', 6000, 6000, 'cost', 'subject');
        $hash = hash('sha256', 'synthetic');
        $this->store->appendPool('revenue', 'grant', ['evidence' => 'Synthetic refund pool'], $hash);
        $this->store->appendMember('revenue', 'grant', 'grant');
        $this->store->appendMember('revenue', 'grant', 'refund');
        $this->store->appendLink('grant', 'cost', ['amount_cents' => 6000, 'evidence' => 'Synthetic drawdown'], $hash);

        (new J6SqliteFundingResolver($this->store))->prepare(true);

        $pool = $this->store->connection()->query("SELECT amount_cents,prior_drawdown_cents FROM resolved_pools WHERE pool_kind='revenue' AND anchor='grant'")->fetch();
        self::assertSame(['amount_cents' => 6000, 'prior_drawdown_cents' => 0], $pool);
        self::assertSame(6000, $this->store->connection()->query('SELECT amount_cents FROM funding_link_eval')->fetchColumn());
    }

    public function testNetPoolCapacityRejectsOneCentOverdraw(): void
    {
        $this->source('grant', 'revenue', 10000, 0, 'public_operating_grant', 'out_of_scope');
        $this->source('refund', 'revenue', -4000, 0, 'public_operating_grant', 'out_of_scope');
        $this->source('cost', 'expense', 7000, 7000, 'cost', 'subject');
        $hash = hash('sha256', 'synthetic');
        $this->store->appendPool('revenue', 'grant', ['evidence' => 'Synthetic refund pool'], $hash);
        $this->store->appendMember('revenue', 'grant', 'grant');
        $this->store->appendMember('revenue', 'grant', 'refund');
        $this->store->appendLink('grant', 'cost', ['amount_cents' => 6001, 'evidence' => 'Synthetic overdraw'], $hash);

        $this->expectException(NonprofitTaxAssessmentException::class);
        (new J6SqliteFundingResolver($this->store))->prepare(true);
    }

    private function source(string $id, string $kind, int $amount, int $deductible, string $title, string $regime): void
    {
        $this->store->appendSource(['id' => $id, 'kind' => $kind, 'amount_cents' => $amount,
            'deductible_cents' => $deductible, 'activity_kind_id' => 1, 'group' => '1:',
            'classification' => ['regime' => $regime, 'method' => $kind === 'revenue' ? 'none' : 'cost'],
            'decision' => ['title' => $title]], true);
    }
}
