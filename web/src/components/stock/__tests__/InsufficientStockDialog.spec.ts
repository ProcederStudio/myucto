import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const auth = vi.hoisted(() => ({ canWriteDocuments: true, canReadStock: true }))

vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (perm: string) => perm === 'stock.documents.write' && auth.canWriteDocuments,
    canRead: (perm: string) => perm === 'stock' && auth.canReadStock,
  }),
}))

import InsufficientStockDialog from '../InsufficientStockDialog.vue'
import { insufficientStockItems } from '../insufficientStock'

const items = [
  { stock_item_id: 7, sku: 'SR-10', name: 'Šroub M10', requested: '5.000', available: '2.000', warehouse_id: 3, warehouse_name: 'Prodejna', unit: 'ks' },
  { stock_item_id: 8, sku: 'MT-10', name: 'Matice M10', requested: '1.500', available: '0.000', warehouse_id: 3, warehouse_name: 'Prodejna', unit: 'kg' },
]

let wrapper: VueWrapper | null = null
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.replaceChildren()
  auth.canWriteDocuments = true
  auth.canReadStock = true
})

async function mountDialog() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/invoices/1')
  wrapper = mount(InsufficientStockDialog, { attachTo: document.body, props: { items }, global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('insufficientStockItems', () => {
  it('vytáhne položky z chyby vystavení faktury i skladového dokladu', () => {
    const issue = { response: { data: { error: { code: 'stock.error.insufficient_stock', items } } } }
    const document = { response: { data: { error: { code: 'insufficient_stock', details: items } } } }
    expect(insufficientStockItems(issue)).toEqual(items)
    expect(insufficientStockItems(document)).toEqual(items)
  })

  it('jiná chyba nebo prázdný výčet okno neotevře', () => {
    expect(insufficientStockItems({ response: { data: { error: { code: 'not_draft' } } } })).toBeNull()
    expect(insufficientStockItems({ response: { data: { error: { code: 'stock.error.insufficient_stock', items: [] } } } })).toBeNull()
    expect(insufficientStockItems(new Error('network'))).toBeNull()
  })
})

describe('InsufficientStockDialog', () => {
  it('je přístupný dialog s názvem a fokusem na zavření', async () => {
    await mountDialog()
    const dialog = document.body.querySelector('[role="dialog"]')!
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    const labelId = dialog.getAttribute('aria-labelledby')!
    expect(document.getElementById(labelId)?.textContent).toBe('stock.insufficient.title')
    expect(document.activeElement?.getAttribute('data-test')).toBe('insufficient-stock-close')
  })

  it('vypíše kód, název, sklad, požadované, dostupné a chybějící množství s jednotkou', async () => {
    await mountDialog()
    const rows = Array.from(document.body.querySelectorAll('[data-test="insufficient-stock-row"]'))
    // Desktop tabulka i mobilní seznam: každá položka dvakrát.
    expect(rows).toHaveLength(4)
    const text = rows[0].textContent ?? ''
    expect(text).toContain('SR-10')
    expect(text).toContain('Šroub M10')
    expect(text).toContain('Prodejna')
    expect(text).toContain('5')
    expect(text).toContain('2')
    expect(text).toContain('3')
    expect(text).toContain('ks')
    expect(rows[1].textContent).toContain('1,5')
    expect(rows[1].textContent).toContain('kg')
  })

  it('odkáže na kartu a na novou příjemku do skladu řádku; odkaz okno zavře', async () => {
    const w = await mountDialog()
    const links = Array.from(document.body.querySelectorAll<HTMLAnchorElement>('[data-test="insufficient-stock-row"] a'))
    const hrefs = links.map(a => a.getAttribute('href'))
    expect(hrefs).toContain('/stock/items/7')
    expect(hrefs).toContain('/stock/documents/new?doc_type=receipt&stock_item_id=7&warehouse_id=3')
    links[0].click()
    await flushPromises()
    expect(w.emitted('close')).toHaveLength(1)
  })

  it('bez práva zapisovat skladové doklady příjemku nenabízí', async () => {
    auth.canWriteDocuments = false
    await mountDialog()
    const hrefs = Array.from(document.body.querySelectorAll('a')).map(a => a.getAttribute('href'))
    expect(hrefs.some(h => h?.startsWith('/stock/documents/new'))).toBe(false)
    expect(hrefs).toContain('/stock/items/7')
  })

  it('bez práva číst sklad odkaz na kartu nenabízí', async () => {
    auth.canReadStock = false
    await mountDialog()
    const hrefs = Array.from(document.body.querySelectorAll('a')).map(a => a.getAttribute('href'))
    expect(hrefs.some(h => h?.startsWith('/stock/items/'))).toBe(false)
  })

  it('Zavřít i Escape okno zavřou', async () => {
    const w = await mountDialog()
    ;(document.body.querySelector('[data-test="insufficient-stock-close"]') as HTMLButtonElement).click()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()
    expect(w.emitted('close')).toHaveLength(2)
  })
})
