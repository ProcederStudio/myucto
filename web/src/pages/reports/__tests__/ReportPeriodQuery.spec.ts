import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, unknown> },
  dphPreview: vi.fn(), khPreview: vi.fn(), shvPreview: vi.fn(), dphSettings: vi.fn(),
}))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({ push: vi.fn() }) }))
vi.mock('@/api/reports', () => ({ reportsApi: {
  dphSettings: m.dphSettings,
  dphPreview: m.dphPreview, khPreview: m.khPreview, shvPreview: m.shvPreview,
  dphTrend: vi.fn().mockResolvedValue([]), dphDraftsPrediction: vi.fn().mockResolvedValue(null),
} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => false }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [2026, 2025] }))
vi.mock('vue-i18n', async (original) => ({
  ...await original<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))
import DphReport from '@/pages/reports/DphPriznaniReport.vue'
import KhReport from '@/pages/reports/KontrolniHlaseniReport.vue'
import ShReport from '@/pages/reports/SouhrnneHlaseniReport.vue'

const pages = [
  { name: 'DPH', component: DphReport, preview: m.dphPreview },
  { name: 'KH', component: KhReport, preview: m.khPreview },
  { name: 'SH', component: ShReport, preview: m.shvPreview },
]

describe('období výkazů z daňového kalendáře', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-15T12:00:00+02:00'))
    m.route = reactive({ query: {} })
    for (const page of pages) page.preview.mockReset().mockResolvedValue(null)
    m.dphSettings.mockReset().mockResolvedValue({ vat_period: 'quarterly', taxpayer_type: 'fo' })
  })
  afterEach(() => vi.useRealTimers())

  for (const { name, component, preview } of pages) {
    it(`${name}: první náhled respektuje měsíc i explicitní periodicitu před nastavením firmy`, async () => {
      m.route.query = { year: '2025', month: '12', period: 'monthly' }
      const wrapper = shallowMount(component)
      await flushPromises()
      expect(preview).toHaveBeenCalled()
      for (const call of preview.mock.calls) expect(call.slice(0, 3)).toEqual([2025, 12, 'monthly'])
      wrapper.unmount()
    })
    it(`${name}: reaguje na proklik do jiného období ve stejné stránce`, async () => {
      m.route.query = { year: '2026', month: '7', period: 'quarterly' }
      const wrapper = shallowMount(component)
      await flushPromises()
      expect(preview.mock.calls[0]?.slice(0, 3)).toEqual([2026, 7, 'quarterly'])
      m.route.query = { year: '2025', month: '9', period: 'monthly' }
      await flushPromises()
      expect(preview.mock.lastCall?.slice(0, 3)).toEqual([2025, 9, 'monthly'])
      wrapper.unmount()
    })
    it(`${name}: běžné menu obnoví výchozí období po prokliku z kalendáře`, async () => {
      m.route.query = { year: '2025', month: '7', period: 'monthly' }
      const wrapper = shallowMount(component)
      await flushPromises()
      m.route.query = {}
      await flushPromises()
      expect(preview.mock.lastCall?.slice(0, 3)).toEqual([
        2026, 10, name === 'KH' ? 'quarterly' : name === 'SH' ? 'monthly' : undefined,
      ])
      wrapper.unmount()
    })
    it(`${name}: opožděný úspěšný náhled nepřepíše nové období`, async () => {
      let resolveOld!: (value: unknown) => void
      preview.mockResolvedValue({ marker: 'new' })
      preview.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
      m.route.query = { year: '2026', month: '7', period: 'quarterly' }
      const wrapper = shallowMount({ ...component, render: () => null })
      await flushPromises()
      m.route.query = { year: '2026', month: '9', period: 'monthly' }
      await flushPromises()
      resolveOld({ marker: 'old' })
      await flushPromises()
      expect((wrapper.vm as unknown as { preview: { marker: string } }).preview.marker).toBe('new')
      wrapper.unmount()
    })
    it(`${name}: opožděná chyba předchozího období nepřepíše nové období`, async () => {
      let rejectOld!: (error: Error) => void
      preview.mockImplementationOnce(() => new Promise((_, reject) => { rejectOld = reject }))
      m.route.query = { year: '2026', month: '7', period: 'quarterly' }
      const wrapper = shallowMount(component)
      await flushPromises()
      m.route.query = { year: '2026', month: '9', period: 'monthly' }
      await flushPromises()
      rejectOld(new Error('old period unavailable'))
      await flushPromises()
      const vm = wrapper.vm as unknown as { error: string; loading: boolean }
      expect(vm.error).toBe('')
      expect(vm.loading).toBe(false)
      wrapper.unmount()
    })
    it(`${name}: neplatný nebo opakovaný parametr nenastaví jiné období`, async () => {
      m.route.query = { year: ['2025', '2024'], month: '12oops', period: ['quarterly', 'monthly'] }
      const wrapper = shallowMount(component)
      await flushPromises()
      expect(preview.mock.calls[0]?.slice(0, 2)).toEqual([2026, 10])
      expect(preview.mock.calls[0]?.[2]).toBe(name === 'KH' ? 'quarterly' : name === 'SH' ? 'monthly' : undefined)
      wrapper.unmount()
    })
  }
})
