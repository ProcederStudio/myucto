<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\SaldoAgingBuckets;
use MyInvoice\Service\Pdf\SaldoPdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaldoAgingBucketsTest extends TestCase
{
    public function testParseSortsAndDeduplicatesBoundaries(): void
    {
        self::assertSame([30, 60, 120], SaldoAgingBuckets::parse('120, 30,60,30'));
        self::assertSame([90], SaldoAgingBuckets::parse('90'));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidBoundaries(): iterable
    {
        yield 'prázdné' => [''];
        yield 'nula' => ['0,30'];
        yield 'záporné' => ['-30'];
        yield 'text' => ['30,abc'];
        yield 'desetinné' => ['30.5'];
        yield 'nad limit' => ['3651'];
        yield 'moc hranic' => ['10,20,30,40,50,60,70'];
    }

    #[DataProvider('invalidBoundaries')]
    public function testParseRejectsInvalidInput(string $raw): void
    {
        self::assertNull(SaldoAgingBuckets::parse($raw));
    }

    public function testBucketLabelsFollowBoundaries(): void
    {
        self::assertSame(
            ['Do splatnosti', '1–30 dní', '31–60 dní', '61–120 dní', 'Nad 120 dní'],
            array_column(SaldoAgingBuckets::buckets([30, 60, 120]), 'label'),
        );
    }

    public function testBoundaryDayBelongsToLowerBucket(): void
    {
        $b = [30, 60, 120];
        self::assertSame('not_due', SaldoAgingBuckets::bucketOf(0, $b));
        self::assertSame('d30', SaldoAgingBuckets::bucketOf(1, $b));
        self::assertSame('d30', SaldoAgingBuckets::bucketOf(30, $b));
        self::assertSame('d60', SaldoAgingBuckets::bucketOf(31, $b));
        self::assertSame('d120', SaldoAgingBuckets::bucketOf(120, $b));
        self::assertSame('over', SaldoAgingBuckets::bucketOf(121, $b));
    }

    public function testBucketsAddUpToOpenItems(): void
    {
        $aging = SaldoAgingBuckets::build(self::report(), [30, 60, 120]);

        $account = $aging['accounts'][0];
        self::assertSame(['not_due' => 500.0, 'd30' => 1000.0, 'd60' => 0.0, 'd120' => 250.0, 'over' => -100.0], $account['totals']);
        self::assertSame(1650.0, $account['total'], 'Součet pásem = Σ otevřených položek účtu.');
        self::assertSame(['not_due' => 500.0, 'd30' => 1000.0, 'd60' => 0.0, 'd120' => 0.0, 'over' => 0.0], $account['partners'][0]['amounts']);
        self::assertSame(1500.0, $account['partners'][0]['total']);
    }

    public function testPdfShowsAgingWithoutDetailWhenAsked(): void
    {
        $data = self::report() + ['entity' => ['name' => 'Testovací firma s.r.o.', 'ico' => '12345678', 'address' => 'Testovací 1', 'prepared_at' => '01.01.2025'],
            'as_of' => '2024-12-31', 'period' => ['fiscal_year' => 2024]];
        $data['aging'] = SaldoAgingBuckets::build($data, [30, 60, 120]);
        $renderer = new SaldoPdfRenderer();
        $html = static fn (array $d): string => (string) (new \ReflectionMethod($renderer, 'renderTemplate'))->invoke($renderer, 'saldo.twig', $d);

        $summary = $html($data + ['show_detail' => false]);
        self::assertStringContainsString('Členění otevřených položek podle doby po splatnosti', $summary);
        self::assertStringContainsString('61–120 dní', $summary);
        self::assertStringNotContainsString('FV-1', $summary, 'Bez rozpisu nejsou v PDF jednotlivé doklady.');

        $full = $html($data + ['show_detail' => true]);
        self::assertStringContainsString('FV-1', $full);
        self::assertStringContainsString('Rozpis otevřených položek', $full);

        unset($data['aging']);
        $plain = $html($data);
        self::assertStringNotContainsString('Členění', $plain, 'Bez volby zůstává PDF inventarizačním protokolem.');
        self::assertStringStartsWith('%PDF', $renderer->render($data + ['aging' => SaldoAgingBuckets::build($data, [30]), 'show_detail' => false]));
    }

    /** @return array<string,mixed> */
    private static function report(): array
    {
        $item = static fn (string $no, int $days, float $remaining): array => [
            'doc_no' => $no, 'issue_date' => '2024-10-01', 'due_date' => '2024-10-15', 'days_overdue' => $days,
            'booked_czk' => $remaining, 'currency_code' => 'CZK', 'amount_foreign' => 0.0, 'paid_czk' => 0.0,
            'remaining_czk' => $remaining, 'kind' => 'document',
        ];
        return ['accounts' => [[
            'account' => ['code' => '311', 'name' => 'Odběratelé'], 'note' => null, 'gl_balance' => 1650.0,
            'open_items_total' => 1650.0, 'matches' => true, 'difference' => 0.0,
            'partners' => [
                ['partner_name' => 'Odběratel A', 'total_remaining' => 1500.0,
                    'items' => [$item('FV-1', 0, 500.0), $item('FV-2', 30, 1000.0)]],
                ['partner_name' => 'Odběratel B', 'total_remaining' => 150.0,
                    'items' => [$item('FV-3', 61, 250.0), $item('FV-4', 400, -100.0)]],
            ],
        ]]];
    }
}
