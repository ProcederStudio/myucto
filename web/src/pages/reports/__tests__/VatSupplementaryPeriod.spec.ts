import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { ref } from 'vue'
import OssReport from '../OssReport.vue'
import Section74b from '../Section74b.vue'
import Section46 from '../Section46.vue'

const m = vi.hoisted(() => ({
  usePeriod: vi.fn(), initialize: vi.fn(),
  ossPreview: vi.fn(), s74bPreview: vi.fn(), s46Restorations: vi.fn(), s46Candidates: vi.fn(),
  ossDownloadUrl: vi.fn(), evidenceExportUrl: vi.fn(),
  archive: vi.fn(), reconciliation: vi.fn(), evidence: vi.fn(),
}))
vi.mock('@/composables/useVatReportPeriod', () => ({ useVatReportPeriod: m.usePeriod }))
vi.mock('@/api/reports', () => ({ reportsApi: m }))
vi.mock('@/api/ossFiling', () => ({ ossFilingApi: m }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ replace: vi.fn() }), RouterLink: { render: () => null } }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => ref([2026, 2025]) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))

function periodState() {
  return { year: ref(2025), month: ref(9), ready: ref(false), settingsError: ref<unknown>(null), initialize: m.initialize }
}
let period: ReturnType<typeof periodState>
beforeEach(() => {
  vi.clearAllMocks()
  period = periodState()
  m.usePeriod.mockReturnValue(period)
  m.initialize.mockImplementation(() => new Promise(() => {}))
  m.ossPreview.mockResolvedValue({ summary: { row_count: 0 } })
  m.s74bPreview.mockResolvedValue({ period: { year: 2025, month: 9 }, rows: [], totals: {} })
  m.s46Restorations.mockResolvedValue({ period: { year: 2025, month: 9 }, rows: [] })
  m.s46Candidates.mockResolvedValue({ rows: [] })
})

describe('období navazujících nástrojů DPH', () => {
  const cases = [
    { name: 'OSS', component: OssReport, kind: 'oss', preview: m.ossPreview, expected: [2025, 3] },
    { name: '§74b', component: Section74b, kind: 'monthly', preview: m.s74bPreview, expected: [2025, 9] },
    { name: 'obnovy §46', component: Section46, kind: 'monthly', preview: m.s46Restorations, expected: [2025, 9] },
  ]
  for (const { name, component, kind, preview, expected } of cases) {
    it(`${name}: čeká na ověřené období a zachová vlastní periodicitu`, async () => {
      const wrapper = shallowMount({ ...component, render: () => null })
      await flushPromises()
      expect(m.usePeriod).toHaveBeenCalledWith(kind)
      expect(m.initialize).toHaveBeenCalledOnce()
      expect(preview).not.toHaveBeenCalled()
      period.ready.value = true
      await flushPromises()
      expect(preview).toHaveBeenCalledExactlyOnceWith(...expected)
      period.ready.value = false
      period.month.value = 6
      await flushPromises()
      expect(preview).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })

    it(`${name}: stará odpověď po změně firmy nepřepíše nové období`, async () => {
      let resolveOld!: (value: unknown) => void
      preview.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
      preview.mockResolvedValue({ marker: 'new' })
      const wrapper = shallowMount({ ...component, render: () => null })
      period.ready.value = true
      await flushPromises()
      period.ready.value = false
      await flushPromises()
      period.year.value = 2024
      period.month.value = 6
      period.ready.value = true
      await flushPromises()
      resolveOld({ marker: 'old' })
      await flushPromises()
      const vm = wrapper.vm as unknown as { preview: { marker: string }; restorations: { marker: string } }
      expect((kind === 'monthly' && name === 'obnovy §46' ? vm.restorations : vm.preview).marker).toBe('new')
      wrapper.unmount()
    })
  }

  it('OSS neexportuje před načtením období a používá stejné čtvrtletí pro rekonciliaci i evidenci', async () => {
    m.reconciliation.mockResolvedValue({})
    m.evidence.mockResolvedValue({})
    const wrapper = shallowMount({ ...OssReport, render: () => null })
    await flushPromises()
    const vm = wrapper.vm as unknown as { downloadXml(): void; exportEvidence(format: 'csv'): void; activeTab: string }
    vm.downloadXml()
    vm.exportEvidence('csv')
    expect(m.ossDownloadUrl).not.toHaveBeenCalled()
    expect(m.evidenceExportUrl).not.toHaveBeenCalled()
    period.ready.value = true
    vm.activeTab = 'rekonciliace'
    await flushPromises()
    expect(m.reconciliation).toHaveBeenLastCalledWith(2025, 3)
    vm.activeTab = 'evidence'
    await flushPromises()
    expect(m.evidence).toHaveBeenLastCalledWith(2025, 3)
    wrapper.unmount()
  })
})
