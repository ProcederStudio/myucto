<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Bank\Pdf\PdfPositionedText;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use PHPUnit\Framework\TestCase;

/**
 * Text s pozicí z vygenerovaného PDF. mPDF vkládá podmnožinu fontu s vícebajtovým
 * kódováním, tedy stejný případ, na kterém dekódování v `getDataCommands()` vracelo
 * nesmysly místo české diakritiky.
 */
final class PdfPositionedTextTest extends TestCase
{
    private const MM = 72 / 25.4;

    public function testReturnsDecodedTextWithPagePosition(): void
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmpDir, ...MpdfFontConfig::options()]);
        $mpdf->AddPage();
        $mpdf->SetFont('dejavusans', '', 9);
        $mpdf->WriteText(20, 40, 'Tuzemská odchozí úhrada');
        $mpdf->WriteText(120, 40, '-2 500.00');
        $mpdf->WriteText(20, 50, 'Příliš žluťoučký kůň');
        $mpdf->AddPage();
        $mpdf->WriteText(30, 60, 'Konečný zůstatek:');

        $chunks = PdfPositionedText::fromBytes((string) $mpdf->Output('', 'S'));

        $find = static function (string $text) use ($chunks): array {
            foreach ($chunks as $chunk) {
                if (trim($chunk['text']) === $text) return $chunk;
            }
            self::fail('V PDF chybí text „' . $text . '".');
        };
        $item = $find('Tuzemská odchozí úhrada');
        $amount = $find('-2 500.00');
        $second = $find('Příliš žluťoučký kůň');
        $nextPage = $find('Konečný zůstatek:');

        self::assertSame(0, $item['page']);
        self::assertEqualsWithDelta(20 * self::MM, $item['x'], 0.5);
        self::assertEqualsWithDelta((297 - 40) * self::MM, $item['y'], 0.5);
        self::assertEqualsWithDelta(120 * self::MM, $amount['x'], 0.5);
        self::assertEqualsWithDelta($item['y'], $amount['y'], 0.01, 'Buňky téhož řádku mají stejné y.');
        self::assertEqualsWithDelta(10 * self::MM, $item['y'] - $second['y'], 0.5);
        self::assertSame(1, $nextPage['page']);
        self::assertEqualsWithDelta(30 * self::MM, $nextPage['x'], 0.5);
    }
}
