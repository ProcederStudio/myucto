import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
const m = vi.hoisted(() => ({ write: false, id: '7', push: vi.fn(), listItems: vi.fn(), getItem: vi.fn(), createItem: vi.fn(), updateItem: vi.fn(), deleteItem: vi.fn() }))
vi.mock('@/api/stock', () => ({ stockApi: m }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { units: async () => [], vatRates: async () => [] } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.write }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: 'cs' }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ name: 'stock-item-detail', params: { id: m.id } }), useRouter: () => ({ push: m.push }) }))
import ClientItems from '../ClientItems.vue'
const item = { id: 7, sku: 'SYNTHETIC', name: 'Synthetic stock card', item_type: 'goods', unit: 'ks', tracking_mode: 'none', ean: null, vat_rate_id: null, sale_price_without_vat: '100.00', min_qty: '1.000', is_active: true, row_version: 4, note: null, qty: '2.000' }
const options = { global: { stubs: { ActionBar: true, EmptyState: true } } }
describe('delegated client stock cards', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.write = false
    m.id = '7'
    m.getItem.mockResolvedValue(item)
    m.listItems.mockResolvedValue({ data: [item], meta: { pages: 1 } })
    m.updateItem.mockResolvedValue(item)
  })
  it('shows basic catalog quantities without valuation, accounting or e-shop actions', async () => {
    const wrapper = mount(ClientItems, { ...options, props: { mode: 'list' } })
    await flushPromises()
    expect(wrapper.text()).toContain('Synthetic stock card')
    expect(wrapper.text()).toContain('2.000')
    expect(wrapper.text()).not.toContain('stock.items.new_title')
    expect(wrapper.text()).not.toContain('stock.items.col_value')
    expect(m.getItem).not.toHaveBeenCalled()
  })
  it('keeps detail disabled and rejects submitting a reader form', async () => {
    const wrapper = mount(ClientItems, { ...options, props: { mode: 'detail' } })
    await flushPromises()
    expect(wrapper.find('fieldset').attributes()).toHaveProperty('disabled')
    await wrapper.find('form').trigger('submit')
    expect(m.updateItem).not.toHaveBeenCalled()
    expect(m.createItem).not.toHaveBeenCalled()
  })
  it('saves only basic editable fields and the loaded concurrency version', async () => {
    m.write = true
    const wrapper = mount(ClientItems, { ...options, props: { mode: 'editor' } })
    await flushPromises()
    expect(wrapper.find('fieldset').attributes()).not.toHaveProperty('disabled')
    await wrapper.find('input[maxlength="255"]').setValue('Synthetic changed card')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.updateItem).toHaveBeenCalledWith(7, expect.objectContaining({ name: 'Synthetic changed card', row_version: 4 }))
    const payload = m.updateItem.mock.calls[0]![1]
    expect(payload).not.toHaveProperty('revenue_account_code')
    expect(payload).not.toHaveProperty('expense_account_code')
    expect(payload).not.toHaveProperty('export_eshop')
    expect(payload).not.toHaveProperty('dimension_allocations')
  })
})
