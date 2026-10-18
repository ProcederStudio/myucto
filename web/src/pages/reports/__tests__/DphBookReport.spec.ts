import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import type { DphBookPreview, DphBookRow, DphBookSection, KhEvidenceReport } from '@/api/reports'

const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, string> },
  replace: vi.fn(),
  supplier: { currentSupplierId: 1 },
  canRead: vi.fn(), openBeside: vi.fn(), error: vi.fn(),
  api: {
    dphSettings: vi.fn(), dphBookPreview: vi.fn(), khEvidencePreview: vi.fn(),
    dphBookPdfUrl: vi.fn(() => '/book.pdf'), khEvidenceDownloadUrl: vi.fn(() => '/kh.pdf'),
  },
}))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({
  replace: m.replace,
  resolve: (to: unknown) => ({ href: `/#${JSON.stringify(to)}` }),
}) }))
vi.mock('@/api/reports', async original => ({ ...await original<typeof import('@/api/reports')>(), reportsApi: m.api }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 11 }, canRead: m.canRead }) }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplier = reactive({ currentSupplierId: 1 })
  return { useSupplierStore: () => m.supplier }
})
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: m.openBeside }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error }) }))
vi.mock('@/composables/useYearOptions', async () => {
  const { computed } = await import('vue')
  return { useYearOptions: () => computed(() => [2026, 2025]) }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, values?: Record<string, unknown>) => `${key}${values ? JSON.stringify(values) : ''}`,
  locale: { value: 'cs' },
}) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
import DphBookReport from '../DphBookReport.vue'

const row = (over: Partial<DphBookRow>): DphBookRow => ({
  invoice_id: 42, direction: 'issued', doc_number: 'TEST-42', original_doc_number: null,
  tax_date: '2026-10-01', accounting_date: null, claim_date: null, claim_basis: null, received_at: null,
  description: 'Test', counterparty_name: 'Test', counterparty_dic: '', vat_classification_code: null,
  vat_rate: 21, currency: 'CZK', exchange_rate: 1, base: 100, vat: 21, total: 121,
  status: 'issued', is_draft: false, kh_section: null, ...over,
})

function section(key: string, line: string): DphBookSection {
  return { key, dphdp3_line: line, direction: 'PŘIJATÁ', label: key, vat_rate: 21,
    is_secondary: false, rows: [], subtotal_base: 100, subtotal_vat: 21, subtotal_total: 121 }
}
function preview(label = 'Q2/2025'): DphBookPreview {
  return {
    period: { year: 2025, month: 6, period_type: 'quarterly', quarter: 2,
      start: '2025-04-01', end: '2025-06-30', label }, supplier: {},
    sections: [section('SECTION_43_FULL', '043'), section('SECTION_43_MIRROR', '43'), section('SECTION_43_REDUCED', '43k'), section('SECTION_1', '1')],
    totals: { issued: { base: 9000, vat: 1890, total: 10890 },
      received: { base: 200, vat: 42, total: 242 }, vat_balance: 1848 },
  }
}
function khReport(sectionName = 'A.4'): KhEvidenceReport {
  return { period: preview().period, section: sectionName, sections: {} } as KhEvidenceReport
}
function mountReport() {
  return mount(DphBookReport, { global: { stubs: {
    KhEvidenceSections: { props: ['report'], template: '<div data-test="kh-report">{{ report.section }}</div>' },
    EmptyState: { props: ['title'], template: '<div>{{ title }}</div>' },
  } } })
}
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}

beforeEach(() => {
  vi.clearAllMocks()
  sessionStorage.clear()
  m.supplier.currentSupplierId = 1
  m.canRead.mockReturnValue(true)
  m.openBeside.mockResolvedValue(undefined)
  m.api.dphSettings.mockResolvedValue({ vat_period: 'monthly', taxpayer_type: 'fo' })
  m.route = reactive({ query: {} })
  m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => {
    m.route.query = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined))
    return Promise.resolve()
  })
})
afterEach(() => {
  vi.restoreAllMocks()
})

