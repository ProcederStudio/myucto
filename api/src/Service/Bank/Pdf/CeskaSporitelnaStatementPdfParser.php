<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Card\CardNumberMask;

/**
 * Parser PDF „Výpis z účtu" České spořitelny (běžný a podnikatelský účet, George).
 *
 * Hlavička: „Číslo účtu/kód banky: <účet>/0800", „Číslo výpisu:", „Období: d.m.rrrr -
 * d.m.rrrr", „Měna účtu:", souhrn „Počáteční zůstatek / Celkem přišlo / Celkem odešlo /
 * Konečný zůstatek" a v závěru „Počet položek na výpise <debet> <kredit>". Částky mají
 * desetinnou TEČKU, mezeru jako oddělovač tisíců a u pohybů vždy znaménko.
 *
 * Tabulka „PŘEHLED POHYBŮ NA ÚČTU" má na pohyb tři řádky po čtyřech sloupcích:
 *
 *   Zaúčtováno | Položka                 | Číslo protiúčtu                 | VS | Částka
 *   Provedeno  | Částka obratu cizí měny | Název protiúčtu                 | KS
 *              | Popis                   | Kurz měny obratu / účtu         | SS
 *
 * Prázdné buňky textová vrstva nijak neoznačí, takže z prostého textu nejde poznat,
 * jestli je řádek pod pohybem název protiúčtu, nebo popis platby, a jestli samotné
 * číslo je KS, nebo SS. Pohyby se proto čtou z pozic textu na stránce: sloupce určí
 * záhlaví tabulky, řádek symbolu jeho vzdálenost od řádku s datem zaúčtování.
 *
 * Self-check: počet položek, kreditní i debetní obrat a změna zůstatku musí sedět na
 * souhrn výpisu, jinak se parsování zamítne.
 */
final class CeskaSporitelnaStatementPdfParser implements BankStatementPdfParserInterface
{
    private const MONEY = '[+-]?\d{1,3}(?: \d{3})*\.\d{2}';
    private const DATE = '/^(\d{2})\.(\d{2})\.(\d{4})$/';
    private const ACCOUNT = '/^((?:\d{1,6}-)?\d{1,10})\/(\d{4})$/';

    /** Tolerance svislé pozice (pt), ve které leží buňky téhož řádku. */
    private const SAME_ROW = 1.5;
    /** Tolerance vodorovné pozice (pt) vůči levému okraji sloupce ze záhlaví. */
    private const COLUMN_SLACK = 5.0;

    public function key(): string
    {
        return 'csas';
    }

