<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SqliteWorkingStore;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use PHPUnit\Framework\TestCase;

final class J6SqliteWorkingStoreTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'j6-sqlite-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->base, 0700));
    }

    protected function tearDown(): void
    {
        if (!isset($this->base) || !is_dir($this->base)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->base);
    }

    public function testKeepsExactInt64RowsIndexedAndDeletesEveryOwnedFile(): void
    {
        $store = new J6SqliteWorkingStore($this->base);
        $hash = hash('sha256', 'synthetic');
        $store->appendActivity('1', ['evidence' => 'Synthetic activity'], $hash);
        $store->appendSource(['id' => 'line:1', 'kind' => 'revenue', 'amount_cents' => 9007199254740993,
            'deductible_cents' => 0, 'activity_kind_id' => 1, 'group' => '1:',
            'classification' => ['regime' => 'exempt', 'method' => 'none'],
            'decision' => ['title' => 'gift']], true);
        $store->appendPool('revenue', 'line:1', ['evidence' => 'Synthetic pool'], $hash);
        $store->appendMember('revenue', 'line:1', 'line:1');
        $store->appendLink('line:1', 'line:2', ['amount_cents' => 500, 'evidence' => 'Synthetic link'], $hash);
        $store->appendHistory(['assessment_id' => 7, 'period_id' => 6, 'status' => 'confirmed', 'current' => true,
            'anchor' => 'line:1', 'member_id' => 'line:1', 'net_source_cents' => 9007199254740993,
            'prior_drawdown_cents' => 0, 'drawdown_cents' => 500, 'remaining_cents' => 9007199254740493]);

        $row = $store->connection()->query('SELECT amount_cents,typeof(amount_cents) AS storage_type FROM sources')->fetch();
        self::assertSame(9007199254740993, $row['amount_cents']);
        self::assertSame('integer', $row['storage_type']);
        $plan = $store->connection()->query("EXPLAIN QUERY PLAN SELECT * FROM funding_members WHERE member_id='line:1' AND pool_kind='revenue'")->fetchColumn(3);
        self::assertStringStartsWith('SEARCH funding_members USING INDEX ', (string) $plan);
        self::assertSame(['evidence' => 'Synthetic activity'], $store->activity('1'));

        $store->closeAndDelete();
        self::assertSame([], array_values(array_diff(scandir($this->base), ['.', '..'])));
    }

    public function testMemberCannotBelongToTwoPoolsOfTheSameKind(): void
    {
        $store = new J6SqliteWorkingStore($this->base);
        try {
            $hash = hash('sha256', 'synthetic');
            $store->appendPool('revenue', 'line:1', ['evidence' => 'First'], $hash);
            $store->appendPool('revenue', 'line:2', ['evidence' => 'Second'], $hash);
            $store->appendMember('revenue', 'line:1', 'line:3');
            try {
                $store->appendMember('revenue', 'line:2', 'line:3');
                self::fail('Expected duplicate funding member refusal.');
            } catch (NonprofitTaxAssessmentException $failure) {
                self::assertSame('nonprofit_tax_facts_incomplete', $failure->errorCode);
            }
        } finally {
            $store->closeAndDelete();
        }
    }
}
