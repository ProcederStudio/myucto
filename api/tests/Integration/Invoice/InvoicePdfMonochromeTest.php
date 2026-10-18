<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfBranding;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Úsporná černobílá faktura (`supplier.invoice_pdf_monochrome`).
 *
 * ── Co test hlídá ───────────────────────────────────────────────────────────
 * 1. Bez volby je CSS dokladu beze změny (jen base invoice.css + případný akcent).
 * 2. S volbou se místo barevného akcentu připojí styles/invoice-mono.css.
 * 3. Černobílý stylopis neutralizuje VŠECHNY plné plochy a barevné texty/čáry,
 *    které base stylopis nastavuje. Hlídá se to obecně (parsováním obou CSS), aby
 *    nová barevná plocha přidaná do invoice.css bez protějšku v invoice-mono.css
 *    test shodila, ne až tiskárnu zákazníka.
 * 4. Šablony nenesou barevné inline styly: mPDF `!important` zahazuje, inline styl
 *    tedy žádný stylopis nepřebije a černobílá varianta by ho nevypnula.
 * 5. Dobropis a storno se i bez barvy liší textem v hlavičce.
 *
 * Render běží nad syntetickým polem faktury (jako OssInvoiceClauseRenderTest),
 * do DB se nic nezapisuje.
 */
