<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\TwigCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** PDF přehledu mezd za měsíc ({@see PayrollMonthlyOverviewService}). */
final class PayrollMonthlyOverviewPdfRenderer
{
    public const VERSION = 'mz-monthly-overview-v1';

    private ?Environment $twig = null;

    /** @param array<string,mixed> $data */
    public function renderHtml(array $data): string
    {
        return $this->twig()->render('monthly-overview.twig', $data + [
            'generated_on' => (new \DateTimeImmutable())->format('d.m.Y'),
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{bytes:string,filename:string}
     */
    public function render(array $data): array
    {
        $body = $this->renderHtml($data);
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 8,
            'margin_right' => 8,
            'margin_top' => 9,
            'margin_bottom' => 12,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
        $period = (string) $data['period'];
        $mpdf->SetTitle('Přehled mezd ' . $period);
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->SetHTMLFooter(
            '<table style="width:100%;border-top:0.3pt solid #DEC8D4;'
            . 'font-family:montserrat,dejavusans,sans-serif;font-size:7pt;color:#6B5C66">'
            . '<tr><td>Přehled mezd ' . htmlspecialchars((string) $data['period_label'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="text-align:center">Strana {PAGENO} / {nbpg}</td>'
            . '<td style="text-align:right">MyÚčto.cz</td></tr></table>',
        );
        $mpdf->WriteHTML($body);
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException('mPDF nevytvořilo platný přehled mezd.');
        }

        return ['bytes' => $pdf, 'filename' => 'prehled-mezd-' . $period . '.pdf'];
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader([Bootstrap::rootDir() . '/api/templates/payroll']),
                [
                    'autoescape' => 'html',
                    'strict_variables' => true,
                ] + TwigCache::options('payroll'),
            );
            $this->twig->addFilter(new \Twig\TwigFilter(
                'money',
                static fn (?int $minor): string => $minor === null ? '' : PayrollSheetMatrix::format($minor, 'money'),
            ));
            $this->twig->addFilter(new \Twig\TwigFilter(
                'hours',
                static fn (?int $millihours): string => $millihours === null ? '' : PayslipPdfRenderer::formatMillihours($millihours),
            ));
        }

        return $this->twig;
    }
}
