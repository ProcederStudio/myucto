<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Featura G (private/REAL_data_followup_UX.md) — detekce kolize variabilních symbolů
 * napříč číselnými řadami.
 *
 * Proč: `invoices.varsymbol` neslouží jen jako číslo dokladu, ale i jako VS pro
 * bankovní párování (StatementMatcher) — porovnává se PO NORMALIZACI na číslice
 * (VariableSymbolNormalizer::forMatching() / SQL `CAST(REGEXP_REPLACE(...) AS UNSIGNED)`),
 * takže nečíselné znaky šablony (písmena, pomlčky, lomítka) ZE SROVNÁNÍ ZMIZÍ. Dvě
 * šablony různých typů dokladů (faktura/proforma/dobropis), lišící se jen v takových
 * znacích, tak pro stejné datum+počítadlo vygenerují STEJNÝ VS — reálný incident
 * v produkci (proforma `Z2406002` × faktura `2406002`, viz REAL_data_followup_UX.md §G).
 * Až vznikne, nedá se opravit — klient už platí pod cizím VS.
 *
 * Read-only — nic neopravuje/nemění, jen hlásí. Surfacing: nastavení dodavatele
 * (Systém → Dodavatelé → Číslování faktur), NE monthly-check — kolize řad je
 * setup-level (vzniká při ZAKLÁDÁNÍ/EDITACI šablony, ne periodicky jako uzávěrkové
 * kontroly v {@see \MyInvoice\Service\Accounting\Closing\ClosingService::buildChecks()}).
 *
 * Srovnáváme jen invoice/proforma/credit_note — to je jediná trojice, která sdílí
 * VS-namespace tabulky `invoices` použitý při párování PŘÍCHOZÍCH plateb
 * (viz {@see \MyInvoice\Service\Bank\StatementMatcher}). Přijaté faktury
 * (`purchase_invoice_number_format`) mají VLASTNÍ tabulku (`purchase_invoices`)
 * i vlastní unique constraint — s vydanými doklady nekolidují.
 */
final class VarsymbolSeriesCollisionChecker
{
    /** Typy dokladů sdílející VS-namespace tabulky `invoices` (StatementMatcher). */
    public const SHARED_TYPES = ['invoice', 'proforma', 'credit_note'];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * Kolize mezi supplier-wide šablonami a všemi specifičtějšími přepsáními daného
     * dodavatele — per-client (clients.{type}_number_format, viz ClientForm.vue)
     * i per-kategorii tržby (revenue_categories.{type}_number_format, viz Codebooks.vue).
     *
     * @return list<array{
     *   a: array{type:string, client_id:?int, client_name:?string, revenue_category_id:?int, revenue_category_name:?string, template:string},
     *   b: array{type:string, client_id:?int, client_name:?string, revenue_category_id:?int, revenue_category_name:?string, template:string},
     * }>
     */
    public function findForSupplier(int $supplierId): array
    {
        return self::findCollisions($this->collectSeries($supplierId));
    }

    /**
     * @return list<array{type:string, client_id:?int, client_name:?string, revenue_category_id:?int, revenue_category_name:?string, template:string}>
     */
    private function collectSeries(int $supplierId): array
    {
        $simplifiedColumn = $this->db->hasColumn('supplier', 'simplified_number_format')
            ? 'simplified_number_format' : 'NULL';
        $stmt = $this->db->pdo()->prepare(
            "SELECT invoice_number_format, proforma_number_format, credit_note_number_format,
                    {$simplifiedColumn} AS simplified_number_format
               FROM supplier WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $sup = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $series = [];
        foreach (self::SHARED_TYPES as $type) {
            $tpl = trim((string) ($sup["{$type}_number_format"] ?? ''));
            if ($tpl === '') {
                // Supplier nemá vlastní šablonu — dosadí se globální cfg fallback (stejná
                // priorita jako ve VarsymbolGenerator::resolveTemplateAndPeriod()).
                $tpl = trim((string) $this->config->get("varsymbol.templates.{$type}", ''));
            }
            if ($tpl !== '') {
                $series[] = self::side($type, $tpl);
            }
        }

        // Vlastní řada zjednodušených daňových dokladů (migrace 2037) je jen na úrovni
        // firmy a bez šablony žádná není (doklady jdou do řady faktur). Její čísla jsou
        // VS v téže tabulce `invoices`, takže se s ostatními řadami porovnává taky.
        $simplifiedTpl = trim((string) ($sup['simplified_number_format'] ?? ''));
        if ($simplifiedTpl !== '') {
            $series[] = self::side(VarsymbolGenerator::SIMPLIFIED, $simplifiedTpl);
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT id, company_name, invoice_number_format, proforma_number_format, credit_note_number_format
                 FROM clients
                WHERE supplier_id = ?
                  AND (COALESCE(invoice_number_format, '') <> ''
                    OR COALESCE(proforma_number_format, '') <> ''
                    OR COALESCE(credit_note_number_format, '') <> '')"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cli) {
            foreach (self::SHARED_TYPES as $type) {
                $tpl = trim((string) ($cli["{$type}_number_format"] ?? ''));
                if ($tpl !== '') {
                    $series[] = self::side($type, $tpl, clientId: (int) $cli['id'], clientName: (string) $cli['company_name']);
                }
            }
        }

        // Řady kategorií tržeb (migrace 1333). Sdílejí týž VS-namespace tabulky
        // `invoices` jako supplier-wide a per-client řady, takže musí do stejného
        // porovnání — jinak by tenhle nový způsob nastavení řady obcházel jedinou
        // kontrolu, která reálnému incidentu s dvojím VS předchází.
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, label, invoice_number_format, proforma_number_format, credit_note_number_format
                 FROM revenue_categories
                WHERE supplier_id = ?
                  AND (COALESCE(invoice_number_format, '') <> ''
                    OR COALESCE(proforma_number_format, '') <> ''
                    OR COALESCE(credit_note_number_format, '') <> '')"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cat) {
            foreach (self::SHARED_TYPES as $type) {
                $tpl = trim((string) ($cat["{$type}_number_format"] ?? ''));
                if ($tpl !== '') {
                    $series[] = self::side($type, $tpl, categoryId: (int) $cat['id'], categoryName: (string) $cat['label']);
                }
            }
        }

        return $series;
    }

