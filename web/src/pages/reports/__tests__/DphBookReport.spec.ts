import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import type { DphBookPreview, DphBookSection, KhEvidenceReport } from '@/api/reports'
import DphBookReport from '../DphBookReport.vue'

const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, string> },
  replace: vi.fn(), supplierId: '1',
  dphBookPreview: vi.fn(), khEvidencePreview: vi.fn(),
  dphBookPdfUrl: vi.fn(() => '/book.pdf'), khEvidenceDownloadUrl: vi.fn(() => '/kh.pdf'),
}))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({ replace: m.replace }) }))
vi.mock('@/workspace/supplierContext', () => ({ requestSupplierId: () => m.supplierId }))
vi.mock('@/api/reports', () => ({
  KH_EVIDENCE_SECTIONS: ['A.1', 'A.2', 'A.4', 'A.5', 'B.1', 'B.2', 'B.3', 'none'],
  reportsApi: m,
}))
vi.mock('@/composables/useYearOptions', async () => {
  const { computed } = await import('vue')
  return { useYearOptions: () => computed(() => [2026, 2025]) }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, values?: Record<string, unknown>) => `${key}${values ? JSON.stringify(values) : ''}`,
  locale: { value: 'cs' },
}) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))

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

describe('DphBookReport section links', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.supplierId = '1'
    m.route = reactive({ query: { year: '2025', month: '6', period: 'quarterly', dph_line: '43' } })
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => {
      m.route.query = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined))
      return Promise.resolve()
    })
    m.dphBookPreview.mockResolvedValue(preview())
    m.khEvidencePreview.mockResolvedValue(khReport())
  })

  it('hydrates period and filters every section of the numeric return line, excluding report totals and full PDF', async () => {
    const wrapper = mountReport()
    await flushPromises()
    expect(m.dphBookPreview).toHaveBeenCalledWith(2025, 6, 'quarterly')
    expect(wrapper.text()).toContain('SECTION_43_FULL')
    expect(wrapper.text()).toContain('SECTION_43_MIRROR')
    expect(wrapper.text()).not.toContain('SECTION_43_REDUCED')
    expect(wrapper.text()).not.toContain('SECTION_1')
    expect(wrapper.text()).not.toContain('reports.dph_book.summary_issued')
    expect(wrapper.text()).not.toContain('reports.dph_book.vat_balance')
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

  it('hydrates a KH section in a reused pane and preserves its export filter and period', async () => {
    const wrapper = mountReport()
    await flushPromises()
    m.route.query = { year: '2026', month: '9', period: 'quarterly', kh_section: 'A.4' }
    await flushPromises()
    expect(m.khEvidencePreview).toHaveBeenLastCalledWith(2026, 9, 'quarterly', 'A.4')
    expect(wrapper.get('[data-test="kh-report"]').text()).toBe('A.4')
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    await wrapper.findAll('button').find(button => button.text().includes('reports.kh_evidence.download_pdf'))!.trigger('click')
    expect(m.khEvidenceDownloadUrl).toHaveBeenCalledWith(2026, 9, 'quarterly', 'A.4', 'pdf')
    open.mockRestore()
    wrapper.unmount()
  })

  it('clears old period data and ignores an earlier response and error after a new link', async () => {
    const earlier = deferred<DphBookPreview>()
    m.dphBookPreview.mockReturnValueOnce(earlier.promise)
    const wrapper = mountReport()
    m.route.query = { year: '2026', month: '7', period: 'monthly', dph_line: '1' }
    await flushPromises()
    expect(wrapper.text()).toContain('SECTION_1')
    earlier.resolve(preview('OLD_PERIOD'))
    await flushPromises()
    expect(wrapper.text()).not.toContain('OLD_PERIOD')

    const failed = deferred<DphBookPreview>()
    m.dphBookPreview.mockReturnValueOnce(failed.promise)
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

  it('shows an empty selected line without falling back to the whole book, and clears to all sections', async () => {
    m.route.query.dph_line = '99'
    const wrapper = mountReport()
    await flushPromises()
    expect(wrapper.text()).toContain('reports.dph_book.no_data')
    expect(wrapper.text()).not.toContain('SECTION_1')
    expect(wrapper.get<HTMLSelectElement>('select[aria-label="reports.dph_book.section_filter"]').element.value).toBe('dph:99')
    wrapper.unmount()
  })

  it('keeps reduced deduction and 51b distinct from their numeric lines', async () => {
    m.route.query.dph_line = '043k'
    m.dphBookPreview.mockResolvedValue({ ...preview(), sections: [
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
    wrapper.unmount()
  })

  it('loads an initial KH link and switches the filter exclusively back to the whole book', async () => {
    m.route.query = { year: '2025', month: '6', period: 'monthly', kh_section: 'A.4' }
    const wrapper = mountReport()
    await flushPromises()
    expect(m.khEvidencePreview).toHaveBeenCalledWith(2025, 6, 'monthly', 'A.4')
    expect(m.dphBookPreview).not.toHaveBeenCalled()
    await wrapper.get('select[aria-label="reports.dph_book.section_filter"]').setValue('')
    await flushPromises()
    expect(wrapper.find('[data-test="kh-report"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('SECTION_1')
    expect(m.dphBookPreview).toHaveBeenLastCalledWith(2025, 6, 'monthly')
    wrapper.unmount()
  })

  it('hides an already loaded period while the new request is pending', async () => {
    const wrapper = mountReport()
    await flushPromises()
    expect(wrapper.text()).toContain('Q2/2025')
    const next = deferred<DphBookPreview>()
    m.dphBookPreview.mockReturnValueOnce(next.promise)
    m.route.query = { year: '2026', month: '7', period: 'monthly', dph_line: '1' }
    await flushPromises()
    expect(wrapper.text()).not.toContain('Q2/2025')
    expect(wrapper.text()).toContain('common.loading')
    next.resolve(preview('07/2026'))
    await flushPromises()
    expect(wrapper.text()).toContain('07/2026')
    wrapper.unmount()
  })

  it('updates the pane URL on manual filter changes so the original source link can be opened again', async () => {
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

  it('discards a pending response when its supplier scope changes', async () => {
    const pending = deferred<DphBookPreview>()
    m.dphBookPreview.mockReturnValueOnce(pending.promise)
    const wrapper = mountReport()
    m.supplierId = '2'
    pending.resolve(preview('WRONG_SUPPLIER_PERIOD'))
    await flushPromises()
    expect(wrapper.text()).not.toContain('WRONG_SUPPLIER_PERIOD')
    expect(wrapper.text()).not.toContain('SECTION_43_FULL')
    wrapper.unmount()
  })

  it('synchronizes manual period controls so reopening the original link restores its period', async () => {
    const wrapper = mountReport()
    await flushPromises()
    await wrapper.findAll('select')[1]!.setValue('2026')
    expect(m.route.query).toEqual({ year: '2026', month: '6', period: 'quarterly', dph_line: '43' })
    await wrapper.findAll('button').find(button => button.text() === 'reports.dph.monthly')!.trigger('click')
    await wrapper.findAll('select')[0]!.setValue('7')
    expect(m.route.query).toEqual({ year: '2026', month: '7', period: 'monthly', dph_line: '43' })
    await wrapper.findAll('button').find(button => button.text() === 'reports.dph.quarterly')!.trigger('click')
    await wrapper.findAll('select')[0]!.setValue('3')
    expect(m.route.query).toEqual({ year: '2026', month: '9', period: 'quarterly', dph_line: '43' })
    m.route.query = { year: '2025', month: '6', period: 'quarterly', dph_line: '43' }
    await flushPromises()
    expect(m.dphBookPreview).toHaveBeenLastCalledWith(2025, 6, 'quarterly')
    expect((wrapper.findAll('select')[1]!.element as HTMLSelectElement).value).toBe('2025')
    expect((wrapper.findAll('select')[0]!.element as HTMLSelectElement).value).toBe('2')
    wrapper.unmount()
  })
})
