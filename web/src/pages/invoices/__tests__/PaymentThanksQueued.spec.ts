import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { nextTick, ref } from 'vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  markPaid: vi.fn(),
  success: vi.fn(),
  defaultQuery: null as Record<string, string> | null,
  apply: null as ((query: Record<string, string>) => void) | null,
}))
vi.mock('@/api/invoices', () => ({ invoicesApi: { listGrouped: mocks.list, markPaid: mocks.markPaid } }))
vi.mock('@/api/clients', () => ({ clientsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: async () => [] } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: async () => [] } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { payment_thanks_enabled: true } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), warning: vi.fn(), success: mocks.success }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [] }))
vi.mock('@/composables/useListKeyboard', () => ({ useListKeyboard: () => ({ activeIndex: -1 }) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined), getPagePrefs: () => ref({}) }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: () => ({
  sort: ref(null), flag: () => true, isVisible: () => false,
}) }))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: (_page: string, opts: { applyQuery: (query: Record<string, string>) => void }) => {
    mocks.apply = opts.applyQuery
    return {
      applyDefaultIfAny: async () => {
        if (!mocks.defaultQuery) return false
        opts.applyQuery(mocks.defaultQuery)
        return true
      },
    }
  },
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => key + (params ? JSON.stringify(params) : '') }),
}))
import InvoiceList from '../InvoiceList.vue'

async function open(query: Record<string, string> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices', component: { render: () => null } }] })
  await router.push({ path: '/invoices', query })
  const wrapper = mount({ ...InvoiceList, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('payment thanks queued response', () => {
  afterEach(() => { vi.unstubAllGlobals() })

  it('counts queued thanks separately from sent messages', async () => {
    mocks.list.mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
    mocks.markPaid.mockResolvedValue({ payment_thanks: { status: 'queued', reason: 'awaiting_commit' } })
    vi.stubGlobal('confirm', () => true)
    const { wrapper } = await open()
    const vm = wrapper.vm as unknown as {
      groups: unknown[]; selectedIds: number[]; bulkMarkPaid: () => Promise<void>
    }
    vm.groups = [{ invoices: [{ id: 101, status: 'issued', invoice_type: 'invoice', amount_to_pay: 49.99, total_with_vat: 49.99 }] }]
    vm.selectedIds = [101]
    await nextTick()
    await vm.bulkMarkPaid()
    expect(mocks.markPaid).toHaveBeenCalledTimes(1)
    expect(mocks.success).toHaveBeenCalledWith(expect.stringContaining('invoice.bulk_thanks_queued{"n":1}'))
    expect(mocks.success).toHaveBeenCalledWith(expect.stringContaining('invoice.bulk_thanks_summary{"sent":0,"failed":0}'))
    wrapper.unmount()
  })
})
