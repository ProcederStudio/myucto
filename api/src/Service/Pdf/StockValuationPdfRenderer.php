<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Stock\StockReportXlsxExporter;

/**
 * Renderer PDF sestavy „Ocenění zásob k datu" (Epic SKLAD §6, B8) — A4 landscape,
 * vzor {@see TrialBalancePdfRenderer}. Data = výstup StockReportService::valuation().
 */
final class StockValuationPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $extra = [];
        if (is_array($data['category_groups'] ?? null)) {
            $parent = $data['category_groups']['parent'] ?? null;
            $extra = [
                'group_labels' => array_map(static fn (array $g): string => StockReportXlsxExporter::valuationGroupLabel($g, $parent), $data['category_groups']['groups']),
                'group_note' => StockReportXlsxExporter::VALUATION_GROUPS_NOTE,
            ];
        }
        $body = $this->renderTemplate('stock_valuation.twig', $data + $extra);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Ocenění zásob k ' . (string) ($data['date'] ?? ''));
        $this->withPageNumbers($mpdf, 'Ocenění zásob');
        // Dlouhý seznam po kusech kvůli pcre.backtrack_limit i paměti (viz ChunkedHtmlWriter).
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }
}
