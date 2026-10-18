import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { AssetDetail, DepreciationPlan } from '@/api/assets'
import AssetEditor from '../AssetEditor.vue'
import AssetDetailPage from '../AssetDetail.vue'

const m = vi.hoisted(() => ({
  get: vi.fn(), plan: vi.fn(), update: vi.fn(), create: vi.fn(), putIntoUse: vi.fn(),
  push: vi.fn(), error: vi.fn(), success: vi.fn(), warning: vi.fn(),
  mode: 'double_entry', loadDimensions: vi.fn(), saveDimensions: vi.fn(),
}))
vi.mock('@/api/assets', () => ({ assetsApi: { get: m.get, plan: m.plan, update: m.update, create: m.create, putIntoUse: m.putIntoUse } }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: async () => [] } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.mode } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error, success: m.success, warning: m.warning }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: unknown) => String(value), formatDate: (value: unknown) => String(value), formatMonth: (value: unknown) => String(value) }))
vi.mock('@/composables/useDocumentDimensions', async () => {
  const { ref } = await import('vue')
  return { useDocumentDimensions: () => ({ enabled: ref(false), header: ref({}), canEdit: ref(true), hasAutoFilled: ref(false), load: m.loadDimensions, save: m.saveDimensions, watchDefaults: vi.fn() }) }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, values?: object) => values ? `${key}:${JSON.stringify(values)}` : key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: '9' }, query: {} }), useRouter: () => ({ push: m.push }) }))

const sourcePlan = { version: 1 as const, source_program: 'Synthetic Ledger', source_tax_method: 'SYN-TAX', source_accounting_method: 'SYN-ACC', accounting: [{ month: '2030-01', amount_cents: 1000 }], tax: [{ fiscal_year: 2030, amount_cents: 1000 }] }
function asset(overrides: Partial<AssetDetail> = {}): AssetDetail {
  return {
    id: 9, supplier_id: 1, inventory_number: 'SYNTHETIC-9', name: 'Synthetic asset', description: null,
    kind: 'intangible', asset_account_code: '013', accumulated_account_code: '073', acquisition_account_code: '041',
    purchase_invoice_id: null, purchase_invoice_item_id: null, input_price: 20, acquisition_date: '2029-12-01',
    put_into_use_date: '2030-01-01', disposal_date: null, disposal_type: null, disposal_price: null,
    status: 'in_use', tax_method: 'by_accounting', tax_group: null, tax_first_year_increase: 'none', is_first_owner: false,
    is_m1_vehicle: false, m1_limit_exception: false, is_zero_emission: false, opening_tax_years: 0, opening_tax_amount: 0,
    opening_acc_months: 0, opening_acc_amount: 0, acc_useful_life_months: null, acc_method: 'straight_line', acc_residual_value: 0,
    created_at: '2030-01-01', updated_at: '2030-01-01', imported_depreciation_plan: sourcePlan,
    locked: { tax_params: false, acquisition: false }, ...overrides,
  }
}
const plan: DepreciationPlan = { asset_summary: { input_price: 20, increased_input_price: 20, tax_residual: 10, acc_residual: 10, accumulated_depreciation: 10 },
  tax: [{ fiscal_year: 2030, amount: 10, full_amount: 10, residual_start: 10, residual_end: 0, is_half: false, is_paused: false, months_count: 1,
    months: [{ month: '2030-01', amount: 10 }], source: 'imported', note: null }], accounting: [] }
const stubs = {
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
  SearchableSelect: { props: ['disabled', 'modelValue'], template: '<input :disabled="disabled" :value="modelValue" data-test="account-select" />' },
  DateInput: { props: ['disabled', 'modelValue'], template: '<input type="date" :disabled="disabled" :value="modelValue" data-test="date-input" />' },
  DimensionFields: true, DocumentDimensionsPanel: true,
  Modal: { template: '<div><slot /></div>' },
  ActionBar: { props: ['actions'], template: '<div><button v-for="action in actions.filter(item => item.show !== false)" :key="action.key" :data-action="action.key" @click="action.run?.()">{{ action.label }}</button></div>' },
}
async function editor() { const wrapper = mount(AssetEditor, { global: { stubs } }); await flushPromises(); return wrapper }
async function detail() { const wrapper = mount(AssetDetailPage, { global: { stubs } }); await flushPromises(); return wrapper }

beforeEach(() => {
  vi.resetAllMocks()
  m.mode = 'double_entry'
  m.get.mockResolvedValue(asset())
  m.plan.mockResolvedValue(plan)
  m.update.mockResolvedValue({ asset: asset(), warnings: [] })
  m.putIntoUse.mockResolvedValue({ asset: asset(), warnings: [] })
})

