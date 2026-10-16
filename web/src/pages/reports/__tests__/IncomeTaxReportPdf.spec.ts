import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'

// Tlačítko „PDF sestava" na stránce přiznání: vidí ho jen uživatel s exportem výkazů
// a stáhne pracovní PDF sestavu pro aktuální typ, rok a druh přiznání. ActionBar drží
// v hlavičce nejvýš 3 akce, u rozpracovaného přiznání je proto sestava v „…".
const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, unknown> },
  listPeriods: vi.fn(),
  canRead: true,
  taxpayerType: 'po' as 'po' | 'fo',
  get: vi.fn(),
  download: vi.fn(),
  reportPdfUrl: vi.fn(),
}))

vi.mock('@/api/taxReturn', () => ({
  taxReturnApi: {
    get: m.get,
    reportPdfUrl: m.reportPdfUrl,
    xmlUrl: () => '/api/tax-return/po/2025/xml',
    previewXmlUrl: () => '/api/tax-return/fo/2025/xml/preview',
    advanceOverrides: vi.fn().mockResolvedValue({ overrides: [], schedules: [] }),
    advances: vi.fn().mockResolvedValue({ schedules: [] }),
    insurance: vi.fn().mockResolvedValue(null),
  },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listPeriods: m.listPeriods } }))
vi.mock('@/api/tax', () => ({ taxApi: { saveProfile: vi.fn() } }))
vi.mock('@/api/taxEvidence', () => ({ taxEvidenceApi: { closing: vi.fn().mockResolvedValue(null) } }))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e),
}))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: m.download }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { taxpayer_type: m.taxpayerType } }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => m.canRead, canWrite: () => true }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/composables/useYearOptions', async () => {
  const { computed } = await import('vue')
  return { useYearOptions: (_source: string, year: { value: number }) => computed(() => [...new Set([2026, 2025, year.value])]) }
})
// Částečný mock: `createI18n` potřebuje useFormat, `t` vrací klíč.
vi.mock('vue-i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-i18n')>()
  return {
    ...actual,
    useI18n: () => ({
      t: (key: string) => key,
      tm: () => [],
      rt: (v: unknown) => String(v),
      te: () => false,
      locale: { value: 'cs' },
    }),
  }
})

import IncomeTaxReport from '@/pages/reports/IncomeTaxReport.vue'

function makeState(type: 'po' | 'fo', status: 'draft' | 'final' = 'draft') {
  return {
    return: {
      year: 2025, type, variant: 'radne', variant_seq: 1, status, row_version: 1, inputs: {},
      last_submission_id: null, final_snapshot_id: null, finalized_at: null, finalized_by: null, updated_at: null,
    },
    form_code: type === 'po' ? 'dppdp9' : 'dpfdp7',
    variant: 'radne',
    variant_seq: 1,
    available_variants: [],
    last_known_tax_suggested: null,
    computed: {
      lines: [], tax: 0, advances_paid: 0, balance_due: 0, projection: null, summary: {}, warnings: [],
      next_advances: { regime: 'none', count: 0, amount: 0, total: 0, note: '' },
    },
    podklady: {
      period: { starts_on: '2025-01-01', ends_on: '2025-12-31' },
      suggestions: { addbacks: [], deductions: [] }, bank_accounts: [], bank_account: null, profile: {},
    },
    warnings: [],
    tax_losses: { losses: [], available_total: 0, suggested: 0, carry_years: 5 },
    prefinalize_check: null,
    snapshot: null,
    constants_year: 2025,
  }
}

const LABEL = 'taxReturn.report_pdf'

function inlinePdfButton(wrapper: VueWrapper) {
  return wrapper.findAll('button').find((b) => b.text().includes(LABEL))
}

/** Akce „PDF sestava" inline, nebo po otevření „…" v teleportovaném menu. */
async function findPdfAction(wrapper: VueWrapper): Promise<HTMLElement | undefined> {
  const inline = inlinePdfButton(wrapper)
  if (inline) return inline.element as HTMLElement
  const trigger = wrapper.findAll('button').find((b) => b.attributes('title') === 'common.more_actions')
  if (!trigger) return undefined
  await trigger.trigger('click')
  await flushPromises()
  return Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.includes(LABEL))
}

let wrapper: VueWrapper | null = null

