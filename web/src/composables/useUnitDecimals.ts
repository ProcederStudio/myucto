import { computed, unref, type MaybeRef } from 'vue'

/**
 * Desetinná místa množství podle měrné jednotky (migrace 2036).
 *
 * Číselník jednotek je globální, jednotka na řádku dokladu i na skladové kartě je
 * volný text. Párují se podle kódu bez ohledu na velikost písmen a okolní mezery,
 * stejně jako na serveru (`UnitPrecision`). Jednotka bez nastavení a jednotka mimo
 * číselník nejsou omezené, takže bez nastavení se v editorech nic nemění.
 *
 * Nic se nezaokrouhluje: editor jen upozorní a uložení zastaví, server stejný
 * vstup odmítne 400.
 */
export interface UnitWithDecimals {
  code: string
  quantity_decimals?: number | null
}

/** Přesnost sloupce množství (DECIMAL(10,3)). */
export const QUANTITY_STORAGE_DECIMALS = 3

export function unitKey(unit: string | null | undefined): string {
  return String(unit ?? '').trim().toLocaleLowerCase()
}

/** Počet platných desetinných míst (koncové nuly se nepočítají), nečíselné → null. */
export function decimalPlaces(value: unknown): number | null {
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) return null
    // Devět míst odřízne šum float sčítání (0.1 + 0.2), skutečná místa nechá.
    const fraction = Math.abs(value).toFixed(9).split('.')[1] ?? ''
    return fraction.replace(/0+$/, '').length
  }
  if (typeof value !== 'string') return null
  const s = value.trim().replace(',', '.')
  const m = /^[+-]?\d*(?:\.(\d*))?$/.exec(s)
  if (!m || s === '' || s === '.' || s === '+' || s === '-') return null
  return (m[1] ?? '').replace(/0+$/, '').length
}

/** Jednotka řádku bez klíče `unit`, stejně jako InvoiceRepository::DEFAULT_ITEM_UNIT. */
export const DEFAULT_ITEM_UNIT = 'ks'

function canonicalQuantity(value: unknown): string | null {
  let s: string
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) return null
    s = value.toFixed(9)
  } else if (typeof value === 'string') {
    s = value.trim().replace(',', '.')
  } else {
    return null
  }
  const m = /^([+-]?)(\d*)(?:\.(\d*))?$/.exec(s)
  if (!m || (m[2] === '' && (m[3] ?? '') === '')) return null
  const int = m[2].replace(/^0+/, '') || '0'
  const frac = (m[3] ?? '').replace(/0+$/, '')
  const abs = frac ? `${int}.${frac}` : int
  return m[1] === '-' && abs !== '0' ? `-${abs}` : abs
}

function signature(row: { quantity?: unknown; unit?: string | null }): string | null {
  const q = canonicalQuantity(row.quantity)
  return q === null ? null : `${unitKey(row.unit ?? DEFAULT_ITEM_UNIT)}|${q}`
}

/**
 * Řádky, které mají stejnou jednotku a množství jako některý uložený řádek dokladu
 * (každý uložený řádek se spáruje nejvýš jednou). Takový řádek vznikl dřív, než
 * jednotka dostala nastavení, a editor ho neblokuje. Stejné pravidlo jako
 * `UnitPrecision::unchangedIndexes()` na serveru.
 */
export function unchangedRows(
  rows: ReadonlyArray<{ quantity?: unknown; unit?: string | null }>,
  stored: ReadonlyArray<{ quantity?: unknown; unit?: string | null }>,
): ReadonlySet<unknown> {
  const pool = stored.map(signature)
  const out = new Set<unknown>()
  for (const row of rows) {
    const sig = signature(row)
    if (sig === null) continue
    const hit = pool.indexOf(sig)
    if (hit >= 0) {
      out.add(row)
      pool[hit] = null
    }
  }
  return out
}

export function useUnitDecimals(units: MaybeRef<UnitWithDecimals[]>) {
  const map = computed(() => {
    const out = new Map<string, number>()
    for (const u of unref(units) ?? []) {
      if (u.quantity_decimals !== null && u.quantity_decimals !== undefined) {
        out.set(unitKey(u.code), u.quantity_decimals)
      }
    }
    return out
  })

  /** Nastavený počet míst jednotky, null = bez omezení. */
  function decimalsFor(unit: string | null | undefined): number | null {
    return map.value.get(unitKey(unit)) ?? null
  }

  /** Má množství víc míst, než jednotka dovoluje? Prázdné a nečíselné ne (to hlídá jiná validace). */
  function tooPrecise(quantity: unknown, unit: string | null | undefined): boolean {
    const allowed = decimalsFor(unit)
    if (allowed === null) return false
    const places = decimalPlaces(quantity)
    return places !== null && places > allowed
  }

  /** Atribut `step` číselného pole množství. Bez nastavení jako dosud 0.001. */
  function stepFor(unit: string | null | undefined): string {
    const d = decimalsFor(unit) ?? QUANTITY_STORAGE_DECIMALS
    return d === 0 ? '1' : (1 / 10 ** d).toFixed(d)
  }

  /**
   * Počet míst pro kalkulačku `v-math` v poli množství. Bez nastavení 2 jako dosud.
   * S nastavením přesnost sloupce: výsledek výrazu se nesmí potichu zaokrouhlit
   * na povolený počet míst, nadbytečná místa ukáže upozornění.
   */
  function mathDecimalsFor(unit: string | null | undefined): number {
    return decimalsFor(unit) === null ? 2 : QUANTITY_STORAGE_DECIMALS
  }

  return { decimalsFor, tooPrecise, stepFor, mathDecimalsFor }
}