describe('Kniha DPH – proklik dokladů', () => {
  beforeEach(() => {
    const rows = [row({}), row({ direction: 'received', doc_number: 'TEST-PF' })]
    m.api.dphBookPreview.mockResolvedValue({
      period: { label: '10/2026' },
      sections: [{ key: '1', direction: 'USKUTEČNĚNÁ', label: 'Test', rows, subtotal_base: 200, subtotal_vat: 42, subtotal_total: 242 }],
      totals: { issued: { base: 100, vat: 21, total: 121 }, received: { base: 100, vat: 21, total: 121 }, vat_balance: 0 },
    } as DphBookPreview)
  })

  it('v řádcích sestavy zachová čísla a nabídne skutečné odkazy na správné doklady', async () => {
    const wrapper = mount(DphBookReport)
    await flushPromises()
    const links = wrapper.findAll('tbody a')
    expect(links).toHaveLength(2)
    expect(links[0]!.text()).toBe('VF TEST-42')
    expect(links[1]!.text()).toBe('PF TEST-PF')
    expect(links[0]!.attributes('href')).toContain('invoice-detail')
    expect(links[1]!.attributes('href')).toContain('purchase-invoice-detail')
    await links[1]!.trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledWith({ name: 'purchase-invoice-detail', params: { id: 42 } })
    wrapper.unmount()
  })

  it('bez oprávnění k dokladům zůstane sestava čitelná bez prokliků', async () => {
    m.canRead.mockReturnValue(false)
    const wrapper = mount(DphBookReport)
    await flushPromises()
    expect(wrapper.findAll('tbody a')).toHaveLength(0)
    expect(wrapper.text()).toContain('VF TEST-42')
    expect(wrapper.text()).toContain('PF TEST-PF')
    wrapper.unmount()
  })
})

