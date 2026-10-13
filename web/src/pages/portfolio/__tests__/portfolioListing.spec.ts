import { describe, expect, it } from 'vitest'
import type { PortfolioCompany } from '@/api/portfolio'
import { applyListing, listingFromQuery, queryFromListing } from '../portfolioListing'

function company(id: number, name: string, over: Partial<PortfolioCompany> = {}): PortfolioCompany {
  return {
    supplier_id: id,
    company_name: name,
    ic: String(10000000 + id),
    is_vat_payer: true,
    accounting_mode: 'double_entry',
    next_deadline: null,
    unbooked_documents: 0,
    unbooked_breakdown: [],
    unmatched_bank_transactions: 0,
    purchase_drafts: 0,
    inbox_pending: 0,
    period_status: null,
    last_bank_import_at: null,
    volume: { issued_invoices: 0, purchase_invoices: 0, bank_statements: 0, bank_transactions: 0, cash_documents: 0, journal_entries: 0 },
    urgency: null,
    last_invoice_date: null,
    last_activity_at: null,
    ...over,
  }
}

function urgency(score: number, overdue = 0): PortfolioCompany['urgency'] {
  return {
    score, level: score >= 50 ? 'high' : score > 0 ? 'low' : 'none', vat: null,
    overdue_receivables: overdue, overdue_payables: 0, unmatched_bank_transactions: 0, purchase_drafts: 0,
  }
}

const deadline = (days: number) => ({ label: 'DPH', date: '2026-10-25', days, shv_pending: false })

const names = (rows: PortfolioCompany[]) => rows.map(c => c.company_name)

describe('portfolioListing', () => {
  const rows = [
    company(1, 'Alfa', { urgency: urgency(0), next_deadline: deadline(10) }),
    company(2, 'Beta', { urgency: urgency(100), unbooked_documents: 3 }),
    company(3, 'Cyril', { urgency: urgency(3, 1), next_deadline: deadline(2), is_vat_payer: false, accounting_mode: 'tax_evidence' }),
    company(4, 'Dana', { urgency: urgency(3), next_deadline: deadline(-1), unbooked_documents: 7, last_invoice_date: '2026-09-30' }),
  ]

  it('defaults to urgency, ties by nearest deadline, then name', () => {
    expect(names(applyListing(rows, listingFromQuery({})))).toEqual(['Beta', 'Dana', 'Cyril', 'Alfa'])
  })

  it('sorts by deadline with companies without one last in both directions', () => {
    expect(names(applyListing(rows, listingFromQuery({ sort: 'deadline' })))).toEqual(['Dana', 'Cyril', 'Alfa', 'Beta'])
    expect(names(applyListing(rows, listingFromQuery({ sort: 'deadline', dir: 'desc' })))).toEqual(['Alfa', 'Cyril', 'Dana', 'Beta'])
  })

  it('sorts by name and by counts', () => {
    expect(names(applyListing(rows, listingFromQuery({ sort: 'name', dir: 'desc' })))).toEqual(['Dana', 'Cyril', 'Beta', 'Alfa'])
    expect(names(applyListing(rows, listingFromQuery({ sort: 'unbooked' })))).toEqual(['Dana', 'Beta', 'Alfa', 'Cyril'])
    expect(names(applyListing(rows, listingFromQuery({ sort: 'last_invoice' })))[0]).toBe('Dana')
  })

  it('sorts by documents awaiting extraction, most first', () => {
    const inbox = [
      company(1, 'Alfa', { inbox_pending: 2 }),
      company(2, 'Beta', { inbox_pending: 9 }),
      company(3, 'Cyril'),
    ]
    expect(names(applyListing(inbox, listingFromQuery({ sort: 'inbox' })))).toEqual(['Beta', 'Alfa', 'Cyril'])
    expect(names(applyListing(inbox, listingFromQuery({ sort: 'inbox', dir: 'asc' })))).toEqual(['Cyril', 'Alfa', 'Beta'])
  })

  it('filters by search, VAT, mode and urgency', () => {
    expect(names(applyListing(rows, listingFromQuery({ q: 'bet' })))).toEqual(['Beta'])
    expect(names(applyListing(rows, listingFromQuery({ q: '10000003' })))).toEqual(['Cyril'])
    expect(names(applyListing(rows, listingFromQuery({ vat: 'non_payer' })))).toEqual(['Cyril'])
    expect(names(applyListing(rows, listingFromQuery({ mode: 'double_entry', urgency: 'without' })))).toEqual(['Alfa'])
  })

  it('round-trips the URL and drops defaults', () => {
    expect(queryFromListing(listingFromQuery({}))).toEqual({})
    expect(queryFromListing(listingFromQuery({ sort: 'deadline', dir: 'desc', urgency: 'with', bogus: 'x' })))
      .toEqual({ sort: 'deadline', dir: 'desc', urgency: 'with' })
    expect(listingFromQuery({ sort: 'nonsense' }).sort).toBe('urgency')
  })
})
