export type VatReportPeriod = 'monthly' | 'quarterly'
export interface VatReportSelection {
  year: number
  month: number
  periodOverride: VatReportPeriod | ''
}

const PREFIX = 'myinvoice.vat-report-period.'

function validSelection(value: unknown): value is VatReportSelection {
  if (!value || typeof value !== 'object') return false
  const selection = value as VatReportSelection
  return Number.isInteger(selection.year) && selection.year >= 2000 && selection.year <= 2199
    && Number.isInteger(selection.month) && selection.month >= 1 && selection.month <= 12
    && ['', 'monthly', 'quarterly'].includes(selection.periodOverride)
}

export function readVatReportSelection(scope: string, group: string): VatReportSelection | null {
  if (!scope) return null
  try {
    const value: unknown = JSON.parse(sessionStorage.getItem(`${PREFIX}${scope}.${group}`) ?? 'null')
    return validSelection(value) ? value : null
  } catch {
    return null
  }
}

export function writeVatReportSelection(scope: string, group: string, selection: VatReportSelection): void {
  if (!scope || !validSelection(selection)) return
  try {
    sessionStorage.setItem(`${PREFIX}${scope}.${group}`, JSON.stringify(selection))
  } catch {
    // Blokované úložiště nesmí blokovat samotný výkaz.
  }
}

/** Odhlášení ukončí i zapamatovanou volbu období, ostatní data relace ponechá. */
export function clearVatReportPeriodSession(): void {
  try {
    for (let i = sessionStorage.length - 1; i >= 0; i--) {
      const key = sessionStorage.key(i)
      if (key?.startsWith(PREFIX)) sessionStorage.removeItem(key)
    }
  } catch {
    // Úložiště nemusí být dostupné ani při odhlášení.
  }
}
