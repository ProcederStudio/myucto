<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Ceníková cena a sleva řádku vydané faktury (migrace 2035).
 *
 * `list_unit_price` je cena za jednotku před slevou ve stejném základu jako
 * `unit_price_without_vat` (v režimu „ceny s DPH" tedy brutto). Sleva v % se
 * z obou cen dopočítá VŽDY tady, klientem poslané procento se ignoruje, aby se
 * údaj na dokladu nemohl rozejít s cenou řádku.
 *
 * Je to informační údaj. Částky dokladu dál počítá {@see InvoiceMath} jen
 * z `unit_price_without_vat`, takže ceníková cena nemění základ, DPH ani součty.
 * Hlavičková sleva dokladu (`discount_percent`) je samostatný slevový řádek.
 *
 * Volají ho všechny cesty, které řádek zapisují: {@see \MyInvoice\Repository\InvoiceRepository::replaceItems()},
 * dobropis ({@see \MyInvoice\Action\Invoice\CancelInvoiceAction}), kopie faktury
 * ({@see \MyInvoice\Action\Invoice\BulkReissueAction}) a konečná faktura ze zálohy
 * ({@see FinalFromProformaCreator}).
 */
final class LineDiscount
{
    public const LIST_COLUMN = 'list_unit_price';
    public const PERCENT_COLUMN = 'line_discount_pct';

    private const PRICE_SCALE = 6;
    private const PERCENT_SCALE = 3;
    /** DECIMAL(16,6) má deset celých míst. */
    private const MAX_PRICE = '9999999999.999999';
    /** DECIMAL(7,3) má čtyři celá místa. */
    private const MAX_PERCENT = '9999.999';

    /** Ceníková cena k uložení: kladné číslo na šest desetinných míst, jinak null. */
    public static function listPrice(mixed $value): ?string
    {
        $price = self::decimal($value);
        if ($price === null || bccomp($price, '0', self::PRICE_SCALE) <= 0 || bccomp($price, self::MAX_PRICE, self::PRICE_SCALE) > 0) {
            return null;
        }
        return $price;
    }

    /**
     * Sleva v % = (1 − cena / ceníková cena) · 100 na tři desetinná místa (půl nahoru),
     * záporná = přirážka. Null bez ceníkové ceny, při shodě, u záporné ceny řádku
     * (odpočet zálohy, ruční sleva) a mimo rozsah sloupce.
     */
    public static function percent(mixed $listPrice, mixed $unitPrice): ?string
    {
        $list = self::listPrice($listPrice);
        $unit = self::decimal($unitPrice);
        if ($list === null || $unit === null || bccomp($unit, '0', self::PRICE_SCALE) < 0) {
            return null;
        }
        $raw = bcdiv(bcmul(bcsub($list, $unit, self::PRICE_SCALE), '100', self::PRICE_SCALE), $list, 10);
        $half = (str_starts_with($raw, '-') ? '-' : '') . '0.0005';
        $pct = bcadd($raw, $half, self::PERCENT_SCALE);
        if (bccomp($pct, '0', self::PERCENT_SCALE) === 0
            || bccomp(ltrim($pct, '-'), self::MAX_PERCENT, self::PERCENT_SCALE) > 0) {
            return null;
        }
        return $pct;
    }

    /**
     * Hodnoty sloupců `list_unit_price`, `line_discount_pct` pro zápis řádku.
     *
     * @param array<string,mixed> $item řádek z požadavku nebo uložený řádek (castItem)
     * @return array{0:?string,1:?string}
     */
    public static function columns(array $item): array
    {
        $list = self::listPrice($item[self::LIST_COLUMN] ?? null);
        return [$list, self::percent($list, $item['unit_price_without_vat'] ?? null)];
    }

    private static function decimal(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $float = (float) $value;
        if (!is_finite($float) || abs($float) >= 1e12) {
            return null;
        }
        return number_format($float, self::PRICE_SCALE, '.', '');
    }
}
