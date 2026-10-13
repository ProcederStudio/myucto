import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { PortfolioCompany } from '@/api/portfolio'

// Regrese: sloupec „K doúčtování" sčítá tři různé entity (FV / PF / bankovní pohyby),
// ale proklik byl natvrdo `/invoices?booked=0`. Na ostrých datech tvořilo číslo 7
// sedm bankovních pohybů → uživatel přistál na prázdném seznamu vydaných faktur.

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  push: vi.fn(),
  setSupplier: vi.fn(),
  currentSupplierId: 2,
  isSuperadmin: false,
  isAdminPlusRole: false,
}))

vi.mock('@/api/portfolio', () => ({ portfolioApi: { overview: m.overview } }))

vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))

vi.mock('vue-router', () => ({ useRouter: () => ({ push: m.push, replace: m.push }), useRoute: () => ({ query: {} }) }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))

vi.mock('@/composables/useFormat', () => ({ formatNumber: (n: number) => String(n) }))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    get currentSupplierId() { return m.currentSupplierId },
    setSupplier: m.setSupplier,
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isSuperadmin() { return m.isSuperadmin },
    get isAdminPlusRole() { return m.isAdminPlusRole },
  }),
}))

vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { cycle: 'M0 0', factory: 'M0 0', chevron: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))

import PortfolioOverview from '../PortfolioOverview.vue'

const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }

function company(over: Partial<PortfolioCompany> = {}): PortfolioCompany {
  return {
    supplier_id: 2,
    company_name: 'Testovací s.r.o.',
    ic: '12345678',
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
    volume: {
      issued_invoices: 1234,
      purchase_invoices: 56,
      bank_statements: 12,
      bank_transactions: 789,
      cash_documents: 0,
      journal_entries: 4321,
    },
    urgency: null,
    last_invoice_date: null,
    last_activity_at: null,
    ...over,
  }
}

async function mountWith(c: PortfolioCompany) {
  m.overview.mockResolvedValue({ companies: [c], total: 1, generated_at: '2026-08-02T10:00:00+02:00' })
  const w = mount(PortfolioOverview, { global: { stubs: { RouterLink: RouterLinkStub } } })
  await flushPromises()
  return w
}

function resetMocks() {
  vi.clearAllMocks()
  m.currentSupplierId = 2
  m.isSuperadmin = false
  m.isAdminPlusRole = false
}

describe('PortfolioOverview — proklik „K doúčtování"', () => {
  beforeEach(resetMocks)

  it('vede na banku, když číslo tvoří jen bankovní pohyby', async () => {
    const w = await mountWith(company({
      unbooked_documents: 7,
      unbooked_breakdown: [{ key: 'bank', count: 7, link: '/bank?tab=posting' }],
    }))

    const cell = w.find('[data-testid="unbooked"]')
    expect(cell.text()).toContain('7')
    await cell.find('button').trigger('click')

    expect(m.push).toHaveBeenCalledWith('/bank?tab=posting')
  })

  it('vede na vydané faktury, jen když v čísle opravdu jsou', async () => {
    const w = await mountWith(company({
      unbooked_documents: 3,
      unbooked_breakdown: [{ key: 'invoices', count: 3, link: '/invoices?booked=0' }],
    }))

    await w.find('[data-testid="unbooked"]').find('button').trigger('click')

    expect(m.push).toHaveBeenCalledWith('/invoices?booked=0')
  })

  it('míchané typy rozpadne na samostatné prokliky', async () => {
    const w = await mountWith(company({
      unbooked_documents: 9,
      unbooked_breakdown: [
        { key: 'purchase_invoices', count: 2, link: '/purchase-invoices?booked=0' },
        { key: 'bank', count: 7, link: '/bank?tab=posting' },
      ],
    }))

    const buttons = w.find('[data-testid="unbooked"]').findAll('button')
    // 1× celkové číslo + 1 tlačítko na typ
    expect(buttons).toHaveLength(3)

    await buttons[0].trigger('click')
    expect(m.push).toHaveBeenLastCalledWith('/purchase-invoices?booked=0')

    await buttons[2].trigger('click')
    expect(m.push).toHaveBeenLastCalledWith('/bank?tab=posting')
  })

  it('u jediného typu rozpad nezobrazuje', async () => {
    const w = await mountWith(company({
      unbooked_documents: 7,
      unbooked_breakdown: [{ key: 'bank', count: 7, link: '/bank?tab=posting' }],
    }))

    expect(w.find('[data-testid="unbooked"]').findAll('button')).toHaveLength(1)
  })
})

describe('PortfolioOverview — objem dat', () => {
  beforeEach(resetMocks)

  function volumeButton(w: Awaited<ReturnType<typeof mountWith>>, key: string) {
    return w.find('[data-testid="volume"]').findAll('button').find((b) => b.text().includes('portfolio.volume_' + key))
  }

  it('metrika vede na seznam dokladů firmy', async () => {
    const w = await mountWith(company())

    const issued = volumeButton(w, 'issued_invoices')
    expect(issued).toBeDefined()
    await issued!.trigger('click')
    expect(m.push).toHaveBeenCalledWith('/invoices')

    await volumeButton(w, 'journal_entries')!.trigger('click')
    expect(m.push).toHaveBeenLastCalledWith('/accounting/journal')
  })

  it('v daňové evidenci deník nenabízí', async () => {
    const w = await mountWith(company({ accounting_mode: 'tax_evidence' }))

    expect(volumeButton(w, 'journal_entries')).toBeUndefined()
    expect(volumeButton(w, 'cash_documents')).toBeDefined()
  })
})

describe('PortfolioOverview — odkaz na správu firem', () => {
  beforeEach(resetMocks)

  it('superadmin má vedle Obnovit odkaz na Firmy', async () => {
    m.isSuperadmin = true
    const w = await mountWith(company())

    const link = w.find('[data-testid="portfolio-companies-link"]')
    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toBe('/admin/suppliers')
    expect(link.text()).toContain('nav.suppliers')
  })

  it('Admin Plus odkaz vidí také', async () => {
    m.isAdminPlusRole = true
    const w = await mountWith(company())

    expect(w.find('[data-testid="portfolio-companies-link"]').exists()).toBe(true)
  })

  it('účetní bez správy firem odkaz nevidí, stejně jako položku v menu', async () => {
    const w = await mountWith(company())

    expect(w.find('[data-testid="portfolio-companies-link"]').exists()).toBe(false)
  })
})
