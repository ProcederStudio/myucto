/**
 * Sleva a přirážka cenové hladiny. API nese jedno číslo se znaménkem
 * (`discount_pct`: kladné = sleva, záporné = přirážka, rozsah −999,999 až 100,
 * viz StockPriceLevelService::adjustmentPct). Formuláře místo záporného čísla
 * nabízí výběr Sleva / Přirážka a kladnou hodnotu: klávesnice `inputmode=decimal`
 * na telefonu minus nemá.
 */

export type AdjustmentKind = 'discount' | 'markup'

export const MAX_DISCOUNT_PCT = 100
export const MAX_MARKUP_PCT = 999.999

const DECIMAL = /^\d{1,10}(\.\d{1,3})?$/

/** Mezery pryč, čárka a typografické minus na tečku a pomlčku. */
export function normalizePct(value: string | number | null | undefined): string {
  return String(value ?? '').trim().replace(/\s+/g, '').replace(',', '.').replace('−', '-')
}

/** Uložená hodnota se znaménkem → druh a kladná hodnota pro formulář. */
export function splitAdjustment(pct: string | number | null | undefined): { kind: AdjustmentKind; value: string } {
  const raw = normalizePct(pct)
  return raw.startsWith('-') ? { kind: 'markup', value: raw.slice(1) } : { kind: 'discount', value: raw }
}

/** Druh a kladná hodnota z formuláře → hodnota pro API. Prázdná zůstane prázdná, nula bez znaménka. */
export function joinAdjustment(kind: AdjustmentKind, value: string): string {
  const raw = normalizePct(value)
  if (raw === '' || kind === 'discount' || Number(raw) === 0) return raw
  return '-' + raw
}

/** Neplatná kladná hodnota formuláře pro daný druh. */
export function invalidAdjustment(kind: AdjustmentKind, value: string): boolean {
  const raw = normalizePct(value)
  if (!DECIMAL.test(raw)) return true
  return Number(raw) > (kind === 'markup' ? MAX_MARKUP_PCT : MAX_DISCOUNT_PCT)
}

/** Neplatná hodnota se znaménkem (pravidla zadaná přímo číslem). */
export function invalidSignedPct(value: string | number | null | undefined): boolean {
  const { kind, value: abs } = splitAdjustment(value)
  return invalidAdjustment(kind, abs)
}

/** „+45 %“ pro přirážku, „−10 %“ pro slevu; `format` dodá číslo v jazyce uživatele. */
export function formatAdjustment(pct: string | number | null | undefined, format: (n: number) => string): string {
  const raw = normalizePct(pct)
  const n = Number(raw)
  if (raw === '' || !Number.isFinite(n)) return '—'
  if (n === 0) return `${format(0)} %`
  return `${n < 0 ? '+' : '−'}${format(Math.abs(n))} %`
}
