/**
 * Řazení a filtry Přehledu firem. Přehled načítá všechny firmy uživatele naráz,
 * takže se řadí a filtruje na klientovi a přepnutí řazení nepočítá karty znovu.
 * Urgence je táž jako ve správě firem (BE SupplierDirectory).
 */
import type { LocationQuery } from 'vue-router'
import type { PortfolioCompany } from '@/api/portfolio'

export type PortfolioSort =
  | 'urgency' | 'name' | 'deadline' | 'unbooked' | 'bank' | 'inbox' | 'overdue' | 'last_invoice' | 'last_activity'

export const PORTFOLIO_SORTS: PortfolioSort[] = [
  'urgency', 'name', 'deadline', 'unbooked', 'bank', 'inbox', 'overdue', 'last_invoice', 'last_activity',
]

export const DEFAULT_DIR: Record<PortfolioSort, 'asc' | 'desc'> = {
  urgency: 'desc', name: 'asc', deadline: 'asc', unbooked: 'desc', bank: 'desc', inbox: 'desc',
  overdue: 'desc', last_invoice: 'desc', last_activity: 'desc',
}

export interface PortfolioListing {
  sort: PortfolioSort
  dir: 'asc' | 'desc'
  q: string
  vat?: 'payer' | 'non_payer'
  mode?: 'double_entry' | 'tax_evidence'
  urgency?: 'with' | 'without'
}

function pick<T extends string>(value: unknown, allowed: readonly T[]): T | undefined {
  return typeof value === 'string' && (allowed as readonly string[]).includes(value) ? value as T : undefined
}

export function listingFromQuery(q: LocationQuery): PortfolioListing {
  const sort = pick(q.sort, PORTFOLIO_SORTS) ?? 'urgency'
  return {
    sort,
    dir: pick(q.dir, ['asc', 'desc'] as const) ?? DEFAULT_DIR[sort],
    q: typeof q.q === 'string' ? q.q : '',
    vat: pick(q.vat, ['payer', 'non_payer'] as const),
    mode: pick(q.mode, ['double_entry', 'tax_evidence'] as const),
    urgency: pick(q.urgency, ['with', 'without'] as const),
  }
}

/** Jen odchylky od výchozího pohledu, ať je sdílený odkaz krátký. */
export function queryFromListing(l: PortfolioListing): Record<string, string> {
  const out: Record<string, string> = {}
  if (l.sort !== 'urgency') out.sort = l.sort
  if (l.dir !== DEFAULT_DIR[l.sort]) out.dir = l.dir
  if (l.q) out.q = l.q
  if (l.vat) out.vat = l.vat
  if (l.mode) out.mode = l.mode
  if (l.urgency) out.urgency = l.urgency
  return out
}

function score(c: PortfolioCompany): number {
  return c.urgency?.score ?? 0
}

function byName(a: PortfolioCompany, b: PortfolioCompany): number {
  return a.company_name.localeCompare(b.company_name, 'cs')
}

/** Prázdná hodnota (null) je vždy na konci bez ohledu na směr. */
function compareNullable<T extends number | string>(a: T | null, b: T | null, sign: number): number {
  if (a === null || b === null) return (a === null ? 1 : 0) - (b === null ? 1 : 0)
  return a < b ? -sign : a > b ? sign : 0
}

const KEYS: Record<Exclude<PortfolioSort, 'name'>, (c: PortfolioCompany) => number | string | null> = {
  urgency: score,
  deadline: c => c.next_deadline?.days ?? null,
  unbooked: c => c.unbooked_documents,
  bank: c => c.unmatched_bank_transactions,
  inbox: c => c.inbox_pending ?? 0,
  overdue: c => c.urgency?.overdue_receivables ?? 0,
  last_invoice: c => c.last_invoice_date,
  last_activity: c => c.last_activity_at,
}

export function applyListing(companies: PortfolioCompany[], l: PortfolioListing): PortfolioCompany[] {
  const needle = l.q.trim().toLocaleLowerCase('cs')
  const rows = companies.filter(c =>
    (!needle || c.company_name.toLocaleLowerCase('cs').includes(needle) || (c.ic ?? '').includes(needle))
    && (!l.vat || c.is_vat_payer === (l.vat === 'payer'))
    && (!l.mode || c.accounting_mode === l.mode)
    && (!l.urgency || (score(c) > 0) === (l.urgency === 'with')))

  const sign = l.dir === 'asc' ? 1 : -1
  return rows.sort((a, b) => {
    if (l.sort === 'name') return sign * byName(a, b)
    const primary = compareNullable(KEYS[l.sort](a), KEYS[l.sort](b), sign)
    if (primary !== 0) return primary
    // Při shodě urgence rozhodne bližší termín, pak název.
    if (l.sort === 'urgency') {
      const deadline = compareNullable(a.next_deadline?.days ?? null, b.next_deadline?.days ?? null, 1)
      if (deadline !== 0) return deadline
    }
    return byName(a, b)
  })
}