    /**
     * @return array{type:string, client_id:?int, client_name:?string, revenue_category_id:?int, revenue_category_name:?string, template:string}
     */
    private static function side(
        string $type,
        string $template,
        ?int $clientId = null,
        ?string $clientName = null,
        ?int $categoryId = null,
        ?string $categoryName = null,
    ): array {
        return [
            'type' => $type,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'revenue_category_id' => $categoryId,
            'revenue_category_name' => $categoryName,
            'template' => $template,
        ];
    }

    /**
     * Najde všechny kolidující dvojice v dané sadě řad (pure — bez DB, unit-testovatelné).
     *
     * @param list<array{type:string, template:string, ...}> $series
     * @return list<array{a: array{type:string, template:string, ...}, b: array{type:string, template:string, ...}}>
     */
    public static function findCollisions(array $series): array
    {
        $collisions = [];
        $n = count($series);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($series[$i]['template'] === '' || $series[$j]['template'] === '') {
                    continue;
                }
                if (self::templatesCollide($series[$i]['template'], $series[$j]['template'])) {
                    $collisions[] = ['a' => $series[$i], 'b' => $series[$j]];
                }
            }
        }
        return $collisions;
    }

    /**
     * Dvě šablony kolidují, pokud po zahození nečíselných literálů — přesně to, co dělá
     * bankovní matcher při párování VS — vyprodukují STEJNOU strukturu číslic: pro
     * shodné datum a počítadlo tak vygenerují IDENTICKÝ výsledný VS.
     */
    public static function templatesCollide(string $a, string $b): bool
    {
        $ta = trim($a);
        $tb = trim($b);
        if ($ta === '' || $tb === '') {
            return false;
        }
        if ($ta === $tb) {
            return true;
        }
        return self::digitSkeleton($ta) === self::digitSkeleton($tb);
    }

    /**
     * "Digitální skeleton" šablony — jen to, co v renderovaném VS přežije normalizaci na
     * číslice (viz {@see \MyInvoice\Service\Bank\VariableSymbolNormalizer::digits()}).
     * Placeholdery: `{YYYY}` → `Y4`, `{YY}` → `Y2`, `{MM}` → `M2` (posun je součástí tokenu,
     * `{YY+30}` → `Y2+30` — stejná šířka, ale nikdy stejná hodnota), `{C+}` (n znaků) → `Cn`,
     * `{PP}` (přijaté faktury — vždy 2 písmena PF/PN/KU/KN/NU/NN) → zahozeno. Literální
     * znaky: číslice zůstávají (jako `L<digits>` segment ve svém pořadí), cokoliv jiného
     * (písmena, pomlčky, lomítka, mezery) se zahodí — přesně jako `\D` v normalizeru.
     *
     * Omezení (vědomý kompromis): různě široká počítadla (`{CCC}` vs `{CCCC}`) NEflagujeme,
     * i když `CAST(...AS UNSIGNED)` teoreticky ořízne vodicí nuly na začátku CELÉHO VS a
     * mohly by se pro malé hodnoty counteru shodovat — jde o okrajový a nepravděpodobný
     * překryv (jiná struktura, jiná řada), ne skutečnou kolizi. Cílem je chytit reálný
     * vzor incidentu (stejná struktura, jen jiné/chybějící oddělovací znaky), ne každý
     * teoreticky možný numerický průnik — Featura G výslovně žádá "flagni jen skutečné
     * kolize, ne řady s disjunktními prefixy".
     */
    public static function digitSkeleton(string $template): string
    {
        $parts = preg_split(
            '/(\{(?:YYYY|YY|MM)(?:[+-]\d{1,3})?\}|\{C+\}|\{PP\})/',
            $template,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [$template];

        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // Posun je součástí identity tokenu: {YY} a {YY+30} mají stejnou ŠÍŘKU, ale
            // nikdy stejnou HODNOTU, takže se o kolizi nejedná a nesmí se sloučit.
            if (preg_match('/^\{(YYYY|YY|MM)([+-]\d{1,3})?\}$/', $part, $m) === 1) {
                $offset = (int) ($m[2] ?? 0);
                $tokens[] = ($m[1] === 'YYYY' ? 'Y4' : ($m[1] === 'YY' ? 'Y2' : 'M2'))
                    . ($offset !== 0 ? sprintf('%+d', $offset) : '');
                continue;
            }
            if ($part === '{PP}') {
                continue; // vždy písmena — normalizace na číslice je odstraní
            }
            if (preg_match('/^\{(C+)\}$/', $part, $m) === 1) {
                $tokens[] = 'C' . strlen($m[1]);
                continue;
            }
            $digits = preg_replace('/\D+/', '', $part) ?? '';
            if ($digits !== '') {
                $tokens[] = 'L' . $digits;
            }
        }

        return implode('|', $tokens);
    }
}