async function mountPage(state: ReturnType<typeof makeState>) {
  m.get.mockResolvedValue(state)
  wrapper = mount(IncomeTaxReport, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('IncomeTaxReport.vue: PDF sestava', () => {
  beforeEach(() => {
    m.route = reactive({ query: {} })
    m.listPeriods.mockReset().mockResolvedValue([])
    m.canRead = true
    m.taxpayerType = 'po'
    m.get.mockReset()
    m.download.mockReset()
    m.reportPdfUrl.mockReset()
    m.reportPdfUrl.mockImplementation((type: string, year: number) => `/api/tax-return/${type}/${year}/pdf?`)
    m.download.mockResolvedValue({})
  })
  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.innerHTML = ''
    vi.useRealTimers()
  })

  it('odkaz na roční přiznání zachová rok i mimo nabídku účetních období', async () => {
    m.route.query = { year: '2024' }
    m.listPeriods.mockResolvedValue([{ fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31' }])
    const page = await mountPage(makeState('po'))
    expect(m.get).toHaveBeenCalled()
    expect(m.get.mock.calls.every(call => call[1] === 2024)).toBe(true)
    expect((page.vm as unknown as { yearSelectOptions: Array<{ value: number }> }).yearSelectOptions.map(option => option.value)).toContain(2024)
  })

  it('roční přiznání reaguje na jiný rok v URL a běžné menu obnoví výchozí rok', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-15T12:00:00+02:00'))
    m.route.query = { year: '2024' }
    await mountPage(makeState('po'))
    expect(m.get).toHaveBeenLastCalledWith('po', 2024, 'radne', undefined)
    m.route.query = { year: '2023' }
    await flushPromises()
    expect(m.get).toHaveBeenLastCalledWith('po', 2023, 'radne', undefined)
    m.route.query = {}
    await flushPromises()
    expect(m.get).toHaveBeenLastCalledWith('po', 2025, 'radne', undefined)
  })

  it('u rozpracovaného DPPO je sestava v „…" a stáhne PDF pro aktuální rok a druh', async () => {
    const page = await mountPage(makeState('po'))
    expect(inlinePdfButton(page)).toBeUndefined()

    const action = await findPdfAction(page)
    expect(action).toBeTruthy()
    expect(action!.querySelector('svg')).not.toBeNull()
    action!.click()
    await flushPromises()

    expect(m.reportPdfUrl).toHaveBeenCalledWith('po', 2025, 'radne', undefined)
    expect(m.download).toHaveBeenCalledTimes(1)
    expect(m.download.mock.calls[0][0]).toBe('/api/tax-return/po/2025/pdf?')
  })

  it('u finálního přiznání je sestava inline hned vedle stažení XML', async () => {
    const page = await mountPage(makeState('po', 'final'))
    const labels = page.findAll('button').map((b) => b.text())
    const xmlIndex = labels.findIndex((l) => l.includes('taxReturn.download_xml'))
    const pdfIndex = labels.findIndex((l) => l.includes(LABEL))
    expect(xmlIndex).toBeGreaterThanOrEqual(0)
    expect(pdfIndex).toBe(xmlIndex + 1)

    await page.findAll('button')[pdfIndex].trigger('click')
    await flushPromises()
    expect(m.download).toHaveBeenCalledTimes(1)
  })

  it('nabízí sestavu i u DPFO', async () => {
    m.taxpayerType = 'fo'
    const page = await mountPage(makeState('fo'))

    const action = await findPdfAction(page)
    expect(action).toBeTruthy()
    action!.click()
    await flushPromises()
    expect(m.reportPdfUrl).toHaveBeenCalledWith('fo', 2025, 'radne', undefined)
  })

  it('záložka Export nabízí vedle XML i box PDF sestavy, který stáhne sestavu', async () => {
    const page = await mountPage(makeState('po'))
    const box = page.find('[data-testid="export-pdf-box"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('taxReturn.export_pdf_title')
    expect(box.text()).toContain('taxReturn.export_pdf_hint')

    const button = box.get('[data-testid="export-pdf-download"]')
    expect(button.find('svg').exists()).toBe(true)
    await button.trigger('click')
    await flushPromises()

    expect(m.reportPdfUrl).toHaveBeenCalledWith('po', 2025, 'radne', undefined)
    expect(m.download).toHaveBeenCalledWith('/api/tax-return/po/2025/pdf?', 'po-2025-sestava.pdf')
  })

  it('bez práva exportu výkazů akce není ani v „…"', async () => {
    m.canRead = false
    const page = await mountPage(makeState('po'))

    expect(await findPdfAction(page)).toBeUndefined()
    expect(m.download).not.toHaveBeenCalled()
  })
})
