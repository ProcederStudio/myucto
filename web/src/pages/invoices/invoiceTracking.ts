import type { InvoiceItemTrackingAllocation } from '@/api/invoices'

/**
 * Výběr sériových čísel a šarží na řádku faktury. Faktura nese jen identitu kusu
 * a množství v základní jednotce karty (umístění dohledá výdejka).
 *
 * Zdroj nabídky: u prodeje kusy skladem ve skladu řádku, u vratky (dobropis, záporný
 * řádek) kusy, které původní faktura vydala a jiné dobropisy dosud nevrátily
 * (GET /stock/items/{id}/tracking-choices). Vratka bez původní faktury nemá z čeho
 * nabízet, kus se pak zadá ručně.
 */

export type TrackingMode = 'none' | 'lot' | 'serial'

/** Kus ve zdroji nabídky (skladem / k vrácení); množství v základní jednotce. */
export interface TrackingUnit {
  serial_number: string | null
  lot_code: string | null
  expires_on: string | null
  quantity: string
}

export interface TrackingChoice {
  key: string
  serial_number: string | null
  lot_code: string | null
  expires_on: string | null
  /** Dostupné pro tento řádek: zdroj minus výběr na jiných řádcích; 0 = jen vybraný / dřív vybraný kus. */
  available: number
  /** Kolik z kusu vybraly jiné řádky téže karty a skladu. */
  takenElsewhere: number
}

export type SelectionState = 'none' | 'incomplete' | 'complete' | 'over'

const DECIMAL = /^\d{1,11}(\.\d{1,3})?$/

export function trackingKey(a: { serial_number?: string | null; lot_code?: string | null; expires_on?: string | null }): string {
  return a.serial_number ? `serial:${a.serial_number}` : `lot:${a.lot_code ?? ''}|${a.expires_on ?? ''}`
}

function qty(value: string | number): number {
  return Math.round(Number(String(value).replace(',', '.')) * 1000) / 1000
}

/** Množství z pole: čárka i tečka, nejvýš tři desetinná místa (jako backend); jinak null. */
function parseQuantity(value: string): string | null {
  const raw = value.trim().replace(/\s+/g, '').replace(',', '.')
  return DECIMAL.test(raw) ? raw : null
}

/**
 * Nabídka: kusy ze zdroje, vybrané kusy, které ve zdroji nejsou (uložený doklad), a kusy
 * vybrané dřív v této relaci (aby odškrtnutý kus nezmizel). Dostupnost snižuje výběr
 * na jiných řádcích.
 */
export function trackingChoices(
  source: TrackingUnit[],
  selected: InvoiceItemTrackingAllocation[],
  remembered: TrackingUnit[],
  takenElsewhere: Map<string, number>,
): TrackingChoice[] {
  const byKey = new Map<string, TrackingChoice>()
  const add = (u: { serial_number: string | null; lot_code: string | null; expires_on: string | null }, amount: number) => {
    const key = trackingKey(u)
    const choice = byKey.get(key) ?? { key, serial_number: u.serial_number, lot_code: u.lot_code, expires_on: u.expires_on, available: 0, takenElsewhere: 0 }
    choice.available = qty(choice.available + amount)
    byKey.set(key, choice)
  }
  for (const u of source) add(u, Math.max(0, qty(u.quantity)))
  for (const u of [...selected, ...remembered]) add(u, 0)
  for (const choice of byKey.values()) {
    const taken = takenElsewhere.get(choice.key) ?? 0
    choice.takenElsewhere = taken
    choice.available = Math.max(0, qty(choice.available - taken))
  }
  return [...byKey.values()]
}

/** Množství po kusech, které vybraly jiné řádky téže karty a skladu. */
export function takenByOtherRows(
  rows: Array<{ stock_item_id?: number | null; warehouse: number | null; tracking_allocations?: InvoiceItemTrackingAllocation[] }>,
  rowIndex: number,
  stockItemId: number,
  warehouse: number | null,
): Map<string, number> {
  const out = new Map<string, number>()
  rows.forEach((row, i) => {
    if (i === rowIndex || row.stock_item_id !== stockItemId || row.warehouse !== warehouse) return
    for (const a of row.tracking_allocations ?? []) {
      const key = trackingKey(a)
      out.set(key, qty((out.get(key) ?? 0) + qty(a.quantity)))
    }
  })
  return out
}