describe('Imported asset plan UI', () => {
  it.each(['double_entry', 'tax_evidence'])('explains the imported plan and locks plan fields in the %s editor while saving only metadata', async mode => {
    m.mode = mode
    const wrapper = await editor()
    expect(wrapper.get('[data-testid="asset-imported-plan"]').text()).toContain('Synthetic Ledger')
    expect(wrapper.get('[data-testid="asset-imported-plan"]').text()).toContain('SYN-TAX')
    expect(wrapper.findAll('select').every(control => control.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.findAll('input[type="number"]').every(control => control.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.findAll('[data-test="account-select"]').every(control => control.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.findAll('[data-test="date-input"]').every(control => control.attributes('disabled') !== undefined)).toBe(true)
    const fields = wrapper.findAll('input[type="text"]')
    await fields[1]!.setValue('Renamed synthetic asset')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(9, { inventory_number: 'SYNTHETIC-9', name: 'Renamed synthetic asset', description: null })
    expect(m.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('keeps the native editor unlocked when no imported plan exists', async () => {
    m.get.mockResolvedValue(asset({ imported_depreciation_plan: null, acc_useful_life_months: 12 }))
    const wrapper = await editor()
    expect(wrapper.find('[data-testid="asset-imported-plan"]').exists()).toBe(false)
    expect(wrapper.findAll('select').some(control => control.attributes('disabled') === undefined)).toBe(true)
    expect(wrapper.findAll('input[type="number"]').some(control => control.attributes('disabled') === undefined)).toBe(true)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(9, expect.objectContaining({ input_price: 20, tax_method: 'by_accounting', acc_useful_life_months: 12 }))
    wrapper.unmount()
  })

  it('marks imported future rows clearly and hides plan-changing operations while retaining disposal and edit', async () => {
    const wrapper = await detail()
    expect(wrapper.get('[data-testid="asset-imported-plan"]').text()).toContain('SYN-ACC')
    expect(wrapper.text()).toContain('accounting.assets.plan.status_imported')
    expect(wrapper.text()).not.toContain('accounting.assets.plan.status_confirmed')
    expect(wrapper.find('[data-action="improvement"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="pause"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="dispose"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="edit"]').exists()).toBe(true)
    await wrapper.findAll('tr').find(row => row.text().includes('accounting.assets.plan.status_imported'))!.trigger('click')
    expect(wrapper.text()).toContain('2030-01')
    wrapper.unmount()
  })

  it.each([false, true])('hides TZ deletion, tax overrides and pause reversal for imported confirmed rows (paused=%s)', async paused => {
    m.get.mockResolvedValue(asset({ improvements: [{ id: 3, supplier_id: 1, asset_id: 9, completed_on: '2030-01-01', amount: 1, description: 'Synthetic improvement', purchase_invoice_id: null, created_at: '2030-01-01' }] }))
    m.plan.mockResolvedValue({ ...plan, tax: [{ ...plan.tax[0]!, source: 'confirmed', is_paused: paused, override_reason: 'Synthetic adjustment' }] })
    const wrapper = await detail()
    const improvement = wrapper.findAll('tr').find(row => row.text().includes('Synthetic improvement'))
    expect(improvement).toBeDefined()
    expect(improvement!.find('button').exists()).toBe(false)
    expect(wrapper.find('[aria-label="accounting.assets.override.action"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="accounting.assets.override.clear"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="accounting.assets.lifecycle.unpause"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('keeps native lifecycle and tax adjustment controls available', async () => {
    m.get.mockResolvedValue(asset({ imported_depreciation_plan: null, kind: 'tangible', tax_method: 'straight', tax_group: 1, acc_useful_life_months: 12 }))
    m.plan.mockResolvedValue({ ...plan, tax: [{ ...plan.tax[0]!, source: 'confirmed', override_reason: 'Synthetic adjustment' }] })
    const wrapper = await detail()
    expect(wrapper.find('[data-testid="asset-imported-plan"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="improvement"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="pause"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label="accounting.assets.override.action"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label="accounting.assets.override.clear"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('allows an imported draft to activate only on its original date without posting an acquisition', async () => {
    m.get.mockResolvedValue(asset({ status: 'draft' }))
    const wrapper = await detail()
    await wrapper.get('[data-action="put_into_use"]').trigger('click')
    const date = wrapper.get('[data-test="date-input"]')
    expect(date.attributes('disabled')).toBeDefined()
    expect((date.element as HTMLInputElement).value).toBe('2030-01-01')
    const checkbox = wrapper.findAll('input[type="checkbox"]').find(control => control.attributes('disabled') !== undefined)
    expect(checkbox).toBeDefined()
    expect((checkbox!.element as HTMLInputElement).checked).toBe(false)
    const confirm = wrapper.findAll('button').find(button => button.text().includes('common.confirm'))
    expect(confirm).toBeDefined()
    await confirm!.trigger('click')
    await flushPromises()
    expect(m.putIntoUse).toHaveBeenCalledWith(9, { date: '2030-01-01', book_entry: false })
    wrapper.unmount()
  })
})
