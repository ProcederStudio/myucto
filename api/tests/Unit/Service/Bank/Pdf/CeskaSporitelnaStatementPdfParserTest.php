<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\CeskaSporitelnaStatementPdfParser;
use PHPUnit\Framework\TestCase;

/**
 * PDF „Výpis z účtu" České spořitelny. Data jsou VYMYŠLENÁ, pozice buněk odpovídají
 * layoutu výpisu: záhlaví tabulky o třech řádcích, sloupce Zaúčtováno / Položka /
 * Číslo protiúčtu / symboly / Částka, KS a SS na dalších řádcích pod VS.
 */
final class CeskaSporitelnaStatementPdfParserTest extends TestCase
{
    private const X_DATE = 48.2;
    private const X_ITEM = 97.1;
    private const X_ACCOUNT = 248.7;
    private const X_RATE = 324.9;
    private const X_SYMBOL = 403.3;
    private const X_AMOUNT = 506.8;

    private static function text(string $prev, string $curr, string $credit, string $debit, int $debitCount, int $creditCount): string
    {
        return "Výpis z účtu\n"
            . "Podnikatelský účet Klasik\tObdobí: 01.09.2099 - 30.09.2099\n"
            . "Číslo účtu/kód banky: 1000000005/0800\tČíslo výpisu: 009\n"
            . "Název účtu: TESTOVACI FIRMA s.r.o.\n"
            . "Měna účtu: CZK\n"
            . "Kód banky (BIC): GIBACZPX\n"
            . "ZÁKLADNÍ ÚDAJE ÚČTU \n"
            . "Počáteční zůstatek:\t{$prev}\n"
            . "Celkem přišlo:\t{$credit}\n"
            . "Celkem odešlo:\t{$debit}\n"
            . "Konečný zůstatek:\t{$curr}\n"
            . "PŘEHLED POHYBŮ NA ÚČTU\tPočáteční zůstatek:\t{$prev}\n"
            . "Konečný zůstatek:\t{$curr}\n"
            . "SHRNUTÍ POHYBŮ NA ÚČTU\n"
            . "Počet položek na výpise\t{$debitCount}\t{$creditCount}\n"
            . "Česká spořitelna, a.s., Praha 4\n";
    }

    /** @param list<array{0:float,1:float,2:string}> $rows */
    private static function chunks(array $rows, string $curr): array
    {
        $chunks = [
            [42.5, 572.8, 'Počáteční zůstatek:'],
            [42.5, 544.0, 'Konečný zůstatek:'],
            [self::X_DATE, 497.1, 'Zaúčtováno'],
            [self::X_DATE, 487.5, 'Provedeno'],
            [self::X_ITEM, 497.1, 'Položka'],
            [self::X_ITEM, 487.5, 'Částka obratu cizí měny'],
            [self::X_ITEM, 477.9, 'Popis'],
            [self::X_ACCOUNT, 497.1, 'Číslo protiúčtu'],
            [self::X_ACCOUNT, 487.5, 'Název protiúčtu'],
            [self::X_ACCOUNT, 477.9, 'Kurz měny obratu / Kurz měny účtu'],
            [self::X_SYMBOL, 497.1, 'Variabilní symbol'],
            [self::X_SYMBOL, 487.5, 'Konstantní symbol'],
            [self::X_SYMBOL, 477.9, 'Specifický symbol'],
            [521.6, 497.1, 'Částka'],
            ...$rows,
            [self::X_ACCOUNT, 150.0, 'Konečný zůstatek:'],
            [490.4, 150.0, $curr],
            [self::X_DATE, 130.0, 'SHRNUTÍ POHYBŮ NA ÚČTU'],
            [self::X_DATE, 120.0, 'Počet položek na výpise'],
            [420.4, 120.0, '9'],
            [462.2, 47.7, 'Česká spořitelna, a.s., Praha 4'],
        ];
        return array_map(static fn (array $c): array => ['page' => 0, 'x' => $c[0], 'y' => $c[1], 'text' => $c[2]], $chunks);
    }

