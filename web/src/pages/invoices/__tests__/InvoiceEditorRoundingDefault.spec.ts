import { flushPromises, shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import type { InvoiceRoundingMode } from '@/api/invoices'
import type { SupplierBrief } from '@/api/auth'
import { useSupplierStore } from '@/stores/supplier'

// Při tvrdém načtení /invoices/new se firmy z /me načtou až po vytvoření formuláře.
// Editor proto výchozí zaokrouhlení firmy dosadí znovu v onMounted, stejně jako
// režim cen a výchozí poznámku. Všechna API odpoví prázdně.
function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/codebooks', () => ({
  codebooksApi: emptyApi({ currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'CZK', is_default: true }]) }),
}))
vi.mock('@/api/invoices', () => ({ invoicesApi: emptyApi({ previewVarsymbol: vi.fn().mockResolvedValue({}) }) }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: emptyApi() }))
vi.mock('@/api/priceList', () => ({ priceListApi: emptyApi({ list: vi.fn().mockResolvedValue({ data: [] }) }) }))
vi.mock('@/api/cash', () => ({ cashApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))

import InvoiceEditor from '../InvoiceEditor.vue'

describe('InvoiceEditor výchozí zaokrouhlení firmy', () => {
  it('dosadí volbu firmy, i když se firma načte až po vytvoření formuláře', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/invoices/new', component: InvoiceEditor }],
    })
    await router.push('/invoices/new')
    await router.isReady()
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
        directives: { math: {} },
      },
    })
    useSupplierStore().setAvailable(
      [{ id: 1, company_name: 'Syntetická firma', default_rounding_mode: 'whole_czk' } as unknown as SupplierBrief],
      1,
      true,
    )
    await flushPromises()

    const vm = wrapper.vm as unknown as { form: { rounding_mode: InvoiceRoundingMode } }
    expect(vm.form.rounding_mode).toBe('whole_czk')
    wrapper.unmount()
  })
})
