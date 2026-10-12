import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { listPeriods: m.listPeriods, createPeriod: vi.fn() },
}))

vi.mock('@/api/closing', () => ({
  closingApi: { setPeriodStatus: vi.fn() },
  closingSettingsApi: { get: vi.fn().mockResolvedValue({}), update: vi.fn() },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true, can: () => true }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { fiscal_year_start_month: 1 } }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }),
}))

vi.mock('@/composables/useFormat', () => ({ formatDate: (d: string) => d }))

vi.mock('@/composables/useHotkey', () => ({ useHotkey: vi.fn() }))

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: {} }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import Periods from '@/pages/accounting/Periods.vue'

function period(id: number, status: string) {
  return {
    id,
    fiscal_year: 2020 + id,
    starts_on: `${2020 + id}-01-01`,
    ends_on: `${2020 + id}-12-31`,
    status,
    row_version: 1,
  }
}

describe('Periods: schválení závěrky je konečné', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('u schváleného období nenabízí zrušení schválení, jen zámek s vysvětlením', async () => {
    m.listPeriods.mockResolvedValue([period(1, 'approved')])
    const wrapper = await mountPage()
    expect(wrapper.text()).not.toContain('accounting.closing.approve.revoke_button')
    expect(wrapper.text()).not.toContain('accounting.closing.approve.button')
    expect(wrapper.text()).not.toContain('accounting.closing.reopen.button')
    expect(wrapper.find('[title="accounting.closing.approve.locked_hint"]').exists()).toBe(true)
  })

  it('u uzavřeného období nabízí schválení a znovuotevření', async () => {
    m.listPeriods.mockResolvedValue([period(2, 'closed')])
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('accounting.closing.approve.button')
    expect(wrapper.text()).toContain('accounting.closing.reopen.button')
    expect(wrapper.text()).not.toContain('accounting.closing.approve.revoke_button')
  })
})

async function mountPage() {
  const wrapper = mount(Periods, { props: { embedded: true } })
  await flushPromises()
  return wrapper
}
