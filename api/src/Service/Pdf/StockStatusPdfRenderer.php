<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Stock\StockStatusColumns;

/**
 * Renderer PDF sestavy „Stav zásob" (Epic SKLAD) — A4 landscape, vzor
 * {@see TrialBalancePdfRenderer}. Data = výstup StockReportService::status().
 */
final class StockStatusPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        // Sloupce podle výběru v sestavě (výchozí = původních osm), A4 na šířku vždy.
        $layout = StockStatusColumns::layout($data);
        $nameWidth = (float) (array_column($layout, 'width', 'key')['name'] ?? 35);
        // Název se zkracuje úměrně šířce sloupce (původně 55 znaků na 35 %).
        $body = $this->renderTemplate('stock_status.twig', ['items' => StockStatusColumns::exportItems($data)] + $data + [
            'layout' => $layout,
            'name_chars' => max(20, (int) round(55 * $nameWidth / 35)),
        ]);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Stav zásob');
        $this->withPageNumbers($mpdf, 'Stav zásob');
        // Dlouhý seznam po kusech kvůli pcre.backtrack_limit i paměti (viz ChunkedHtmlWriter).
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }
}
