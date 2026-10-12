<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\Ms3Journal;
use MyInvoice\Service\Migration\MoneyS3\Ms3Table;
use MyInvoice\Tests\Fixtures\MoneyS3\Ms3FixtureWriter;
use PHPUnit\Framework\TestCase;

final class Ms3TableTest extends TestCase
{
    /**
     * Epocha kalendáře Money: 1 = 1. 1. 1900, tedy dny od 31. 12. 1899. Kotvy jsou
     * napevno (sériové číslo data v Excelu minus jedna), ne spočtené stejným kódem —
     * s epochou Delphi (30. 12. 1899) by 1. 1. 2025 vyšel jako 31. 12. 2024 a doklad
     * by spadl do jiného účetního roku.
     */
    public function testDateEpochIsDecemberThirtyFirst1899(): void
    {
        self::assertSame('1900-01-01', Ms3Table::dateFromDays(1));
        self::assertSame('2024-01-01', Ms3Table::dateFromDays(45291));
        self::assertSame('2025-01-01', Ms3Table::dateFromDays(45657));
        self::assertSame('2026-01-01', Ms3Table::dateFromDays(46022));
        self::assertSame('2025-12-31', Ms3Table::dateFromDays(46021));
        self::assertNull(Ms3Table::dateFromDays(0));
    }

    public function testNewYearsDayEntryStaysInNewYear(): void
    {
        $raw = Ms3FixtureWriter::table([['Zdroj', 'C', 2], ['Datum', 'D', 2]], [
            ['Zdroj' => 'ID', 'Datum' => '2025-01-01'],
        ]);
        $rows = iterator_to_array(Ms3Table::fromString($raw, 'UCDENIK')->rows(), false);
        self::assertSame('2025-01-01', $rows[0]['Datum']);
        self::assertSame(2025, Ms3Journal::fiscalYear($rows));
    }

    public function testDecodesExtendedAmountsAndCp1250Texts(): void
    {
        $amounts = [0.0, 0.01, 12100.0, -50.0, 1234567.89, -0.5, 3749375.21, 99999999.99];
        $rows = [];
        foreach ($amounts as $i => $a) {
            $rows[] = ['Cislo' => $i, 'Popis' => 'Účetní služby, žluťoučký kůň', 'Castka' => $a];
        }
        $raw = Ms3FixtureWriter::table([['Cislo', 'L', 4], ['Popis', 'C', 40], ['Castka', 'E', 10]], $rows);
        $decoded = iterator_to_array(Ms3Table::fromString($raw, 'T')->rows(), false);

        self::assertCount(count($amounts), $decoded);
        foreach ($amounts as $i => $a) {
            self::assertSame($i, $decoded[$i]['Cislo']);
            self::assertSame(round($a, 2), round((float) $decoded[$i]['Castka'], 2));
        }
        self::assertSame('Účetní služby, žluťoučký kůň', $decoded[0]['Popis']);
    }

    public function testSkipsDeletedRecordsAndFindsDataBehindIndexBlock(): void
    {
        $raw = Ms3FixtureWriter::table([['Doklad', 'C', 10], ['Del', 'B', 1]], [
            ['Doklad' => 'A1'],
            ['Doklad' => 'SMAZANO', 'Del' => 1],
            ['Doklad' => 'A2'],
        ], 37);
        $table = Ms3Table::fromString($raw, 'T');
        $docs = array_column(iterator_to_array($table->rows(), false), 'Doklad');

        self::assertSame(['A1', 'A2'], $docs);
        self::assertSame(1, $table->skippedDeleted());
    }

    /**
     * Faktury mají místo `Del` příznak `FlagDel`. Smazaná faktura v souboru zůstává a řada
     * její číslo přidělí znovu — bez přeskočení by převod narazil na dva doklady téhož čísla.
     */
    public function testSkipsDocumentsFlaggedAsDeleted(): void
    {
        $raw = Ms3FixtureWriter::table([['Doklad', 'C', 10], ['FlagDel', 'B', 1]], [
            ['Doklad' => 'FP25012', 'FlagDel' => 1],
            ['Doklad' => 'FP25012'],
            ['Doklad' => 'FP25013'],
        ]);
        $table = Ms3Table::fromString($raw, 'PFAKTURY');
        $rows = iterator_to_array($table->rows(), false);

        self::assertSame(['FP25012', 'FP25013'], array_column($rows, 'Doklad'));
        self::assertSame(0, $rows[0]['FlagDel']);
        self::assertSame(1, $table->skippedDeleted());
    }

    public function testEmptyTableHasNoData(): void
    {
        $table = Ms3Table::fromString(Ms3FixtureWriter::table([['Doklad', 'C', 10]], []), 'T');
        self::assertFalse($table->hasData());
        self::assertSame([], iterator_to_array($table->rows(), false));
    }

