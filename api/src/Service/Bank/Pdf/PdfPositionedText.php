<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser as PdfTextParser;
use Smalot\PdfParser\PDFObject;

/**
 * Text stránek PDF s pozicí každého vypsaného řetězce.
 *
 * `Page::getDataTm()` ze Smalotu páruje příkazy pro pozici s texty z jiného průchodu
 * stránkou (`getTextArray()`). Když ten průchod některý řetězec vynechá nebo přidá,
 * posunou se všechny další texty o buňku vedle; jeho vlastní dekódování textu
 * (`getDataCommands()`) zase u fontů s vícebajtovým kódováním vrací nesmysly.
 * Tady se obsah stránky prochází jednou: pozice se počítá z matic a text dekóduje
 * fontem z téhož příkazu stejně jako `getTextArray()`.
 */
final class PdfPositionedText
{
    /**
     * @return list<array{page:int,x:float,y:float,text:string}> Souřadnice v PDF bodech, y roste nahoru.
     * @throws \RuntimeException Když PDF nejde přečíst.
     */
    public static function fromBytes(string $pdfBytes): array
    {
        try {
            $pages = (new PdfTextParser())->parseContent($pdfBytes)->getPages();
            $out = [];
            foreach (array_values($pages) as $index => $page) {
                foreach (self::page($page) as $chunk) {
                    $out[] = ['page' => $index] + $chunk;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Nelze přečíst rozložení stránek PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return list<array{x:float,y:float,text:string}> */
    private static function page(Page $page): array
    {
        $identity = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $ctm = $identity;
        $line = $identity;
        $leading = 0.0;
        $font = null;
        $stack = [];
        $out = [];

        foreach ($page->getSectionsText(self::content($page)) as $section) {
            foreach ($page->getCommandsText($section) as $command) {
                $operands = is_string($command['c']) ? preg_split('/\s+/', trim($command['c'])) : [];
                switch ($command['o']) {
                    case 'q':
                        $stack[] = [$ctm, $font];
                        break;
                    case 'Q':
                        [$ctm, $font] = array_pop($stack) ?? [$identity, $font];
                        break;
                    case 'cm':
                        if (count($operands) >= 6) $ctm = self::multiply(array_map('floatval', $operands), $ctm);
                        break;
                    case 'Tf':
                        $id = trim((string) ($operands[0] ?? ''), '/');
                        $font = $page->getFont($id) ?? $font;
                        break;
                    case 'BT':
                        $line = $identity;
                        break;
                    case 'Tm':
                        if (count($operands) >= 6) $line = array_map('floatval', $operands);
                        break;
                    case 'TL':
                        $leading = (float) ($operands[0] ?? 0);
                        break;
                    case 'TD':
                        $leading = -(float) ($operands[1] ?? 0);
                        $line = self::translate($line, (float) ($operands[0] ?? 0), (float) ($operands[1] ?? 0));
                        break;
                    case 'Td':
                        $line = self::translate($line, (float) ($operands[0] ?? 0), (float) ($operands[1] ?? 0));
                        break;
                    case 'T*':
                        $line = self::translate($line, 0.0, -$leading);
                        break;
                    case "'":
                    case '"':
                        $line = self::translate($line, 0.0, -$leading);
                        $out[] = self::chunk($line, $ctm, self::decode($page, $font, [$command]));
                        break;
                    case 'Tj':
                        $out[] = self::chunk($line, $ctm, self::decode($page, $font, [$command]));
                        break;
                    case 'TJ':
                        $out[] = self::chunk($line, $ctm, self::decode($page, $font, is_array($command['c']) ? $command['c'] : [$command]));
                        break;
                }
            }
        }

        return $out;
    }

    /** Obsahový proud stránky, i když je rozdělený do více objektů. */
    private static function content(Page $page): string
    {
        $contents = $page->get('Contents');
        if ($contents instanceof ElementArray) {
            $content = '';
            foreach ($contents->getContent() as $part) {
                $content .= $part->getContent() . "\n";
            }
            return $content;
        }
        if ($contents instanceof PDFObject) {
            $elements = $contents->getHeader()->getElements();
            if (is_numeric(key($elements))) {
                $content = '';
                foreach ($elements as $element) {
                    $content .= method_exists($element, 'getObject') ? $element->getObject()->getContent() : $element->getContent();
                }
                return $content;
            }
            return (string) $contents->getContent();
        }
        return '';
    }

    /**
     * Stejné dekódování jako `getTextArray()`: dá-li font řídicí znaky, zkusí se
     * ostatní fonty stránky.
     */
    private static function decode(Page $page, ?Font $font, array $command): string
    {
        $fonts = $page->getFonts();
        $font ??= $fonts !== [] ? reset($fonts) : null;
        if ($font === null) return '';

        $original = $font->decodeText($command);
        $text = $original;
        $ids = array_keys($fonts);
        while (preg_match('/[\x00-\x08\x0e-\x1f\x7f]/u', $text) || str_contains(bin2hex($text), '00')) {
            if ($ids === []) return $original;
            $next = $page->getFont((string) array_shift($ids));
            if ($next === null) continue;
            $text = $next->decodeText($command);
        }
        return str_replace(["\r", "\n"], '', $text);
    }

    /** @param list<float> $tm */
    private static function chunk(array $tm, array $ctm, string $text): array
    {
        $m = self::multiply($tm, $ctm);
        return ['x' => round($m[4], 2), 'y' => round($m[5], 2), 'text' => $text];
    }

    /** @param list<float> $m */
    private static function translate(array $m, float $tx, float $ty): array
    {
        $m[4] += $tx * $m[0] + $ty * $m[2];
        $m[5] += $tx * $m[1] + $ty * $m[3];
        return $m;
    }

    /**
     * Součin matic a × b ve tvaru [a b c d e f] podle PDF.
     *
     * @param list<float> $a
     * @param list<float> $b
     * @return list<float>
     */
    private static function multiply(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }
}
