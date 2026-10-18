import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, unknown> },
  dphPreview: vi.fn(), khPreview: vi.fn(), shvPreview: vi.fn(), dphBookPreview: vi.fn(), dphSettings: vi.fn(),
  dphDownloadUrl: vi.fn(), khDownloadUrl: vi.fn(), shvDownloadUrl: vi.fn(), dphBookPdfUrl: vi.fn(),
  khEvidencePreview: vi.fn(), khEvidenceDownloadUrl: vi.fn(), downloadApiFile: vi.fn(),
}))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({
  push: vi.fn(),
  replace: vi.fn(async ({ query }: { query: Record<string, unknown> }) => { m.route.query = query }),
}) }))
vi.mock('@/api/reports', () => ({
  KH_EVIDENCE_SECTIONS: ['A.1', 'A.2', 'A.4', 'A.5', 'B.1', 'B.2', 'B.3'],
  reportsApi: {
    dphSettings: m.dphSettings,
    dphPreview: m.dphPreview, khPreview: m.khPreview, shvPreview: m.shvPreview, dphBookPreview: m.dphBookPreview,
    dphDownloadUrl: m.dphDownloadUrl, khDownloadUrl: m.khDownloadUrl, shvDownloadUrl: m.shvDownloadUrl,
    dphBookPdfUrl: m.dphBookPdfUrl, khEvidencePreview: m.khEvidencePreview, khEvidenceDownloadUrl: m.khEvidenceDownloadUrl,
    dphTrend: vi.fn().mockResolvedValue([]), dphDraftsPrediction: vi.fn().mockResolvedValue(null),
  },
}))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: m.downloadApiFile }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 11 }, canRead: () => false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 7 }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [2026, 2025] }))
vi.mock('vue-i18n', async (original) => ({
  ...await original<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))
import DphReport from '@/pages/reports/DphPriznaniReport.vue'
import KhReport from '@/pages/reports/KontrolniHlaseniReport.vue'
import ShReport from '@/pages/reports/SouhrnneHlaseniReport.vue'
import DphBookReport from '@/pages/reports/DphBookReport.vue'

const pages = [
  { name: 'DPH', component: DphReport, preview: m.dphPreview, download: m.dphDownloadUrl, action: 'downloadXml', companyPeriod: true },
  { name: 'KH', component: KhReport, preview: m.khPreview, download: m.khDownloadUrl, action: 'downloadXml', companyPeriod: true },
  // SH se řídí druhem plnění, ne periodicitou DPH firmy.
  { name: 'SH', component: ShReport, preview: m.shvPreview, download: m.shvDownloadUrl, action: 'downloadXml', companyPeriod: false },
  { name: 'Kniha DPH', component: DphBookReport, preview: m.dphBookPreview, download: m.dphBookPdfUrl, action: 'downloadPdf', companyPeriod: true },
]
const mounted: ReturnType<typeof shallowMount>[] = []
function mountPage(component: typeof DphReport | typeof KhReport | typeof ShReport | typeof DphBookReport) {
  const wrapper = shallowMount({ ...component, render: () => null })
  mounted.push(wrapper)
  return wrapper
}
function vm(wrapper: ReturnType<typeof shallowMount>) {
  return wrapper.vm as unknown as Record<string, unknown>
}

describe('období výkazů DPH', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-15T12:00:00+02:00'))
    sessionStorage.clear()
    m.route = reactive({ query: {} })
    for (const page of pages) {
      page.preview.mockReset().mockResolvedValue({ marker: 'new' })
      page.download.mockReset().mockReturnValue('/download')
    }
    m.downloadApiFile.mockReset().mockResolvedValue(undefined)
    m.khEvidencePreview.mockReset().mockResolvedValue({ marker: 'evidence' })
    m.khEvidenceDownloadUrl.mockReset().mockReturnValue('/evidence')
    m.dphSettings.mockReset().mockResolvedValue({ vat_period: 'quarterly', taxpayer_type: 'fo' })
    vi.spyOn(window, 'open').mockImplementation(() => null)
  })
  afterEach(() => {
    for (const wrapper of mounted.splice(0)) wrapper.unmount()
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  for (const { name, component, preview, download, action, companyPeriod } of pages) {
    const closed = (year: number, quarterMonth: number, month: number) =>
      companyPeriod ? [year, quarterMonth, 'quarterly'] : [year, month, 'monthly']
    it(`${name}: zamkne výběr období během nastavení a po chybě nabídne opakování`, async () => {
      m.dphSettings.mockRejectedValueOnce(new Error('settings unavailable'))
      preview.mockResolvedValue(null)
      const wrapper = shallowMount(component)
      mounted.push(wrapper)
      await flushPromises()
      const periodControls = wrapper.findAll('select[disabled]')
      expect(periodControls.length).toBeGreaterThanOrEqual(2)
      const toggles = wrapper.findAll('button').filter(button => ['reports.dph.monthly', 'reports.dph.quarterly'].includes(button.text()))
      expect(toggles).toHaveLength(2)
      for (const toggle of toggles) expect(toggle.attributes('disabled')).toBeDefined()
      expect(preview).not.toHaveBeenCalled()
      await (vm(wrapper)[action] as () => Promise<void>)()
      expect(download).not.toHaveBeenCalled()
      let resolveSettings!: (value: unknown) => void
      m.dphSettings.mockImplementationOnce(() => new Promise(resolve => { resolveSettings = resolve }))
      const retry = wrapper.findAll('button').find(button => button.text() === 'common.retry')
      expect(retry).toBeDefined()
      await retry!.trigger('click')
      expect(wrapper.text()).toContain('common.loading')
      expect(preview).not.toHaveBeenCalled()
      resolveSettings({ vat_period: 'quarterly', taxpayer_type: 'fo' })
      await flushPromises()
      expect(preview).toHaveBeenCalledTimes(1)
      expect(preview.mock.lastCall?.slice(0, 3)).toEqual(closed(2026, 9, 9))
      expect(wrapper.text()).not.toContain('settings unavailable')
      expect(wrapper.findAll('button').some(button => button.text() === 'common.retry')).toBe(false)
      for (const toggle of toggles) expect(toggle.attributes('disabled')).toBeUndefined()
    })
    it(`${name}: první náhled čeká na nastavení firmy a použije poslední uzavřené období`, async () => {
      let resolveSettings!: (value: unknown) => void
      m.dphSettings.mockImplementationOnce(() => new Promise(resolve => { resolveSettings = resolve }))
      mountPage(component)
      await flushPromises()
      expect(preview).not.toHaveBeenCalled()
      resolveSettings({ vat_period: 'quarterly', taxpayer_type: 'fo' })
      await flushPromises()
      expect(preview).toHaveBeenCalledTimes(1)
      expect(preview.mock.calls[0]?.slice(0, 3)).toEqual(closed(2026, 9, 9))
    })
    it(`${name}: měsíční firma začíná posledním uzavřeným měsícem`, async () => {
      m.dphSettings.mockResolvedValue({ vat_period: 'monthly', taxpayer_type: 'fo' })
      mountPage(component)
      await flushPromises()
      expect(preview.mock.calls[0]?.slice(0, 3)).toEqual([2026, 9, 'monthly'])
    })
    it(`${name}: leden použije předchozí rok také pro export`, async () => {
      vi.setSystemTime(new Date('2026-01-15T12:00:00+01:00'))
      const wrapper = mountPage(component)
      await flushPromises()
      expect(preview.mock.calls[0]?.slice(0, 3)).toEqual(closed(2025, 12, 12))
      await (vm(wrapper)[action] as () => Promise<void>)()
      expect(download.mock.lastCall?.slice(0, 3)).toEqual(closed(2025, 12, 12))
    })
    it(`${name}: uvnitř kvartálu zvolí předchozí uzavřené období`, async () => {
      vi.setSystemTime(new Date('2026-08-15T12:00:00+02:00'))
      mountPage(component)
      await flushPromises()
      expect(preview.mock.calls[0]?.slice(0, 3)).toEqual(closed(2026, 6, 7))
    })
    it(`${name}: první náhled i export respektují explicitní období odkazu`, async () => {
      m.route.query = { year: '2025', month: '12', period: 'monthly' }
      const wrapper = mountPage(component)
      await flushPromises()
      expect(preview).toHaveBeenCalledTimes(1)
      expect(preview.mock.calls[0]?.slice(0, 3)).toEqual([2025, 12, 'monthly'])
      await (vm(wrapper)[action] as () => Promise<void>)()
      expect(download.mock.lastCall?.slice(0, 3)).toEqual([2025, 12, 'monthly'])
    })
    it(`${name}: bez načteného nastavení nespouští export`, async () => {
      m.dphSettings.mockImplementationOnce(() => new Promise(() => {}))
      const wrapper = mountPage(component)
      await flushPromises()
      await (vm(wrapper)[action] as () => Promise<void>)()
      expect(download).not.toHaveBeenCalled()
    })
    it(`${name}: chyba nastavení nespustí náhled s odhadnutým obdobím`, async () => {
      m.dphSettings.mockRejectedValue(new Error('settings unavailable'))
      const wrapper = mountPage(component)
      await flushPromises()
      expect(preview).not.toHaveBeenCalled()
      expect(vm(wrapper).error).toContain('settings unavailable')
    })
    it(`${name}: reaguje na proklik do jiného období ve stejné stránce`, async () => {
      m.route.query = { year: '2026', month: '7', period: 'quarterly' }
      mountPage(component)
      await flushPromises()
      expect(preview.mock.calls[0]?.slice(0, 3)).toEqual([2026, 7, 'quarterly'])
      m.route.query = { year: '2025', month: '9', period: 'monthly' }
      await flushPromises()
      expect(preview.mock.lastCall?.slice(0, 3)).toEqual([2025, 9, 'monthly'])
    })
    it(`${name}: návrat z menu zachová ručně zvolené období v relaci`, async () => {
      const wrapper = mountPage(component)
      await flushPromises()
      vm(wrapper).year = 2025
      vm(wrapper).month = 6
      vm(wrapper).periodOverride = 'monthly'
      await flushPromises()
      wrapper.unmount()
      mountPage(component)
      await flushPromises()
      expect(preview.mock.lastCall?.slice(0, 3)).toEqual([2025, 6, 'monthly'])
    })
    it(`${name}: opožděný úspěšný náhled nepřepíše nové období`, async () => {
      let resolveOld!: (value: unknown) => void
      preview.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
      m.route.query = { year: '2026', month: '7', period: 'quarterly' }
      const wrapper = mountPage(component)
      await flushPromises()
      m.route.query = { year: '2026', month: '9', period: 'monthly' }
      await flushPromises()
      resolveOld({ marker: 'old' })
      await flushPromises()
      expect(vm(wrapper).preview).toEqual({ marker: 'new' })
    })
    it(`${name}: opožděná chyba předchozího období nepřepíše nové období`, async () => {
      let rejectOld!: (error: Error) => void
      preview.mockImplementationOnce(() => new Promise((_, reject) => { rejectOld = reject }))
      m.route.query = { year: '2026', month: '7', period: 'quarterly' }
      const wrapper = mountPage(component)
      await flushPromises()
      m.route.query = { year: '2026', month: '9', period: 'monthly' }
      await flushPromises()
      rejectOld(new Error('old period unavailable'))
      await flushPromises()
      expect(vm(wrapper).error).toBe('')
      expect(vm(wrapper).loading).toBe(false)
    })
    it(`${name}: neplatné parametry nezmění poslední uzavřené období`, async () => {
      m.route.query = { year: ['2025', '2024'], month: '12oops', period: ['quarterly', 'monthly'] }
      mountPage(component)
      await flushPromises()
      expect(preview.mock.calls[0]?.slice(0, 3)).toEqual(closed(2026, 9, 9))
    })
  }

  it('KH právnické osoby zůstane měsíční i při kvartálním nastavení firmy a odkazu', async () => {
    m.dphSettings.mockResolvedValue({ vat_period: 'quarterly', taxpayer_type: 'po' })
    m.route.query = { year: '2025', month: '6', period: 'quarterly' }
    const wrapper = mountPage(KhReport)
    await flushPromises()
    expect(m.khPreview.mock.calls[0]?.slice(0, 3)).toEqual([2025, 6, 'monthly'])
    await (vm(wrapper).downloadXml as () => Promise<void>)()
    expect(m.khDownloadUrl.mock.lastCall?.slice(0, 3)).toEqual([2025, 6, 'monthly'])
  })

  it('ruční výběr z DPH přebírá Kniha DPH, souhrnné hlášení jen rok a měsíc', async () => {
    const dph = mountPage(DphReport)
    await flushPromises()
    vm(dph).year = 2025
    vm(dph).month = 3
    vm(dph).periodOverride = 'quarterly'
    await flushPromises()
    dph.unmount()
    mountPage(ShReport)
    await flushPromises()
    mountPage(DphBookReport)
    await flushPromises()
    expect(m.shvPreview.mock.lastCall?.slice(0, 3)).toEqual([2025, 3, 'monthly'])
    expect(m.dphBookPreview.mock.lastCall?.slice(0, 3)).toEqual([2025, 3, 'quarterly'])
  })

  it('Kniha DPH předá stejné vybrané období náhledu a exportu oddílu KH', async () => {
    m.route.query = { year: '2025', month: '6', period: 'quarterly' }
    const wrapper = mountPage(DphBookReport)
    await flushPromises()
    vm(wrapper).khSection = 'A.4'
    await flushPromises()
    expect(m.khEvidencePreview.mock.lastCall).toEqual([2025, 6, 'quarterly', 'A.4'])
    const downloadKh = vm(wrapper).downloadKh as (format: string) => void
    downloadKh('xlsx')
    expect(m.khEvidenceDownloadUrl.mock.lastCall).toEqual([2025, 6, 'quarterly', 'A.4', 'xlsx'])
  })
})