    public function supports(string $text): bool
    {
        $text = $this->normalize($text);
        return str_contains($text, 'Výpis z účtu')
            && str_contains($text, 'PŘEHLED POHYBŮ NA ÚČTU')
            && !str_contains($text, 'Výpis z kartového účtu')
            && (str_contains($text, 'GIBACZPX') || str_contains($text, 'Česká spořitelna'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        return $this->parseChunks(PdfPositionedText::fromBytes($pdfBytes), $text);
    }

    /**
     * @param list<array{page:int,x:float,y:float,text:string}> $chunks Text stránek s pozicí (PDF body, y roste nahoru).
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function parseChunks(array $chunks, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $rows = $this->parseTransactionsFromChunks($chunks);

        if ($header['item_count'] !== null && $header['item_count'] !== count($rows)) {
            throw new \RuntimeException(sprintf(
                'Česká spořitelna PDF: výpis uvádí %d položek, přečteno %d. Parsování zamítnuto.',
                $header['item_count'],
                count($rows),
            ));
        }
        $creditCents = 0;
        $debitCents = 0;
        foreach ($rows as $row) {
            $cents = (int) round($row['amount'] * 100);
            if ($cents >= 0) $creditCents += $cents;
            else $debitCents -= $cents;
        }
        if ($creditCents !== (int) round($header['credit_total'] * 100) || $debitCents !== (int) round($header['debit_total'] * 100)) {
            throw new \RuntimeException('Česká spořitelna PDF: součet pohybů nesedí na obraty výpisu. Parsování zamítnuto.');
        }
        if ((int) round(($header['curr_balance'] - $header['prev_balance']) * 100) !== $creditCents - $debitCents) {
            throw new \RuntimeException('Česká spořitelna PDF: obraty nesedí na změnu zůstatku. Parsování zamítnuto.');
        }

        $currency = (string) $header['currency'];
        $transactions = [];
        foreach ($rows as $row) {
            // Nulová položka („Založení účtu") se do obratů počítá, ale pohybem není.
            if ((int) round($row['amount'] * 100) === 0) continue;
            $row['currency'] = $currency;
            $transactions[] = $row;
        }

        unset($header['currency'], $header['item_count']);
        $header['account_currency'] = $currency;

        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * @return array{account_number:string, bank_code:string, statement_date:string, statement_number:string,
     *   prev_balance:float, curr_balance:float, debit_total:float, credit_total:float,
     *   currency:string, period_kind:string, period_from:string, item_count:?int}
     */
    public function parseHeaderFromText(string $text): array
    {
        $text = $this->normalize($text);

        if (!preg_match('/Číslo účtu\/kód banky:\s*((?:\d{1,6}-)?\d{1,10})\/(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('Česká spořitelna PDF: chybí číslo účtu v hlavičce.');
        }
        $account = $m[1];
        $bankCode = $m[2];
        if (!preg_match('/Období:\s*(\d{2})\.(\d{2})\.(\d{4})\s*-\s*(\d{2})\.(\d{2})\.(\d{4})/u', $text, $p)) {
            throw new \RuntimeException('Česká spořitelna PDF: chybí období výpisu.');
        }
        $number = preg_match('/Číslo výpisu:\s*(\d+)/u', $text, $n) ? (ltrim($n[1], '0') ?: '0') : '';
        if (!preg_match('/Měna účtu:\s*([A-Z]{3})/u', $text, $c)) {
            throw new \RuntimeException('Česká spořitelna PDF: chybí měna účtu.');
        }

        return [
            'account_number'   => $account,
            'bank_code'        => $bankCode,
            'statement_date'   => $this->date($p[4], $p[5], $p[6]),
            'statement_number' => $number,
            'prev_balance'     => $this->amountAfter($text, 'Počáteční zůstatek:'),
            'curr_balance'     => $this->amountAfter($text, 'Konečný zůstatek:'),
            'debit_total'      => abs($this->amountAfter($text, 'Celkem odešlo:')),
            'credit_total'     => abs($this->amountAfter($text, 'Celkem přišlo:')),
            'currency'         => $c[1],
            'period_kind'      => 'period',
            'period_from'      => $this->date($p[1], $p[2], $p[3]),
            'item_count'       => preg_match('/Počet položek na výpise\s+(\d+)\s+(\d+)/u', $text, $i) ? (int) $i[1] + (int) $i[2] : null,
        ];
    }

    /**
     * Pohyby včetně nulových položek (ty vyřadí až {@see parseChunks()} po kontrole počtu).
     *
     * @param list<array{page:int,x:float,y:float,text:string}> $chunks
     * @return list<array<string,mixed>>
     */
    public function parseTransactionsFromChunks(array $chunks): array
    {
        $pages = [];
        foreach ($chunks as $chunk) {
            $text = trim((string) preg_replace('/ {2,}/', ' ', $this->normalize($chunk['text'])));
            if ($text === '') continue;
            $pages[$chunk['page']][] = ['x' => (float) $chunk['x'], 'y' => (float) $chunk['y'], 'text' => $text];
        }
        ksort($pages);

        $blocks = [];
        foreach ($pages as $page) {
            $columns = $this->columns($page);
            if ($columns === null) continue;
            $cells = $this->tableCells($page, $columns);
            usort($cells, static fn (array $a, array $b): int => [$b['y'], $a['x']] <=> [$a['y'], $b['x']]);

            $starts = $this->starts($cells, $columns);
            $pageBlocks = array_fill_keys(array_keys($starts), []);
            foreach ($cells as $cell) {
                $owner = null;
                foreach ($starts as $index => $start) {
                    if ($start['y'] >= $cell['y'] - self::SAME_ROW) $owner = $index;
                }
                if ($owner !== null) {
                    $pageBlocks[$owner][] = $cell;
                } elseif ($blocks !== []) {
                    // Pohyb zalomený na další stranu: pokračování patří poslednímu pohybu.
                    $blocks[array_key_last($blocks)]['cells'][] = $cell + ['continued' => true];
                }
            }
            foreach ($starts as $index => $start) {
                $blocks[] = $start + ['columns' => $columns, 'cells' => $pageBlocks[$index]];
            }
        }

        return array_map(fn (array $block): array => $this->row($block), $blocks);
    }

    /**
     * Začátky pohybů: datum zaúčtování a řádek s částkou. Částka stojí obvykle na řádku
     * data; u poplatkových položek („Ceny za služby") až o řádek níž, pod názvem služby
     * (s větší mezerou, než je výška řádku v záhlaví).
     *
     * @param list<array{x:float,y:float,text:string}> $cells Seřazené shora dolů.
     * @param array<string,float> $columns
     * @return list<array{y:float,amount_y:float}>
     */
    private function starts(array $cells, array $columns): array
    {
        $dates = [];
        $amounts = [];
        foreach ($cells as $cell) {
            if ($cell['x'] < $columns['item'] - self::COLUMN_SLACK && preg_match(self::DATE, $cell['text'])) {
                $dates[] = $cell['y'];
            } elseif ($cell['x'] >= $columns['symbol'] - self::COLUMN_SLACK && preg_match('/^' . self::MONEY . '$/', $cell['text'])) {
                $amounts[] = $cell['y'];
            }
        }

        $starts = [];
        $unclaimed = [];
        foreach ($amounts as $amountY) {
            $date = null;
            foreach ($dates as $y) {
                if (abs($y - $amountY) <= self::SAME_ROW) $date = $y;
            }
            if ($date !== null) {
                $starts[(string) $date] = ['y' => $date, 'amount_y' => $amountY];
            } else {
                $unclaimed[] = $amountY;
            }
        }
        foreach ($unclaimed as $amountY) {
            foreach ($dates as $y) {
                if ($y > $amountY && $y - $amountY <= 2 * $columns['row_step'] && !isset($starts[(string) $y])) {
                    $starts[(string) $y] = ['y' => $y, 'amount_y' => $amountY];
                    break;
                }
            }
        }
        usort($starts, static fn (array $a, array $b): int => $b['y'] <=> $a['y']);

        return $starts;
    }

    /** @param array{y:float,amount_y:float,columns:array<string,float>,cells:list<array<string,mixed>>} $block */
    private function row(array $block): array
    {
        $columns = $block['columns'];
        $startY = $block['y'];
        $postedAt = null;
        $amount = null;
        $account = null;
        $bank = null;
        $name = null;
        $symbols = [];
        $parts = [];
        $extra = [];

        foreach ($block['cells'] as $cell) {
            $text = $cell['text'];
            $x = $cell['x'];
            $sameRow = empty($cell['continued']) && abs($cell['y'] - $startY) <= self::SAME_ROW;
            if ($x < $columns['item'] - self::COLUMN_SLACK) {
                if ($postedAt === null && $sameRow && preg_match(self::DATE, $text, $d)) {
                    $postedAt = $this->date($d[1], $d[2], $d[3]);
                }
                continue;
            }
            if ($x < $columns['counterparty'] - self::COLUMN_SLACK) {
                if (!preg_match(self::DATE, $text) && preg_match('/[\p{L}\p{N}]/u', $text)) $parts[] = $text;
                continue;
            }
            if ($x < $columns['symbol'] - self::COLUMN_SLACK) {
                if ($account === null && preg_match(self::ACCOUNT, $text, $a)) {
                    $account = $a[1];
                    $bank = $a[2];
                } elseif ($account === null && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', str_replace(' ', '', $text))) {
                    $account = str_replace(' ', '', $text);
                } elseif ($name === null && abs($x - $columns['counterparty']) <= self::COLUMN_SLACK) {
                    $name = $text;
                } elseif (preg_match('/[\p{L}\p{N}]/u', $text)) {
                    $extra[] = $text;
                }
                continue;
            }
            if ($amount === null && empty($cell['continued']) && abs($cell['y'] - $block['amount_y']) <= self::SAME_ROW
                && preg_match('/^' . self::MONEY . '$/', $text)) {
                $amount = $this->num($text);
                continue;
            }
            if (preg_match('/^\d{1,10}$/', $text) && empty($cell['continued'])) {
                $line = (int) round(($startY - $cell['y']) / $columns['row_step']);
                $symbols[$line] ??= $this->symbol($text);
            }
        }
        if ($postedAt === null || $amount === null) {
            throw new \RuntimeException('Česká spořitelna PDF: pohyb bez data zaúčtování nebo částky. Parsování zamítnuto.');
        }

        $description = implode(' | ', [...$parts, ...$extra]);
        return [
            'posted_at'            => $postedAt,
            'amount'               => $amount,
            'variable_symbol'      => $symbols[0] ?? null,
            'constant_symbol'      => $symbols[1] ?? null,
            'specific_symbol'      => $symbols[2] ?? null,
            'counterparty_account' => $account,
            'counterparty_bank'    => $bank,
            'counterparty_name'    => $name !== null ? mb_substr($name, 0, 190) : null,
            'card_last4'           => CardNumberMask::last4FromText($description),
            'description'          => $description !== '' ? mb_substr($description, 0, 255) : null,
            'bank_ref'             => null,
        ];
    }

    /**
     * Levé okraje sloupců a výška řádku ze záhlaví tabulky; null = stránka bez tabulky pohybů.
     *
     * @param list<array{x:float,y:float,text:string}> $page
     * @return array{date:float,item:float,counterparty:float,symbol:float,top:float,row_step:float}|null
     */
    private function columns(array $page): ?array
    {
        $find = static function (string $label) use ($page): ?array {
            foreach ($page as $chunk) {
                if ($chunk['text'] === $label) return $chunk;
            }
            return null;
        };
        $date = $find('Zaúčtováno');
        $item = $find('Položka');
        $counterparty = $find('Číslo protiúčtu');
        $vs = $find('Variabilní symbol');
        $ks = $find('Konstantní symbol');
        $ss = $find('Specifický symbol');
        if ($date === null || $item === null || $counterparty === null || $vs === null || $ks === null || $ss === null) {
            return null;
        }
        $step = $vs['y'] - $ks['y'];
        // Záhlaví má podle stránky tři i čtyři řádky; konec určí nejnižší popisek záhlaví.
        $top = min($ss['y'], $ks['y'], $vs['y']);
        foreach (['Provedeno', 'Částka obratu cizí měny', 'Popis', 'Název protiúčtu', 'Kurz měny obratu / Kurz měny účtu'] as $label) {
            foreach ($page as $chunk) {
                if ($chunk['text'] === $label && $chunk['y'] < $date['y'] && $date['y'] - $chunk['y'] <= 4 * max($step, 10.0)) {
                    $top = min($top, $chunk['y']);
                }
            }
        }

        return [
            'date'         => $date['x'],
            'item'         => $item['x'],
            'counterparty' => $counterparty['x'],
            'symbol'       => $vs['x'],
            'top'          => $top,
            'row_step'     => $step > 1.0 ? $step : 10.0,
        ];
    }

    /**
     * Buňky pod záhlavím tabulky a nad jejím koncem (konečný zůstatek nebo patička stránky).
     *
     * @param list<array{x:float,y:float,text:string}> $page
     * @param array<string,float> $columns
     * @return list<array{x:float,y:float,text:string}>
     */
    private function tableCells(array $page, array $columns): array
    {
        $top = $columns['top'] - 0.5;
        $bottom = null;
        foreach ($page as $chunk) {
            if ($chunk['y'] >= $top) continue;
            if (str_starts_with($chunk['text'], 'Konečný zůstatek')
                || str_starts_with($chunk['text'], 'SHRNUTÍ POHYBŮ')
                || str_starts_with($chunk['text'], 'Pokračování na další straně')
                || str_starts_with($chunk['text'], 'Česká spořitelna, a.s.')) {
                $bottom = max($bottom ?? $chunk['y'], $chunk['y']);
            }
        }

        return array_values(array_filter(
            $page,
            static fn (array $c): bool => $c['y'] < $top && ($bottom === null || $c['y'] > $bottom + 0.5),
        ));
    }

    private function amountAfter(string $text, string $label): float
    {
        if (!preg_match('/' . preg_quote($label, '/') . '\s*(' . self::MONEY . ')/u', $text, $m)) {
            throw new \RuntimeException('Česká spořitelna PDF: chybí „' . rtrim($label, ':') . '".');
        }
        return $this->num($m[1]);
    }

    private function symbol(string $cell): ?string
    {
        $value = ltrim($cell, '0');
        return $value !== '' ? $value : null;
    }

    private function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    }

    private function date(string $day, string $month, string $year): string
    {
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new \RuntimeException('Česká spořitelna PDF: neplatné datum.');
        }
        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    private function num(string $value): float
    {
        return (float) str_replace([' ', '+'], '', $value);
    }
}
