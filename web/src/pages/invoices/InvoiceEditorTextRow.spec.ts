import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: () => new Promise(() => {}),
    currencies: vi.fn(),
    units: vi.fn(),
  },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))

import InvoiceEditor from './InvoiceEditor.vue'

async function mountEditor() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/invoices/new', component: InvoiceEditor }],
  })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, createPinia(), createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as {
    form: { currency: string, items: Array<Record<string, unknown>> }
    computed_totals: { without_vat: number, vat: number, with_vat: number, breakdown: Array<{ rate: number }> }
    addTextItem: () => void
  }
  return { wrapper, vm }
}

describe('InvoiceEditor textový řádek', () => {
  it('nepočítá textový řádek do součtů ani do rekapitulace', async () => {
    const { wrapper, vm } = await mountEditor()
    Object.assign(vm.form, {
      currency: 'CZK',
      items: [
        { quantity: 1, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 0 },
        // I kdyby v textovém řádku zůstalo množství a cena, do náhledu nepatří.
        { item_kind: 'text', description: 'Oddíl B', quantity: 2, unit: 'ks', unit_price_without_vat: 50, vat_rate_id: 99 },
      ],
    })
    expect(vm.computed_totals.without_vat).toBe(100)
    expect(vm.computed_totals.breakdown).toHaveLength(1)
    wrapper.unmount()
  })

  it('přidá prázdný textový řádek na konec', async () => {
    const { wrapper, vm } = await mountEditor()
    vm.form.items = [{ description: 'Licence', quantity: 1, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 0, order_index: 0 }]
    vm.addTextItem()
    expect(vm.form.items).toHaveLength(2)
    expect(vm.form.items[1]).toMatchObject({ item_kind: 'text', description: '', quantity: 0, order_index: 1 })
    wrapper.unmount()
  })
})
