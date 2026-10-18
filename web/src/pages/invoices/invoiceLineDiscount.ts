/**
 * Ceníková cena a sleva řádku faktury (migrace 2035).
 *
 * `list_unit_price` je cena za jednotku před slevou ve stejném základu jako
 * `unit_price_without_vat`. Editor ji ukládá při nacenění skladové karty a ruční
 * změna ceny ji nechá být, procento se jen přepočítá. Rozhodné procento počítá
 * server (`LineDiscount::percent`), tady je jen náhled pro řádek editoru.
 */

function toNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

/** Sleva v % (záporná = přirážka) na tři desetinná místa; null bez ceníku, při shodě a u záporné ceny. */
export function lineDiscountPct(listPrice: number | string | null | undefined, unitPrice: number | string | null | undefined): number | null {
  const list = toNumber(listPrice)
  const unit = toNumber(unitPrice)
  if (list === null || unit === null || list <= 0 || unit < 0) return null
  const raw = (list - unit) * 100 / list
  const pct = Math.sign(raw) * Math.round(Math.abs(raw) * 1000 + 1e-7) / 1000
  return pct === 0 ? null : pct
}

/** Ceníková cena z odpovědi nacenění (`/stock/items/quote`). */
export function quotedListPrice(line: { list_unit_price?: string | number | null }): number | null {
  const list = toNumber(line.list_unit_price)
  return list !== null && list > 0 ? list : null
}

/** Ceníková cena karty vybrané bez nacenění: standardní cena bez akce. */
export function legacyListPrice(si: { sale_price_without_vat: string | null }): number | null {
  return quotedListPrice({ list_unit_price: si.sale_price_without_vat })
}