#[Group('integration')]
final class InvoicePdfMonochromeTest extends TestCase
{
    private InvoicePdfRenderer $renderer;
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        if (!is_file($this->rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $this->renderer = Bootstrap::buildContainer()->get(InvoicePdfRenderer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    public function testWithoutOptionThePdfCssIsExactlyTheBaseStylesheet(): void
    {
        $rendered = $this->renderer->renderHtmlAndCss($this->invoice());

        self::assertSame($this->baseCss(), $rendered['css']);
    }

    public function testWithoutOptionTheBrandAccentStillApplies(): void
    {
        $rendered = $this->renderer->renderHtmlAndCss($this->invoice(['email_branding_enabled' => 1, 'email_accent_color' => '#C0392B']));

        self::assertStringContainsString('table.items th { background: #C0392B; }', $rendered['css']);
        self::assertStringNotContainsString($this->monoCss(), $rendered['css']);
    }

    public function testMonochromeReplacesTheAccentWithTheMonochromeStylesheet(): void
    {
        $rendered = $this->renderer->renderHtmlAndCss($this->invoice([
            'invoice_pdf_monochrome' => 1,
            'email_branding_enabled' => 1,
            'email_accent_color'     => '#C0392B',
        ]));

        self::assertSame($this->baseCss() . $this->monoCss(), $rendered['css']);
        self::assertStringNotContainsString('#C0392B', $rendered['css']);
    }

    public function testHtmlPreviewWithInlineCssCarriesTheMonochromeStylesheet(): void
    {
        $html = $this->renderer->renderHtml($this->invoice(['invoice_pdf_monochrome' => 1]));

        self::assertStringContainsString($this->monoCss(), $html);
    }

    public function testWorkReportSharesTheSameThemeCss(): void
    {
        self::assertSame($this->monoCss(), PdfBranding::themeCss(['invoice_pdf_monochrome' => 1, 'email_branding_enabled' => 1, 'email_accent_color' => '#C0392B']));
        self::assertSame(
            PdfBranding::accentCss(['email_branding_enabled' => 1, 'email_accent_color' => '#C0392B']),
            PdfBranding::themeCss(['invoice_pdf_monochrome' => 0, 'email_branding_enabled' => 1, 'email_accent_color' => '#C0392B']),
        );
        self::assertSame('', PdfBranding::themeCss([]));
    }

    public function testCreditNoteAndCancellationAreDistinguishedByTextInMonochrome(): void
    {
        $creditNote = $this->renderer->renderHtml(
            ['invoice_type' => 'credit_note'] + $this->invoice(['invoice_pdf_monochrome' => 1]),
            includeCss: false,
        );
        $cancellation = $this->renderer->renderHtml(
            ['invoice_type' => 'cancellation'] + $this->invoice(['invoice_pdf_monochrome' => 1]),
            includeCss: false,
        );

        self::assertStringContainsString('Opravný daňový doklad', $creditNote);
        self::assertStringContainsString('Storno (interní)', $cancellation);
    }

    /**
     * Každá plná plocha base stylopisu (background jiný než bílý) musí mít
     * v černobílém stylopisu protějšek se stejným selektorem a bílým pozadím.
     */
    public function testMonochromeNeutralisesEveryFilledSurfaceOfTheBaseStylesheet(): void
    {
        $base = self::parseCss($this->baseCss());
        $mono = self::parseCss($this->monoCss());
        $missing = [];
        foreach ($base as $selector => $decls) {
            foreach (['background', 'background-color'] as $prop) {
                if (!isset($decls[$prop]) || self::isWhite($decls[$prop])) continue;
                if (!isset($mono[$selector][$prop]) || !self::isWhite($mono[$selector][$prop])) {
                    $missing[] = "{$selector} { {$prop} }";
                }
            }
        }

        self::assertSame([], $missing, 'Plné plochy bez bílého protějšku v invoice-mono.css');
    }

    /**
     * Každý text s barvou (kromě černé a inherit) musí být v černobílém stylopisu
     * přebarvený na tmavou neutrální; každá čára s barevným nádechem na neutrální.
     */
    public function testMonochromeTurnsEveryTextBlackAndEveryTintedLineNeutral(): void
    {
        $base = self::parseCss($this->baseCss());
        $mono = self::parseCss($this->monoCss());
        $missing = [];
        foreach ($base as $selector => $decls) {
            foreach ($decls as $prop => $value) {
                if ($prop === 'color') {
                    if (in_array(strtolower($value), ['inherit', '#000', '#000000'], true)) continue;
                    if (!isset($mono[$selector]['color']) || !self::isDarkNeutral($mono[$selector]['color'])) {
                        $missing[] = "{$selector} { color }";
                    }
                    continue;
                }
                if (!str_starts_with($prop, 'border')) continue;
                if (!self::hasTintedHex($value, 0)) continue;
                if (!isset($mono[$selector][$prop]) || self::hasTintedHex($mono[$selector][$prop], 0)) {
                    $missing[] = "{$selector} { {$prop} }";
                }
            }
        }

        self::assertSame([], $missing, 'Barevné texty nebo čáry bez neutrálního protějšku v invoice-mono.css');
    }

    public function testMonochromeStylesheetItselfUsesNoColourAndNoFilledSurface(): void
    {
        foreach (self::parseCss($this->monoCss()) as $selector => $decls) {
            foreach ($decls as $prop => $value) {
                self::assertFalse(self::hasTintedHex($value, 0), "{$selector} { {$prop}: {$value} } nese barvu");
                if ($prop === 'background' || $prop === 'background-color') {
                    self::assertTrue(self::isWhite($value), "{$selector} { {$prop}: {$value} } není bílé pozadí");
                }
                if ($prop === 'color') {
                    self::assertTrue(self::isDarkNeutral($value), "{$selector} { color: {$value} } není tmavý text");
                }
            }
        }
    }

    /**
     * Inline styl v šabloně stylopis nepřebije (mPDF `!important` ignoruje). Plochy
     * a barevné texty proto patří do tříd, inline smí zůstat jen neutrální šedá.
     */
    public function testTemplatesCarryNoInlineSurfaceOrColour(): void
    {
        foreach (['invoice.twig', 'work_report.twig'] as $file) {
            $tpl = (string) file_get_contents($this->rootDir . '/api/templates/invoice/' . $file);
            preg_match_all('/style="([^"]*)"/', $tpl, $m);
            foreach ($m[1] as $style) {
                self::assertDoesNotMatchRegularExpression('/(^|;)\s*background/i', $style, "{$file}: inline pozadí `{$style}`");
                self::assertFalse(self::hasTintedHex($style, 0x20), "{$file}: inline barva `{$style}`");
            }
        }
    }

    private function baseCss(): string
    {
        return (string) file_get_contents($this->rootDir . '/styles/invoice.css');
    }

    private function monoCss(): string
    {
        $path = $this->rootDir . '/styles/invoice-mono.css';
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * Jednoduchý parser pro ploché stylopisy PDF (bez @media): selektor → vlastnosti,
     * skupiny selektorů rozdělené, pozdější deklarace přepisují dřívější.
     *
     * @return array<string,array<string,string>>
     */
    private static function parseCss(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $out = [];
        foreach ($rules as [, $selectors, $body]) {
            $decls = [];
            foreach (explode(';', $body) as $decl) {
                if (!str_contains($decl, ':')) continue;
                [$prop, $value] = array_map('trim', explode(':', $decl, 2));
                $decls[strtolower($prop)] = $value;
            }
            foreach (explode(',', $selectors) as $selector) {
                $selector = (string) preg_replace('/\s+/', ' ', trim($selector));
                if ($selector === '') continue;
                $out[$selector] = array_merge($out[$selector] ?? [], $decls);
            }
        }
        return $out;
    }

    private static function isWhite(string $value): bool
    {
        $v = strtolower(trim($value));
        return in_array($v, ['#fff', '#ffffff', 'none', 'transparent', 'white'], true);
    }

    private static function isDarkNeutral(string $value): bool
    {
        $rgb = self::rgb(trim($value));
        return $rgb !== null && max($rgb) - min($rgb) === 0 && max($rgb) <= 0x33;
    }

    /** Obsahuje hodnota hex barvu, jejíž kanály se liší víc než o $tolerance? */
    private static function hasTintedHex(string $value, int $tolerance): bool
    {
        preg_match_all('/#[0-9a-f]{3}(?:[0-9a-f]{3})?\b/i', $value, $m);
        foreach ($m[0] as $hex) {
            $rgb = self::rgb($hex);
            if ($rgb !== null && max($rgb) - min($rgb) > $tolerance) return true;
        }
        return false;
    }

    /** @return list<int>|null */
    private static function rgb(string $hex): ?array
    {
        if (preg_match('/^#([0-9a-f]{3})$/i', $hex, $m)) {
            $hex = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) return null;
        return [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
    }

    /**
     * Syntetický doklad: snapshot dodavatele je vyplněný, takže render nesahá na
     * živého dodavatele (supplier_id 0) a příznaky dodavatele lze nastavit tady.
     *
     * @param array<string,mixed> $supplier
     * @return array<string,mixed>
     */
    private function invoice(array $supplier = []): array
    {
        return [
            'id'                  => 0,
            'invoice_type'        => 'invoice',
            'status'              => 'issued',
            'language'            => 'cs',
            'currency'            => 'CZK',
            'currency_id'         => 0,
            'client_id'           => 0,
            'supplier_id'         => 0,
            'varsymbol'           => 'MONO0001',
            'issue_date'          => '2026-07-01',
            'tax_date'            => '2026-07-01',
            'due_date'            => '2026-07-15',
            'paid_at'             => null,
            'amount_to_pay'       => 121.0,
            'paid_total'          => 0.0,
            'parent_invoice_id'   => null,
            'payment_method'      => 'bank_transfer',
            'prices_include_vat'  => false,
            'reverse_charge'      => false,
            'branding_profile_id' => null,
            'advance_paid_amount' => 0.0,
            'czk_recap'           => null,
            'supplier_snapshot'   => json_encode($supplier + [
                'company_name' => 'Testovací dodavatel',
                'street'       => 'Zkušební 1',
                'city'         => 'Praha',
                'zip'          => '11000',
                'is_vat_payer' => true,
            ], JSON_UNESCAPED_UNICODE),
            'client_snapshot'     => json_encode([
                'company_name' => 'Testovací odběratel',
                'street'       => 'Zkušební 2',
                'city'         => 'Brno',
                'zip'          => '60200',
                'country_iso2' => 'CZ',
            ], JSON_UNESCAPED_UNICODE),
            'bank_snapshot'       => null,
            'items'               => [[
                'description'            => 'Testovací plnění',
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 100.0,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 100.0,
                'total_vat'              => 21.0,
                'total_with_vat'         => 121.0,
                'item_kind'              => 'standard',
                'oss_applicable'         => false,
                'oss_consumer_country'   => null,
            ]],
            'totals'              => ['without_vat' => 100.0, 'vat' => 21.0, 'with_vat' => 121.0],
            'vat_breakdown'       => [],
        ];
    }
}