    /** @return list<array{0:float,1:float,2:string}> */
    private static function sampleRows(): array
    {
        return [
            [self::X_DATE, 462.8, '01.09.2099'],
            [self::X_ITEM, 462.8, 'Založení účtu'],
            [530.3, 462.8, '0.00'],

            [self::X_DATE, 450.6, '02.09.2099'],
            [self::X_ITEM, 450.6, 'Příchozí úhrada'],
            [self::X_ACCOUNT, 450.6, '123456789/0300'],
            [self::X_SYMBOL, 450.6, '5550045'],
            [499.1, 450.6, '+10 000.00'],
            [self::X_ACCOUNT, 441.2, 'TESTOVACI ODBERATEL'],

            [self::X_DATE, 404.4, '05.09.2099'],
            [self::X_ITEM, 404.4, 'Tuzemská odchozí úhrada'],
            [self::X_ITEM, 394.8, 'okamžitá'],
            [self::X_ACCOUNT, 404.4, '19-987654321/0100'],
            [self::X_SYMBOL, 404.4, '20990001'],
            [self::X_AMOUNT, 404.4, '-2 500.00'],
            [self::X_ITEM, 383.7, 'FAKTURA 2099-001'],
            [self::X_ITEM, 372.9, '05.09.2099'],

            [self::X_DATE, 225.0, '08.09.2099'],
            [self::X_ITEM, 225.0, 'Platba kartou'],
            [self::X_SYMBOL, 225.0, '06092099'],
            [518.8, 225.0, '-500.00'],
            [self::X_SYMBOL, 215.6, '1178'],
            [self::X_SYMBOL, 204.8, '0000001234'],
            [self::X_ITEM, 192.3, 'XXXXXXXXXXXX1234 d.tran.06.09.2099'],
            [self::X_RATE, 192.3, 'CZ TESTOVACI OBCHOD'],
        ];
    }

    public function testParsesStatementFromCellPositions(): void
    {
        $parser = new CeskaSporitelnaStatementPdfParser();
        $text = self::text('1 000.00', '8 000.00', '10 000.00', '-3 000.00', 2, 2);

        self::assertTrue($parser->supports($text));
        $result = $parser->parseChunks(self::chunks(self::sampleRows(), '8 000.00'), $text);

        $header = $result['header'];
        self::assertSame('1000000005', $header['account_number']);
        self::assertSame('0800', $header['bank_code']);
        self::assertSame('9', $header['statement_number']);
        self::assertSame('2099-09-30', $header['statement_date']);
        self::assertSame('2099-09-01', $header['period_from']);
        self::assertSame(1000.0, $header['prev_balance']);
        self::assertSame(8000.0, $header['curr_balance']);
        self::assertSame(10000.0, $header['credit_total']);
        self::assertSame(3000.0, $header['debit_total']);
        self::assertSame('CZK', $header['account_currency']);

        $txs = $result['transactions'];
        self::assertCount(3, $txs, 'Nulová položka „Založení účtu" se do pohybů nepřenáší.');

        self::assertSame('2099-09-02', $txs[0]['posted_at']);
        self::assertSame(10000.0, $txs[0]['amount']);
        self::assertSame('123456789', $txs[0]['counterparty_account']);
        self::assertSame('0300', $txs[0]['counterparty_bank']);
        self::assertSame('TESTOVACI ODBERATEL', $txs[0]['counterparty_name']);
        self::assertSame('5550045', $txs[0]['variable_symbol']);
        self::assertSame('CZK', $txs[0]['currency']);

        self::assertSame(-2500.0, $txs[1]['amount']);
        self::assertSame('19-987654321', $txs[1]['counterparty_account']);
        self::assertSame('0100', $txs[1]['counterparty_bank']);
        self::assertNull($txs[1]['counterparty_name'], 'Text ve sloupci Popis není název protiúčtu.');
        self::assertSame('Tuzemská odchozí úhrada | okamžitá | FAKTURA 2099-001', $txs[1]['description']);

        self::assertSame(-500.0, $txs[2]['amount']);
        self::assertSame('6092099', $txs[2]['variable_symbol']);
        self::assertSame('1178', $txs[2]['constant_symbol']);
        self::assertSame('1234', $txs[2]['specific_symbol']);
        self::assertSame('1234', $txs[2]['card_last4']);
        self::assertStringContainsString('CZ TESTOVACI OBCHOD', (string) $txs[2]['description']);
    }