export function isSelected(selected: InvoiceItemTrackingAllocation[], choice: TrackingChoice): boolean {
  return selected.some(s => trackingKey(s) === choice.key)
}

export function selectedQuantity(selected: InvoiceItemTrackingAllocation[], choice: TrackingChoice): string {
  return selected.find(s => trackingKey(s) === choice.key)?.quantity ?? ''
}

/** Vybrané množství kusu je vyšší, než je pro řádek k dispozici. */
export function exceedsAvailable(selected: InvoiceItemTrackingAllocation[], choice: TrackingChoice): boolean {
  const picked = selected.filter(s => trackingKey(s) === choice.key).reduce((sum, s) => sum + qty(s.quantity), 0)
  return picked > 0 && qty(picked) > choice.available
}

/** Některý vybraný kus má víc, než je k dispozici. */
export function anyExceedsAvailable(selected: InvoiceItemTrackingAllocation[], choices: TrackingChoice[]): boolean {
  return choices.some(c => exceedsAvailable(selected, c))
}

/** Sériové číslo: zaškrtnutí přidá kus (množství 1), další zaškrtnutí ho odebere. */
export function toggleSerial(selected: InvoiceItemTrackingAllocation[], choice: TrackingChoice): InvoiceItemTrackingAllocation[] {
  if (isSelected(selected, choice)) return selected.filter(s => trackingKey(s) !== choice.key)
  return [...selected, { quantity: '1.000', serial_number: choice.serial_number, lot_code: null, expires_on: null }]
}

/** Šarže: množství z pole; prázdné nebo nula šarži odebere, neplatná hodnota vrátí null (pole se vrátí). */
export function setLotQuantity(selected: InvoiceItemTrackingAllocation[], choice: TrackingChoice, value: string): InvoiceItemTrackingAllocation[] | null {
  const rest = selected.filter(s => trackingKey(s) !== choice.key)
  if (value.trim() === '') return rest
  const parsed = parseQuantity(value)
  if (parsed === null) return null
  if (!(Number(parsed) > 0)) return rest
  return [...rest, { quantity: parsed, serial_number: null, lot_code: choice.lot_code, expires_on: choice.expires_on }]
}

/** Stav proti množství řádku v základní jednotce (dobropis má množství záporné). */
export function selectionState(selected: InvoiceItemTrackingAllocation[], requiredBase: number): SelectionState {
  if (selected.length === 0) return 'none'
  const total = selectedTotal(selected)
  const required = qty(Math.abs(requiredBase))
  if (total === required) return 'complete'
  return total < required ? 'incomplete' : 'over'
}

export function selectedTotal(selected: InvoiceItemTrackingAllocation[]): number {
  return qty(selected.reduce((sum, s) => sum + qty(s.quantity), 0))
}

/** Vratka: dobropis nebo záporný řádek běžné faktury. */
export function isReturnRow(invoiceType: string, baseQuantity: number): boolean {
  return invoiceType === 'credit_note' || baseQuantity < 0
}

/** Ruční zadání kusu u vratky bez původní faktury; neplatné → null. */
export function freeTextAllocation(mode: 'lot' | 'serial', identity: string, quantity: string): InvoiceItemTrackingAllocation | null {
  const id = identity.trim()
  if (id === '') return null
  if (mode === 'serial') return { quantity: '1.000', serial_number: id, lot_code: null, expires_on: null }
  const parsed = parseQuantity(quantity)
  if (parsed === null || !(Number(parsed) > 0)) return null
  return { quantity: parsed, serial_number: null, lot_code: id, expires_on: null }
}

/**
 * Karta nalezená podle celého sériového čísla (VIN) si ho rovnou vybere. Hledání
 * podle části čísla vrací první shodu bez ohledu na zásobu, proto jen při úplné shodě.
 */
export function preselectFromSearch(
  mode: TrackingMode,
  match: { kind: string; value: string } | null | undefined,
  query: string,
): InvoiceItemTrackingAllocation[] {
  if (mode !== 'serial' || match?.kind !== 'serial' || !match.value) return []
  if (match.value.trim().toLowerCase() !== query.trim().toLowerCase()) return []
  return [{ quantity: '1.000', serial_number: match.value, lot_code: null, expires_on: null }]
}
