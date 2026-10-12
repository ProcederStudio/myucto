import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import PohodaMigration from '../PohodaMigration.vue'
import { migrationYearLabel } from '@/utils/migrationYear'

const m = vi.hoisted(() => ({ start: vi.fn(), options: null as any, wizard: null as any }))

vi.mock('@/composables/useMigrationWizard', () => ({
  useMigrationWizard: (options: unknown) => {
    m.options = options
    return m.wizard
  },
}))
vi.mock('@/api/pohoda', () => ({ pohodaApi: {}, isPohodaUploadReady: () => true }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { ic: '12345678', company_name: 'Syntetická firma' } }) }))
vi.mock('@/components/settings/CompanyProfileBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MoneyS3Protocol.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/exchange/ImportJobProgress.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/ActionBar.vue', () => ({ default: {
  props: ['actions'],
  template: '<div><button v-for="a in actions" :key="a.key" :data-testid="`action-${a.key}`" :disabled="a.disabled" @click="a.run && a.run()">{{ a.label }}</button></div>',
} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, params?: Record<string, unknown>) => params && Object.keys(params).length ? `${key} ${JSON.stringify(params)}` : key,
  te: () => false, tm: () => [], rt: (value: string) => value,
}) }))

const counts = { journal: 10, opening: 1, first_date: '2025-08-01', last_date: '2026-09-10', later_years: [], issued: 1, purchase: 1, internal: 0, cash: 0, bank: 0, partners: 2 }
// Agenda POHODY `<IČO>_2026` vede hospodářský rok 1. 8. 2025 – 31. 7. 2026 a doklad září 2026.
const companyYear = {
  year: 2025, period_start: '2025-08-01', period_end: '2026-07-31',
  later_years: [2026], later_periods: [{ year: 2026, period_start: '2026-08-01', period_end: '2027-07-31' }],
}

function wizard(fiscal: boolean) {
  return {
    currentStep: ref(2), file: ref(null), job: ref(null), jobMode: ref(null), run: ref(null), jobRuns: ref([]), runs: ref([]),
    busy: ref(false), cancelling: ref(false), confirmed: ref(false), dryRunPassed: ref(false), uploadPercent: ref(null),
    processing: ref(false), processingProgress: ref(null), processingSlow: ref(false), loadError: ref(null), deletingRun: ref(null),
    jobRunning: ref(false), jobSucceeded: ref(false), percent: ref(0),
    differencesAcceptable: ref(false), differences: ref([]), acceptDifferences: ref(false), canImport: ref(false), clearDifferences: () => {},
    upload: ref({
      token: 'synthetic', supplier_ico: '12345678', default_year: 2026, preflight: fiscal ? { 2025: [] } : { 2026: [] },
      agendas: [{ ico: '12345678', company: '', year: 2026, has_accounting: true, files: [], counts, stock: null, ...(fiscal ? { company_year: companyYear } : {}) }],
    }),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(), retryUpload: vi.fn(), abandonUpload: vi.fn(),
    start: m.start, cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(), errorMessage: (_e: unknown, fallback: string) => fallback,
  }
}

async function mountPage(fiscal: boolean) {
  m.wizard = wizard(fiscal)
  const wrapper = mount(PohodaMigration, {
    props: { system: 'pohoda' },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
  })
  m.options.onReady()
  await flushPromises()
  return wrapper
}

async function dryRun(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await wrapper.get('[data-testid="action-continue"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-testid="action-dry"]').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
  m.start.mockResolvedValue(undefined)
})

describe('POHODA: roky průvodce podle účetního roku firmy', () => {
  it('hospodářský rok: agenda jako účetní rok firmy s hranicemi a pozdější období, výběr jde rokem firmy', async () => {
    const wrapper = await mountPage(true)
    const label = migrationYearLabel(2025, { period_start: '2025-08-01', period_end: '2026-07-31' })
    expect(label).toContain('2025 (')
    expect(wrapper.get('[data-testid="pohoda-year-label-12345678-2026"]').text()).toBe(label)
    expect(wrapper.get('[data-testid="pohoda-later-year-2026"]').text()).toContain(migrationYearLabel(2026, { period_start: '2026-08-01', period_end: '2027-07-31' }))
    expect(wrapper.get('[data-testid="pohoda-years-selected"]').text()).toBe(`${label}, ${migrationYearLabel(2026, { period_start: '2026-08-01', period_end: '2027-07-31' })}`)
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ years: [2025, 2026], kind: 'accounting' }))
  })

  it('kalendářní firma: rok agendy beze změny', async () => {
    const wrapper = await mountPage(false)
    expect(wrapper.get('[data-testid="pohoda-year-label-12345678-2026"]').text()).toBe('2026')
    expect(wrapper.find('[data-testid="pohoda-later-year-2026"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="pohoda-years-selected"]').text()).toBe('2026')
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ years: [2026] }))
  })
})