    /** Samotné číslo pod VS je KS jen na druhém řádku; na třetím je to SS. */
    public function testLoneSymbolOnThirdRowIsSpecificSymbol(): void
    {
        $rows = [
            [self::X_DATE, 450.6, '02.09.2099'],
            [self::X_ITEM, 450.6, 'Příchozí úhrada'],
            [self::X_ACCOUNT, 450.6, '123456789/0300'],
            [499.1, 450.6, '+100.00'],
            [self::X_SYMBOL, 431.0, '777'],
        ];
        $text = self::text('0.00', '100.00', '100.00', '0.00', 0, 1);

        $tx = (new CeskaSporitelnaStatementPdfParser())->parseChunks(self::chunks($rows, '100.00'), $text)['transactions'][0];

        self::assertNull($tx['variable_symbol']);
        self::assertNull($tx['constant_symbol']);
        self::assertSame('777', $tx['specific_symbol']);
    }

    /**
     * Výpis ERSTE: poplatková položka má částku až pod názvem služby a záhlaví na další
     * straně má o řádek víc. Ani jedno nesmí přilepit text k sousednímu pohybu.
     */
    public function testFeeItemWithAmountBelowDateAndTallerHeader(): void
    {
        $firstPage = self::chunks([
            [self::X_DATE, 450.6, '25.09.2099'],
            [self::X_ITEM, 450.6, 'Inkaso'],
            [self::X_ACCOUNT, 450.6, '123456789/0300'],
            [self::X_SYMBOL, 450.6, '70066417'],
            [self::X_AMOUNT, 450.6, '-1 330.00'],
            [self::X_ITEM, 430.0, 'Číslo instrukce: 12'],
        ], '670.00');
        $firstPage = array_values(array_filter($firstPage, static fn (array $c): bool => $c['text'] !== 'Konečný zůstatek:' || $c['y'] > 400));
        $secondPage = array_map(static fn (array $c): array => ['page' => 1] + $c, self::chunks([
            [self::X_ITEM, 468.3, 'Popis'],
            [self::X_DATE, 450.6, '30.09.2099'],
            [self::X_ITEM, 450.6, 'Ceny za služby'],
            [self::X_ITEM, 438.4, 'Cena za návštěvu salonku'],
            [530.3, 438.4, '0.00'],
            [self::X_ITEM, 425.7, 'datum návštěvy: 7.9.2099'],
        ], '670.00'));
        $text = str_replace("Počet položek na výpise\t0\t0\n", '', self::text('2 000.00', '670.00', '0.00', '-1 330.00', 0, 0));

        $txs = (new CeskaSporitelnaStatementPdfParser())->parseChunks([...$firstPage, ...$secondPage], $text)['transactions'];

        self::assertCount(1, $txs, 'Nulová položka „Ceny za služby" se do pohybů nepřenáší.');
        self::assertSame(-1330.0, $txs[0]['amount']);
        self::assertSame('Inkaso | Číslo instrukce: 12', $txs[0]['description']);
    }

    public function testRejectsWhenItemCountDiffers(): void
    {
        $text = self::text('1 000.00', '8 000.00', '10 000.00', '-3 000.00', 2, 3);

        $this->expectException(\RuntimeException::class);
        (new CeskaSporitelnaStatementPdfParser())->parseChunks(self::chunks(self::sampleRows(), '8 000.00'), $text);
    }

    public function testRejectsWhenTurnoversDoNotMatchMovements(): void
    {
        $text = self::text('1 000.00', '8 000.00', '10 000.00', '-2 500.00', 2, 2);

        $this->expectException(\RuntimeException::class);
        (new CeskaSporitelnaStatementPdfParser())->parseChunks(self::chunks(self::sampleRows(), '8 000.00'), $text);
    }

    public function testDoesNotClaimCardAccountStatement(): void
    {
        $parser = new CeskaSporitelnaStatementPdfParser();

        self::assertFalse($parser->supports("Výpis z kartového účtu\nPŘEHLED POHYBŮ NA ÚČTU\nČeská spořitelna, a.s."));
        self::assertFalse($parser->supports("Výpis z účtu\nPŘEHLED POHYBŮ NA ÚČTU\nMONETA Money Bank"));
    }
}
