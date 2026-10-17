import { nextTick } from 'vue'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'

// Rychlé zadávání položek faktury: výběr karty → množství, Enter v množství →
// další řádek (nebo nový), stav skladu v nabídce karet podle skladu řádku.

const m = vi.hoisted(() => ({
  focusRowField: vi.fn(async () => true),
  focusLastRow: vi.fn(),
  searchItems: vi.fn(),
}))

vi.mock('@/composables/useRowFocus', () => ({ focusRowField: m.focusRowField, focusLastRow: m.focusLastRow }))
vi.mock('@/api/codebooks', () => ({
  codebooksApi: { vatRates: () => new Promise(() => {}), currencies: vi.fn(), units: vi.fn() },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))
vi.mock('@/api/stock', async importOriginal => {
  const actual = await importOriginal<typeof import('@/api/stock')>()
  return {
    ...actual,
    stockApi: new Proxy({ searchItems: m.searchItems } as Record<string, unknown>, {
      get: (target, key: string) => (key in target ? target[key] : vi.fn(() => new Promise(() => {}))),
    }),
  }
})

import InvoiceEditor from './InvoiceEditor.vue'

type Row = { item_kind?: string; description: string; quantity: number | string; warehouse_id: number | null; stock_item_id: number | null }
type Vm = {
  form: { items: Row[] }
  onQtyEnter: (e: KeyboardEvent, i: number) => void
  onStockSelect: (i: number, id: number | null) => void
  onStockSearch: (i: number, q: string) => Promise<void>
  stockRowOptions: Record<number, Array<{ value: number; badge?: string; badgeTone?: string }>>
}

async function mountEditor() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/new', component: InvoiceEditor }] })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, createPinia(), createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as Vm
  vm.form.items.splice(0, vm.form.items.length,
    { description: 'A', quantity: 1, warehouse_id: 4, stock_item_id: null } as Row,
    { description: 'B', quantity: 1, warehouse_id: null, stock_item_id: null } as Row,
  )
  await nextTick()
  m.focusRowField.mockClear()
  m.focusLastRow.mockClear()
  return { wrapper, vm }
}

function enter(): KeyboardEvent {
  return new KeyboardEvent('keydown', { key: 'Enter', cancelable: true })
}

afterEach(() => { vi.clearAllMocks() })

describe('InvoiceEditor rychlé zadávání', () => {
  it('Enter v množství neuloží formulář a skočí na popis dalšího řádku', async () => {
    const { wrapper, vm } = await mountEditor()
    const e = enter()
    vm.onQtyEnter(e, 0)
    expect(e.defaultPrevented).toBe(true)
    expect(m.focusRowField).toHaveBeenCalledWith('[data-row-input="inv-item"]', 1, expect.anything())
    expect(vm.form.items).toHaveLength(2)
    wrapper.unmount()
  })

  it('Enter v množství posledního řádku přidá nový řádek', async () => {
    const { wrapper, vm } = await mountEditor()
    const e = enter()
    vm.onQtyEnter(e, 1)
    expect(e.defaultPrevented).toBe(true)
    expect(vm.form.items).toHaveLength(3)
    expect(m.focusLastRow).toHaveBeenCalledWith('[data-row-input="inv-item"]', expect.anything())
    wrapper.unmount()
  })

  it('Enter v množství prázdného posledního řádku další prázdný řádek nepřidá', async () => {
    const { wrapper, vm } = await mountEditor()
    vm.form.items[1].description = '  '
    const e = enter()
    vm.onQtyEnter(e, 1)
    expect(e.defaultPrevented).toBe(true)
    expect(vm.form.items).toHaveLength(2)
    expect(m.focusLastRow).not.toHaveBeenCalled()

    // Řádek s kartou bez popisu prázdný není.
    vm.form.items[1].stock_item_id = 77
    vm.onQtyEnter(enter(), 1)
    expect(vm.form.items).toHaveLength(3)
    wrapper.unmount()
  })

  it('neplatná doba v množství: Enter ukáže upozornění a fokus nechá', async () => {
    const { wrapper, vm } = await mountEditor()
    const input = document.createElement('input')
    input.setCustomValidity('Neplatná doba')
    const report = vi.spyOn(input, 'reportValidity')
    input.addEventListener('keydown', ev => vm.onQtyEnter(ev as KeyboardEvent, 0))
    const e = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true })
    input.dispatchEvent(e)
    expect(e.defaultPrevented).toBe(true)
    expect(report).toHaveBeenCalled()
    expect(m.focusRowField).not.toHaveBeenCalled()
    expect(vm.form.items).toHaveLength(2)
    wrapper.unmount()
  })

  it('výběr karty přesune fokus na množství téhož řádku, odpojení ne', async () => {
    const { wrapper, vm } = await mountEditor()
    vm.onStockSelect(1, 77)
    expect(m.focusRowField).toHaveBeenCalledWith('[data-row-qty="inv-item"]', 1, expect.anything())
    m.focusRowField.mockClear()
    vm.onStockSelect(1, null)
    expect(m.focusRowField).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('textový řádek mezi položkami neposune fokus na pole jiného řádku', async () => {
    const { wrapper, vm } = await mountEditor()
    vm.form.items.splice(1, 0, { item_kind: 'text', description: 'Oddíl B', quantity: 0, warehouse_id: null, stock_item_id: null } as Row)
    vm.onQtyEnter(enter(), 0)
    expect(m.focusRowField).toHaveBeenCalledWith('[data-row-input="inv-item"]', 1, expect.anything())
    m.focusRowField.mockClear()
    vm.onStockSelect(2, 77)
    expect(m.focusRowField).toHaveBeenCalledWith('[data-row-qty="inv-item"]', 1, expect.anything())

    vm.form.items.push({ item_kind: 'text', description: 'Závěr', quantity: 0, warehouse_id: null, stock_item_id: null } as Row)
    vm.onQtyEnter(enter(), 2)
    expect(vm.form.items).toHaveLength(5)
    expect(m.focusLastRow).toHaveBeenCalledWith('[data-row-input="inv-item"]', expect.anything())
    wrapper.unmount()
  })

  it('nabídka karet se ptá na stav ve skladu řádku a nulu označí varováním', async () => {
    const { wrapper, vm } = await mountEditor()
    m.searchItems.mockResolvedValue([
      { id: 7, sku: 'SR', name: 'Šroub', unit: 'ks', vat_rate_id: null, sale_price_without_vat: null, is_stocked: true, qty: '12.000' },
      { id: 8, sku: 'MT', name: 'Matice', unit: 'ks', vat_rate_id: null, sale_price_without_vat: null, is_stocked: true, qty: '0.000' },
      { id: 9, sku: 'MO', name: 'Montáž', unit: 'h', vat_rate_id: null, sale_price_without_vat: null, is_stocked: false, qty: null },
    ])
    await vm.onStockSearch(0, 'S')
    await flushPromises()
    expect(m.searchItems).toHaveBeenCalledWith('S', 30, 4)
    const [ok, zero, service] = vm.stockRowOptions[0]
    expect(ok.badge).toBeTruthy()
    expect(ok.badgeTone).toBe('neutral')
    expect(zero.badgeTone).toBe('warning')
    expect(service.badge).toBeUndefined()
    wrapper.unmount()
  })
})