describe('Kniha DPH – rozpis řádku přiznání a oddílu KH', () => {
  beforeEach(() => {
    m.route.query = { year: '2025', month: '6', period: 'quarterly', dph_line: '43' }
    m.api.dphBookPreview.mockResolvedValue(preview())
    m.api.khEvidencePreview.mockResolvedValue(khReport())
  })

  it('převezme období z odkazu a ukáže všechny oddíly řádku bez součtů sestavy a bez PDF celé knihy', async () => {
    const wrapper = mountReport()
    await flushPromises()
    expect(m.api.dphBookPreview).toHaveBeenCalledWith(2025, 6, 'quarterly')
    expect(wrapper.text()).toContain('SECTION_43_FULL')
    expect(wrapper.text()).toContain('SECTION_43_MIRROR')
    expect(wrapper.text()).not.toContain('SECTION_43_REDUCED')
    expect(wrapper.text()).not.toContain('SECTION_1')
    expect(wrapper.text()).not.toContain('reports.dph_book.summary_issued')
    expect(wrapper.text()).not.toContain('reports.dph_book.vat_balance')
    expect(wrapper.text()).toContain('reports.dph_book.current_book_note')
    const pdf = wrapper.findAll('button').find(button => button.text().includes('reports.dph_book.download_pdf'))!
    expect(pdf.attributes('disabled')).toBeDefined()

    await wrapper.get('select[aria-label="reports.dph_book.section_filter"]').setValue('')
    await flushPromises()
    expect(m.route.query).toEqual({ year: '2025', month: '6', period: 'quarterly' })
    expect(wrapper.text()).toContain('SECTION_1')
    expect(wrapper.text()).toContain('reports.dph_book.summary_issued')
    expect(pdf.attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('změna řádku přiznání jen přefiltruje načtenou knihu a server znovu nevolá', async () => {
    const wrapper = mountReport()
    await flushPromises()
    expect(m.api.dphBookPreview).toHaveBeenCalledTimes(1)
    const filter = wrapper.get('select[aria-label="reports.dph_book.section_filter"]')
    await filter.setValue('dph:1')
    await flushPromises()
    expect(wrapper.text()).toContain('SECTION_1')
    expect(wrapper.text()).not.toContain('SECTION_43_FULL')
    await filter.setValue('')
    await flushPromises()
    expect(wrapper.text()).toContain('SECTION_43_FULL')
    expect(m.api.dphBookPreview).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('v znovu použitém panelu převezme oddíl KH i s obdobím pro export', async () => {
    const wrapper = mountReport()
    await flushPromises()
    m.route.query = { year: '2026', month: '9', period: 'quarterly', kh_section: 'A.4' }
    await flushPromises()
    expect(m.api.khEvidencePreview).toHaveBeenLastCalledWith(2026, 9, 'quarterly', 'A.4')
    expect(wrapper.get('[data-test="kh-report"]').text()).toBe('A.4')
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    await wrapper.findAll('button').find(button => button.text().includes('reports.kh_evidence.download_pdf'))!.trigger('click')
    expect(m.api.khEvidenceDownloadUrl).toHaveBeenCalledWith(2026, 9, 'quarterly', 'A.4', 'pdf')
    expect(open).toHaveBeenCalledWith('/kh.pdf', '_blank')
    wrapper.unmount()
  })

  it('po novém odkazu zahodí starší odpověď i chybu předchozího období', async () => {
    const earlier = deferred<DphBookPreview>()
    m.api.dphBookPreview.mockReturnValueOnce(earlier.promise)
    const wrapper = mountReport()
    await flushPromises()
    m.route.query = { year: '2026', month: '7', period: 'monthly', dph_line: '1' }
    await flushPromises()
    expect(wrapper.text()).toContain('SECTION_1')
    earlier.resolve(preview('OLD_PERIOD'))
    await flushPromises()
    expect(wrapper.text()).not.toContain('OLD_PERIOD')

    const failed = deferred<DphBookPreview>()
    m.api.dphBookPreview.mockReturnValueOnce(failed.promise)
    m.route.query = { year: '2026', month: '8', period: 'monthly', dph_line: '43' }
    await flushPromises()
    expect(wrapper.text()).not.toContain('SECTION_1')
    m.route.query = { year: '2026', month: '9', period: 'monthly', kh_section: 'A.4' }
    await flushPromises()
    failed.reject('STALE_ERROR')
    await flushPromises()
    expect(wrapper.text()).not.toContain('STALE_ERROR')
    expect(wrapper.get('[data-test="kh-report"]').text()).toBe('A.4')
    wrapper.unmount()
  })

  it('prázdný zvolený řádek nespadne zpět na celou knihu', async () => {
    m.route.query.dph_line = '99'
    const wrapper = mountReport()
    await flushPromises()
    expect(wrapper.text()).toContain('reports.dph_book.no_data')
    expect(wrapper.text()).not.toContain('SECTION_1')
    expect(wrapper.get<HTMLSelectElement>('select[aria-label="reports.dph_book.section_filter"]').element.value).toBe('dph:99')
    wrapper.unmount()
  })

  it('krácený odpočet a ř. 51b drží odděleně od číselného řádku', async () => {
    m.route.query.dph_line = '043k'
    m.api.dphBookPreview.mockResolvedValue({ ...preview(), sections: [
      ...preview().sections, section('SECTION_51', '51'), section('SECTION_51B', '51b'),
    ] })
    const wrapper = mountReport()
    await flushPromises()
    expect(wrapper.text()).toContain('SECTION_43_REDUCED')
    expect(wrapper.text()).not.toContain('SECTION_43_FULL')
    expect(wrapper.text()).not.toContain('SECTION_43_MIRROR')
    m.route.query = { year: '2025', month: '6', period: 'quarterly', dph_line: '51b' }
    await flushPromises()
    expect(wrapper.findAll('h3').map(header => header.text()).join()).toContain('SECTION_51B')
    expect(wrapper.findAll('h3').map(header => header.text()).join()).not.toMatch(/SECTION_51\s/)
    expect(m.api.dphBookPreview).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('úvodní odkaz na oddíl KH načte soupis a filtr ho vrátí na celou knihu', async () => {
    m.route.query = { year: '2025', month: '6', period: 'monthly', kh_section: 'A.4' }
    const wrapper = mountReport()
    await flushPromises()
    expect(m.api.khEvidencePreview).toHaveBeenCalledWith(2025, 6, 'monthly', 'A.4')
    expect(m.api.dphBookPreview).not.toHaveBeenCalled()
    await wrapper.get('select[aria-label="reports.dph_book.section_filter"]').setValue('')
    await flushPromises()
    expect(wrapper.find('[data-test="kh-report"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('SECTION_1')
    expect(m.api.dphBookPreview).toHaveBeenLastCalledWith(2025, 6, 'monthly')
    wrapper.unmount()
  })

  it('během načítání nového období skryje data předchozího', async () => {
    const wrapper = mountReport()
    await flushPromises()
    expect(wrapper.text()).toContain('Q2/2025')
    const next = deferred<DphBookPreview>()
    m.api.dphBookPreview.mockReturnValueOnce(next.promise)
    m.route.query = { year: '2026', month: '7', period: 'monthly', dph_line: '1' }
    await flushPromises()
    expect(wrapper.text()).not.toContain('Q2/2025')
    expect(wrapper.text()).toContain('common.loading')
    next.resolve(preview('07/2026'))
    await flushPromises()
    expect(wrapper.text()).toContain('07/2026')
    wrapper.unmount()
  })

  it('ruční změna filtru zapíše URL panelu, takže původní odkaz jde otevřít znovu', async () => {
    const wrapper = mountReport()
    await flushPromises()
    await wrapper.get('select[aria-label="reports.dph_book.section_filter"]').setValue('kh:A.4')
    await flushPromises()
    expect(m.route.query).toEqual({ year: '2025', month: '6', period: 'quarterly', kh_section: 'A.4' })
    expect(wrapper.get('[data-test="kh-report"]').text()).toBe('A.4')
    m.route.query = { year: '2025', month: '6', period: 'quarterly', dph_line: '43' }
    await flushPromises()
    expect(wrapper.text()).toContain('SECTION_43_FULL')
    expect(wrapper.find('[data-test="kh-report"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('zahodí čekající odpověď, když se změní firma', async () => {
    const pending = deferred<DphBookPreview>()
    m.api.dphBookPreview.mockReturnValueOnce(pending.promise)
    const wrapper = mountReport()
    await flushPromises()
    m.supplier.currentSupplierId = 2
    pending.resolve(preview('WRONG_SUPPLIER_PERIOD'))
    await flushPromises()
    expect(wrapper.text()).not.toContain('WRONG_SUPPLIER_PERIOD')
    expect(m.api.dphSettings).toHaveBeenCalledTimes(2)
    expect(m.api.dphBookPreview).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('ruční volba období se zapíše do URL a znovu otevřený odkaz vrátí jeho období', async () => {
    const wrapper = mountReport()
    await flushPromises()
    await wrapper.findAll('select')[1]!.setValue('2026')
    await flushPromises()
    expect(m.route.query).toEqual({ year: '2026', month: '6', period: 'quarterly', dph_line: '43' })
    await wrapper.findAll('button').find(button => button.text() === 'reports.dph.monthly')!.trigger('click')
    await wrapper.findAll('select')[0]!.setValue('7')
    await flushPromises()
    expect(m.route.query).toEqual({ year: '2026', month: '7', period: 'monthly', dph_line: '43' })
    await wrapper.findAll('button').find(button => button.text() === 'reports.dph.quarterly')!.trigger('click')
    await wrapper.findAll('select')[0]!.setValue('3')
    await flushPromises()
    expect(m.route.query).toEqual({ year: '2026', month: '9', period: 'quarterly', dph_line: '43' })
    m.route.query = { year: '2025', month: '6', period: 'quarterly', dph_line: '43' }
    await flushPromises()
    expect(m.api.dphBookPreview).toHaveBeenLastCalledWith(2025, 6, 'quarterly')
    expect((wrapper.findAll('select')[1]!.element as HTMLSelectElement).value).toBe('2025')
    expect((wrapper.findAll('select')[0]!.element as HTMLSelectElement).value).toBe('2')
    wrapper.unmount()
  })
})
