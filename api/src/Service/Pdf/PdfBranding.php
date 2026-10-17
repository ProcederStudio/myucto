<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Mail\SafeLogoPath;

/**
 * Sdílená brandingová logika pro PDF (faktura + výkaz víceprací):
 *   - resolveLogoPath: gate na email_branding_enabled, SafeLogoPath validace,
 *     preference SVG sidecaru (crisp), fallback PNG,
 *   - accentCss: override CSS přebarvující fialové akcenty na zvolenou barvu.
 *
 * Cíl: faktura i výkaz mají identické chování hlavičky (3 varianty: bez loga →
 * textový název, jen logo, logo + název) a stejné barevné branding změny.
 */
final class PdfBranding
{
    /**
     * Logo pro PDF — jen když má dodavatel zapnutý branding (email_branding_enabled).
     * Bez brandingu vrací null → šablona vykreslí textový brand-name fallback.
     * Preferuje SVG sidecar (vektor), pokud je mPDF-kompatibilní; jinak PNG.
     */
    public static function logoPath(array $supplier, int $supplierIdFallback = 0): ?string
    {
        if (empty($supplier['email_branding_enabled'])) {
            return null;
        }
        $logoPath = $supplier['logo_path'] ?? null;
        if (!$logoPath) {
            return null;
        }

        // SafeLogoPath: defense-in-depth proti podstrčenému logo_path (security #2).
        $supplierId = (int) ($supplier['id'] ?? $supplierIdFallback);
        $abs = SafeLogoPath::resolve((string) $logoPath, $supplierId);
        if ($abs === null) {
            return null;
        }

        // SVG sidecar preferujeme (crisp), pokud neobsahuje mPDF-problematické prvky.
        $svgSibling = preg_replace('/\.png$/i', '.svg', (string) $logoPath);
        if (is_string($svgSibling) && $svgSibling !== $logoPath) {
            $svgAbs = SafeLogoPath::resolve($svgSibling, $supplierId);
            if ($svgAbs !== null && self::svgIsMpdfCompatible($svgAbs)) {
                return $svgAbs;
            }
        }
        // PNG fallback: splácni alfa kanál na bílou — mPDF neumí SMask u truecolor
        // RGBA PNG a vykreslil by průhledné pozadí černě (issue #152).
        return PdfLogoFlattener::flattenedPath($abs);
    }

    /**
     * SVG features, které mPDF nevykreslí korektně → fallback na PNG.
     * Gradienty v blocklistu záměrně nejsou (issue #37) — mPDF je umí a rastrový
     * fallback ztrácel `<text>` na hostech bez příslušného fontu.
     */
    private static function svgIsMpdfCompatible(string $svgPath): bool
    {
        $svg = (string) @file_get_contents($svgPath);
        if ($svg === '') {
            return false;
        }
        $bad = '/<(?:clipPath|use|mask|pattern|filter)\b/i';
        return !preg_match($bad, $svg);
    }

    /** Úsporná černobílá varianta PDF (migrace 2033), cesta relativně ke kořeni instalace. */
    public const MONOCHROME_CSS = 'styles/invoice-mono.css';

    /**
     * CSS připojované za base invoice.css: černobílý stylopis, když má dodavatel
     * zapnutou úspornou černobílou fakturu (`invoice_pdf_monochrome`), jinak barevný
     * akcent. Černobílá volba akcent nahrazuje, ne doplňuje: barvy brandingu by jinak
     * vrátily plné plochy, které černobílý stylopis právě vypíná.
     */
    public static function themeCss(array $supplier): string
    {
        if (!empty($supplier['invoice_pdf_monochrome'])) {
            $path = Bootstrap::rootDir() . '/' . self::MONOCHROME_CSS;
            return is_file($path) ? (string) file_get_contents($path) : '';
        }
        return self::accentCss($supplier);
    }

    /**
     * Per-supplier accent override CSS — přebarví fialové akcenty (#3B2D83 + světlé
     * varianty/linky) na zvolenou barvu. Gated na email_branding_enabled + nedefaultní
     * hex. Vrací '' pokud branding vypnutý nebo defaultní barva (ta je už v base CSS).
     *
     * Selektory pokrývají fakturu i výkaz (přebytečné selektory u výkazu jsou no-op:
     * .head border, .brand-name, .doc-type, .wr-title/.wr-link platí pro oba).
     */
    public static function accentCss(array $supplier): string
    {
        if (empty($supplier['email_branding_enabled'])) {
            return '';
        }
        $color = AccentColor::normalize($supplier['email_accent_color'] ?? null);
        if ($color === null || $color === AccentColor::DEFAULT) {
            return '';
        }

        $bgSoft     = AccentColor::tint($color, 0.08);
        $lineSoft   = AccentColor::tint($color, 0.24);
        $lineMedium = AccentColor::tint($color, 0.28);
        $badgeBorder = AccentColor::tint($color, 0.30);

        return "\n/* ─── Branding override (per-supplier accent color) ─── */\n"
            . ".head { border-bottom-color: {$color}; }\n"
            . ".brand-name, .doc-type { color: {$color}; }\n"
            . ".parties h2, td.meta-label, .bank-label { color: {$color}; }\n"
            . ".parties td.party-customer { background: {$bgSoft}; }\n"
            . ".pay-panel { background: {$bgSoft}; border-color: {$lineMedium}; }\n"
            . "table.items th { background: {$color}; }\n"
            . "table.totals-table tr.grand td { background: {$color}; }\n"
            . "table.totals-table tr.to-pay td { border-top-color: {$color}; color: {$color}; background: {$bgSoft}; }\n"
            . "table.totals-table tr.subtotal td { border-top-color: {$lineSoft}; }\n"
            . "table.czk-recap td.czk-recap-title, table.czk-recap tr.grand td { color: {$color}; }\n"
            . "table.czk-recap td.czk-recap-title { border-bottom-color: {$lineMedium}; }\n"
            . "table.czk-recap tr.subtotal td { border-top-color: {$lineSoft}; }\n"
            . ".isdoc-badge { color: {$color}; background: {$bgSoft}; border-color: {$badgeBorder}; }\n"
            . ".note { border-left-color: {$color}; }\n"
            . ".note.rc-note { border-left-color: #E8A547; }\n"
            . ".note.oss-note { border-left-color: #2F6FA8; }\n"
            . ".footer { border-top-color: {$lineSoft}; }\n"
            . ".footer-name { color: {$color}; }\n"
            . ".wr-title, .wr-link { color: {$color}; }\n";
    }
}
