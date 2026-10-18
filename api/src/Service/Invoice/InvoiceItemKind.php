<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Druh řádku vydaného dokladu (`invoice_items.item_kind`, migrace 0053 a 2034).
 *
 *   standard  běžná položka (plnění)
 *   discount  systémová sleva z hlavičky, generuje ji `InvoiceRepository::replaceItems()`
 *   text      textový řádek mezi položkami: jen popis, žádná částka
 *
 * ── Textový řádek ──────────────────────────────────────────────────────────────────
 * Nese jen `description` a `vat_rate_id` (sloupec je NOT NULL a řada dotazů na sazbu
 * dělá INNER JOIN). Množství, ceny a totály jsou nula; sklad, majetek, OSS, klasifikace
 * DPH, výnosový účet ani časové rozlišení nemá. Do součtů, rekapitulace DPH, evidence
 * DPH, kontrolního hlášení, OSS, zaúčtování ani skladu NEVSTUPUJE. Místa, která počítají
 * s tím, že každý řádek nese částku, ho vyřazují přes {@see amountLines()} nebo
 * {@see sqlAmountLine()}; jinak by nulový řádek založil prázdný řádek rekapitulace,
 * nulový řádek deníku nebo nulovou větu výkazu.
 *
 * Pravidlo žije tady, aby šlo ZAVOLAT (viz AGENTS.md, SSOT musí jít zavolat).
 */
final class InvoiceItemKind
{
    public const STANDARD = 'standard';
    public const DISCOUNT = 'discount';
    public const TEXT = 'text';

    /** Druh řádku; cokoli neznámého je běžná položka (tak se chová i DB default). */
    public static function of(mixed $item): string
    {
        $kind = is_array($item) ? ($item['item_kind'] ?? null) : null;
        return $kind === self::DISCOUNT || $kind === self::TEXT ? $kind : self::STANDARD;
    }

    public static function isText(mixed $item): bool
    {
        return is_array($item) && self::of($item) === self::TEXT;
    }

    /**
     * Textový řádek v kanonickém tvaru: ponechá popis a sazbu, všechno ostatní vynuluje.
     * Ostatní druhy vrátí beze změny. Volá se na vstupu (API, editor, MCP) i při zápisu,
     * takže nulový řádek nedostane OSS, sklad ani klasifikaci ani přes skript.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function normalize(array $item): array
    {
        if (self::of($item) !== self::TEXT) {
            return $item;
        }
        return array_merge($item, [
            'item_kind'               => self::TEXT,
            'description'             => (string) ($item['description'] ?? ''),
            'quantity'                => 0.0,
            'duration_minutes'        => null,
            'unit'                    => '',
            'unit_price_without_vat'  => 0.0,
            'vat_classification_code' => null,
            'stock_item_id'           => null,
            'warehouse_id'            => null,
            'tracking_allocations'    => [],
            'small_asset_id'          => null,
            'asset_id'                => null,
            'accrual_from'            => null,
            'accrual_to'              => null,
            'revenue_account_code'    => null,
            'oss_applicable'          => false,
            'oss_consumer_country'    => null,
            'oss_rate_type'           => null,
            'oss_supply_type'         => null,
            'oss_exchange_rate'       => null,
            'oss_exchange_rate_date'  => null,
            'oss_taxable_amount_return' => null,
            'oss_vat_amount_return'   => null,
            'oss_original_period'     => null,
            'oss_needs_manual_review' => false,
            'oss_document_contradiction' => false,
        ]);
    }

    /**
     * @param array<int|string,mixed> $items
     * @return array<int|string,mixed>
     */
    public static function normalizeItems(array $items): array
    {
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $items[$key] = self::normalize($item);
            }
        }
        return $items;
    }

    /**
     * Řádky, které nesou částku (vše kromě textových), v původním pořadí a přečíslované.
     *
     * @param array<int|string,mixed> $items
     * @return list<array<string,mixed>>
     */
    public static function amountLines(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item) && self::of($item) !== self::TEXT,
        ));
    }

    /** Má doklad aspoň jeden řádek s částkou? Doklad jen z textových řádků se nevystaví. */
    public static function hasAmountLine(array $items): bool
    {
        return self::amountLines($items) !== [];
    }

    /**
     * Sazba textového řádku: sloupec je NOT NULL a dotazy na sazbu dělají INNER JOIN,
     * takže sazbu nést musí, i když nic nevykazuje. Bere se VŽDY sazba první položky
     * dokladu (poslaná sazba se ignoruje), aby textový řádek nezaložil vlastní skupinu
     * ani v dotazech, které seskupují podle sazby a text nevyřazují. Doklad jen
     * z textových řádků dostane výchozí tuzemskou sazbu číselníku.
     *
     * @param array<int|string,mixed> $items
     * @param array<int,mixed>|null   $knownRates id platných sazeb (klíče), NULL = neověřovat
     */
    public static function textRowRateId(array $items, \PDO $pdo, ?array $knownRates = null): int
    {
        // Bez textového řádku se nic nedohledává (žádný dotaz navíc pro běžné doklady).
        if (array_filter($items, self::isText(...)) === []) {
            return 0;
        }
        foreach ($items as $item) {
            $id = is_array($item) ? (int) ($item['vat_rate_id'] ?? 0) : 0;
            if ($id > 0 && !self::isText($item) && ($knownRates === null || isset($knownRates[$id]))) {
                return $id;
            }
        }
        return (int) ($pdo->query(
            'SELECT id FROM vat_rates WHERE is_reverse_charge = 0
              ORDER BY is_default DESC, valid_to IS NULL DESC, id LIMIT 1'
        )->fetchColumn() ?: 0);
    }

    /** SQL podmínka „řádek nese částku" pro dotazy nad `invoice_items`. */
    public static function sqlAmountLine(string $alias = ''): string
    {
        return ($alias !== '' ? $alias . '.' : '') . "item_kind <> '" . self::TEXT . "'";
    }
}