    public function testRejectsForeignFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Ms3Table::fromString(str_repeat('x', 200), 'T');
    }

    /**
     * Soubor se čte po blocích záznamů: tabulka přes několik bloků (a blok, ve kterém
     * je smazaný záznam i volné místo) musí dát totéž co tabulka z řetězce.
     */
    public function testFileReadInChunksMatchesStringTable(): void
    {
        $fields = [['Doklad', 'C', 250], ['Popis', 'C', 250], ['Text', 'C', 250], ['Castka', 'E', 10], ['Del', 'B', 1]];
        $rows = [];
        $perChunk = intdiv(Ms3Table::READ_CHUNK_BYTES, 4 + 3 * 251 + 10 + 1);
        for ($i = 0; $i < 2 * $perChunk + 17; $i++) {
            $rows[] = ['Doklad' => 'D' . $i, 'Popis' => str_repeat('ž', $i % 50), 'Castka' => $i * 1.5, 'Del' => $i % 97 === 5 ? 1 : 0];
        }
        $raw = Ms3FixtureWriter::table($fields, $rows, 37);
        $path = tempnam(sys_get_temp_dir(), 'ms3');
        file_put_contents($path, $raw);
        try {
            $fromFile = Ms3Table::open($path);
            $fromString = Ms3Table::fromString($raw, 'T');
            $fileRows = iterator_to_array($fromFile->rows(), false);

            self::assertSame(iterator_to_array($fromString->rows(), false), $fileRows);
            self::assertSame($fromString->skippedDeleted(), $fromFile->skippedDeleted());
            self::assertSame(count(array_filter($rows, static fn (array $r): bool => $r['Del'] === 0)), count($fileRows));
            self::assertSame('D' . (2 * $perChunk + 16), $fileRows[count($fileRows) - 1]['Doklad']);
            self::assertSame(count($fileRows), $fromFile->countRows());
        } finally {
            @unlink($path);
        }
    }

    /**
     * Výběr polí: řádek má jen vyžádaná pole (v pořadí tabulky), smazané záznamy se
     * přeskakují i bez příznaku ve výběru a pole, které tabulka nemá, chybí jako dřív.
     */
    public function testRowsWithSelectedFields(): void
    {
        $raw = Ms3FixtureWriter::table([['Doklad', 'C', 10], ['Popis', 'C', 20], ['Castka', 'E', 10], ['FlagDel', 'B', 1]], [
            ['Doklad' => 'A1', 'Popis' => 'první', 'Castka' => 10.0],
            ['Doklad' => 'SMAZANO', 'Popis' => 'x', 'Castka' => 5.0, 'FlagDel' => 1],
            ['Doklad' => 'A2', 'Popis' => 'druhá', 'Castka' => -2.5],
        ]);
        $table = Ms3Table::fromString($raw, 'T');
        $selected = iterator_to_array($table->rows(['Castka', 'Doklad', 'Neni']), false);

        self::assertSame([['Doklad' => 'A1', 'Castka' => 10.0], ['Doklad' => 'A2', 'Castka' => -2.5]], $selected);
        self::assertSame(1, $table->skippedDeleted());
        $full = iterator_to_array($table->rows(), false);
        self::assertSame(array_map(static fn (array $r): array => ['Doklad' => $r['Doklad'], 'Castka' => $r['Castka']], $full), $selected);
        self::assertSame(2, $table->countRows());
    }

    /** Souhrn deníku v jednom průchodu: rok, zda deník leží v jednom roce, data a krajní zápisy. */
    public function testJournalSummaryMatchesRowRules(): void
    {
        $rows = [
            ['Zdroj' => 'XP', 'Datum' => null, 'Popis' => 'Počáteční stav roku 2024'],
            ['Zdroj' => 'ID', 'Datum' => '2023-12-31', 'Popis' => ''],
            ['Zdroj' => 'BK', 'Datum' => '2024-03-02', 'Popis' => ''],
            ['Zdroj' => 'BK', 'Datum' => '2024-01-15', 'Popis' => ''],
            ['Zdroj' => 'FV', 'Datum' => '2025-01-03', 'Popis' => ''],
            ['Zdroj' => 'FV', 'Datum' => '2024-12-30', 'Popis' => ''],
        ];
        $summary = Ms3Journal::summarize(new \ArrayIterator($rows));

        self::assertSame(2024, $summary['fiscal_year']);
        self::assertSame(Ms3Journal::fiscalYear($rows), $summary['fiscal_year']);
        self::assertFalse($summary['single_year'], 'dva z pěti zápisů leží mimo rok 2024');
        self::assertSame(['2023-12-31', '2025-01-03'], $summary['source_dates']);
        self::assertSame(6, $summary['rows']);
        self::assertSame(1, $summary['opening_rows']);
        self::assertSame('2023-12-31', $summary['first_date']);
        self::assertSame('2025-01-03', $summary['last_date']);
        self::assertSame('2024-01-15', $summary['first_entry']);

        // Rok jen s počátečními stavy se vezme z jejich popisu.
        self::assertSame(2026, Ms3Journal::summarize([['Zdroj' => 'XP', 'Datum' => null, 'Popis' => 'PS 2026']])['fiscal_year']);
        self::assertNull(Ms3Journal::summarize([])['fiscal_year']);
    }

    public function testJournalEffectSwapsNegativeAmountAndSkipsDegenerateRows(): void
    {
        self::assertSame(['debit' => '221001', 'credit' => '568000', 'amount' => 50.0],
            Ms3Journal::effect(['UcMD' => '568000', 'UcD' => '221001', 'Castka' => -50.0]));
        self::assertNull(Ms3Journal::effect(['UcMD' => '211000', 'UcD' => '211000', 'Castka' => 10.0]));
        self::assertNull(Ms3Journal::effect(['UcMD' => '518000', 'UcD' => '321000', 'Castka' => 0.0]));
    }
}
