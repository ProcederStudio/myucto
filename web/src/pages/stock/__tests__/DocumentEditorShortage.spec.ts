import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

// Zaúčtování skladového dokladu při nedostatku zásob: jen okno s výčtem,
// žádný chybový pruh nad formulářem ani toast.

const m = vi.hoisted(() => ({
  createDocument: vi.fn(),
  postDocument: vi.fn(),
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}))

vi.mock('@/api/stock', async importOriginal => ({
  ...await importOriginal<typeof import('@/api/stock')>(),
  stockApi: new Proxy({ createDocument: m.createDocument, postDocument: m.postDocument } as Record<string, unknown>, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m.toast }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import DocumentEditor from '../DocumentEditor.vue'

afterEach(() => { vi.clearAllMocks() })

describe('DocumentEditor: nedostatek zásob', () => {
  it('při zaúčtování otevře okno bez chybového pruhu a bez toastu', async () => {
    const items = [{ stock_item_id: 7, sku: 'SR-10', name: 'Šroub', requested: '5.000', available: '2.000', warehouse_id: 3, warehouse_name: 'Prodejna', unit: 'ks' }]
    m.createDocument.mockResolvedValue({ id: 5, status: 'draft' })
    m.postDocument.mockRejectedValue({ response: { status: 409, data: { error: { code: 'stock.error.insufficient_stock', message: 'Nedostatek', items } } } })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
    await router.push('/stock/documents/new?doc_type=issue')
    const wrapper = mount({ ...DocumentEditor, render: () => null }, { global: { plugins: [router] } })
    await flushPromises()
    const vm = wrapper.vm as unknown as {
      form: { warehouse_id: number | null; description: string }
      lines: Array<{ stock_item_id: number | null; qty: string }>
      post: () => Promise<void>
      error: string
      insufficientStock: unknown[] | null
    }
    vm.form.warehouse_id = 3
    vm.form.description = 'Výdej'
    vm.lines[0].stock_item_id = 7
    vm.lines[0].qty = '5'
    await vm.post()
    await flushPromises()
    expect(vm.insufficientStock).toEqual(items)
    expect(vm.error).toBe('')
    expect(m.toast.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
