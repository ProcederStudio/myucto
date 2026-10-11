<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Sample;

use DateTimeImmutable;
use MyInvoice\Service\Sample\SampleDataGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Okno posledních N měsíců, ze kterého generátor ukázkových dat skládá bankovní
 * výpisy, MUSÍ vracet N různých měsíců pro každý den v roce.
 *
 * `$today->modify('-N months')` nad 29.–31. dnem přeteče: 29. 7. − 5 měsíců je
 * 29. 2., což se u nepřestupného roku normalizuje na 1. 3. Dva různé kroky smyčky
 * tak vyrobily TÝŽ měsíc, s ním shodný název souboru `demo-vypis-RRRR-MM.gpc`
 * a shodný `file_hash` - generování pak spadlo na duplicitním klíči výpisu.
 * Rozbité to bylo spolehlivě vždy 29.–31. dne v měsíci.
 *
 * Test drží pravidlo „ukotvit na první den měsíce PŘED odečtem", ne konkrétní
 * implementaci: replikuje výraz z SampleDataGenerator::seedBankStatements().
 */
final class SampleDataMonthWindowTest extends TestCase
{
    private const STATEMENT_COUNT = 6;

    #[DataProvider('riskyDays')]
    public function testBusinessDocumentsCoverTwelveActualMonths(string $today): void
    {
        $reflection = new \ReflectionClass(SampleDataGenerator::class);
        $generator = $reflection->newInstanceWithoutConstructor();
        $dateMethod = $reflection->getMethod('documentDate');
        $anchor = new DateTimeImmutable($today);
        $months = [];
        for ($index = 0; $index < 120; $index++) {
            $date = $dateMethod->invoke($generator, $anchor, $index, 120);
            self::assertLessThanOrEqual($today, $date);
            $months[] = substr($date, 0, 7);
        }
        $expected = [];
        for ($offset = 11; $offset >= 0; $offset--) {
            $expected[] = $anchor->modify('first day of this month')->modify("-$offset months")->format('Y-m');
        }
        self::assertSame($expected, array_values(array_unique($months)));
    }

    /** @return list<string> měsíce okna ve tvaru RRRR-MM */
    private static function window(DateTimeImmutable $today): array
    {
        $out = [];
        for ($s = 0; $s < self::STATEMENT_COUNT; $s++) {
            $out[] = $today->modify('first day of this month')
                ->modify('-' . (self::STATEMENT_COUNT - 1 - $s) . ' months')
                ->format('Y-m');
        }
        return $out;
    }

    /** @return iterable<string, array{string}> */
    public static function riskyDays(): iterable
    {
        // Dny, kde odečet měsíců přetéká přes kratší měsíc.
        yield '29. 7. → únor nepřestupného roku' => ['2026-07-29'];
        yield '30. 7.'                           => ['2026-07-30'];
        yield '31. 7.'                           => ['2026-07-31'];
        yield '31. 3. → únor'                    => ['2026-03-31'];
        yield '31. 5. → únor'                    => ['2026-05-31'];
        yield '29. 2. přestupného roku'          => ['2028-02-29'];
        yield '31. 12.'                          => ['2026-12-31'];
        yield 'běžný den uprostřed měsíce'       => ['2026-07-15'];
        yield 'první den měsíce'                 => ['2026-07-01'];
    }

    #[DataProvider('riskyDays')]
    public function testWindowHasDistinctMonths(string $today): void
    {
        $months = self::window(new DateTimeImmutable($today));

        self::assertCount(self::STATEMENT_COUNT, $months);
        self::assertSame(
            $months,
            array_values(array_unique($months)),
            "Okno k {$today} obsahuje duplicitní měsíc: " . implode(', ', $months),
        );
    }

    /** Okno musí končit aktuálním měsícem a jít souvisle zpět. */
    public function testWindowIsContiguousAndEndsThisMonth(): void
    {
        $months = self::window(new DateTimeImmutable('2026-07-29'));

        self::assertSame('2026-07', end($months), 'Poslední výpis je za aktuální měsíc.');
        self::assertSame(
            ['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'],
            $months,
        );
    }

    /**
     * Kontrola, že chybný pořádek operací (odečet PŘED ukotvením) je opravdu vadný —
     * jinak by test procházel i po návratu původní implementace.
     */
    public function testUnanchoredSubtractionWouldCollide(): void
    {
        $today = new DateTimeImmutable('2026-07-29');
        $broken = [];
        for ($s = 0; $s < self::STATEMENT_COUNT; $s++) {
            $broken[] = $today->modify('-' . (self::STATEMENT_COUNT - 1 - $s) . ' months')
                ->modify('first day of this month')
                ->format('Y-m');
        }

        self::assertNotSame(
            $broken,
            array_values(array_unique($broken)),
            'Původní pořadí operací musí k 29. 7. kolidovat — jinak test nic nehlídá.',
        );
    }
}
