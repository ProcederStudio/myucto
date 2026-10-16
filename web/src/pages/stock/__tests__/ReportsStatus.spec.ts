import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  reportStatus: vi.fn(),
  reportExportUrl: vi.fn(),
  listWarehouses: vi.fn(),
  listCategories: vi.fn(),
  listAttributes: vi.fn(),
  hidden: new Set<string>(),
  readable: new Set<string>(['eshop']),
}))

vi.mock('@/api/stock', () => ({
  stockApi: {
    reportStatus: m.reportStatus,
    reportExportUrl: m.reportExportUrl,
    listWarehouses: m.listWarehouses,
  },
}))
vi.mock('@/api/eshop', () => ({ eshopApi: { listCategories: m.listCategories, listAttributes: m.listAttributes } }))
vi.mock('@/api/catalogJobs', () => ({ catalogJobsApi: {} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: (key: string) => m.readable.has(key), canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => String(value), formatDate: (value: string) => value, formatNumber: (value: number) => String(value),
}))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: (_key: string, columns: Array<{ key: string; defaultHidden?: boolean }>) => ({
    columns,
    isVisible: (key: string) => !m.hidden.has(key),
    densityClass: ref(''),
  }),
}))
vi.mock('@/components/ui/buttonStyles', () => ({ ICONS: new Proxy({}, { get: () => 'M0 0' }), btnOutline: () => '' }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), RouterLink: { template: '<a><slot /></a>' } }))

import Reports from '../Reports.vue'

const stubs = {
  ClientSearchSelect: true, PaginationBar: true, EmptyState: true, DateInput: true, CatalogJobProgress: true, ColumnPicker: true,
  RouterLink: { template: '<a><slot /></a>' },
}

function row(over: Record<string, unknown> = {}) {
  return {
    warehouse_id: 1, stock_item_id: 7, qty: '1.000', value_total: '10.00', avg_unit_cost: '10.000000', sku: 'A-1', name: 'Karta',
    unit: 'ks', item_type: 'goods', min_qty: null, is_active: true, warehouse_code: 'HL', warehouse_name: 'Hlavní', ...over,
  }
}

describe('Reports – stav zásob', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.hidden = new Set(['sale_price', 'note'])
    m.readable = new Set(['eshop'])
    m.listWarehouses.mockResolvedValue([])
    m.listCategories.mockResolvedValue([{ id: 12, name: 'Motocykly', path: '/12/', depth: 0, archived: false }])
    m.listAttributes.mockResolvedValue([{ id: 14, name: 'VIN', unit: null, data_type: 'text', archived: false }])
    m.reportStatus.mockResolvedValue({ items: [row()], totals: { value_total: '10.00', count: 1 } })
    m.reportExportUrl.mockReturnValue('/export')
  })

  it('keeps the default request without new parameters', async () => {
    mount(Reports, { global: { stubs } })
    await flushPromises()
    const params = m.reportStatus.mock.calls[0][0]
    expect(params.category_id).toBeUndefined()
    expect(params.columns).toBeUndefined()
    expect(params.attribute_id).toBeUndefined()
  })

  it('sends category, chosen columns and parameter to data and export', async () => {
    m.hidden = new Set(['avg_cost'])
    m.reportStatus.mockResolvedValue({
      items: [row({ sale_price: '459900.00', note: 'Vitrína', attribute_value: 'VIN-1' })],
      totals: { value_total: '10.00', count: 1 },
      columns: ['value_total', 'sale_price', 'note', 'attribute'],
      attribute: { id: 14, code: 'VIN', name: 'VIN', unit: null, data_type: 'text' },
    })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()

    await wrapper.find('[data-test="status-category"]').setValue('12')
    await wrapper.find('[data-test="status-attribute"]').setValue('14')
    await flushPromises()

    const params = m.reportStatus.mock.calls.at(-1)![0]
    expect(params).toMatchObject({ category_id: 12, columns: 'value_total,sale_price,note', attribute_id: 14 })
    const text = wrapper.text()
    expect(text).toContain('459900')
    expect(text).toContain('Vitrína')
    expect(text).toContain('VIN-1')
    expect(text).not.toContain('stock.reports.col_avg_cost')

    await wrapper.findAll('button').find(b => b.text().includes('stock.reports.export_xlsx'))!.trigger('click')
    expect(m.reportExportUrl).toHaveBeenCalledWith('status', 'xlsx', expect.objectContaining({
      category_id: 12, columns: 'value_total,sale_price,note', attribute_id: 14,
    }))
    expect(open).toHaveBeenCalledWith('/export', '_blank', 'noopener')
    open.mockRestore()
  })

  it('does not call catalog codebooks without eshop read permission', async () => {
    m.readable = new Set()
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()
    expect(m.listCategories).not.toHaveBeenCalled()
    expect(m.listAttributes).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="status-category"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="status-attribute"]').exists()).toBe(false)
  })

  it('translates a yes/no parameter in the table', async () => {
    m.reportStatus.mockResolvedValue({
      items: [row({ attribute_value: 'true' }), row({ stock_item_id: 8, attribute_value: 'false' })],
      totals: { value_total: '20.00', count: 2 },
      columns: ['avg_unit_cost', 'value_total', 'attribute'],
      attribute: { id: 15, code: 'NOVY', name: 'Nový', unit: null, data_type: 'bool' },
    })
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()
    const cells = wrapper.findAll('tbody td').map(td => td.text())
    expect(cells).toContain('common.yes')
    expect(cells).toContain('common.no')
    expect(cells).not.toContain('true')
  })

  it('asks for no optional columns explicitly when all are hidden', async () => {
    m.hidden = new Set(['avg_cost', 'value', 'sale_price', 'note'])
    mount(Reports, { global: { stubs } })
    await flushPromises()
    expect(m.reportStatus.mock.calls[0][0].columns).toBe('none')
  })
})
