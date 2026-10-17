import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import Page from '@/pages/accounting/NonprofitTaxAssessment.vue'
const mocks = vi.hoisted(() => ({ read: vi.fn(), history: vi.fn(), preview: vi.fn(), confirm: vi.fn(), withdraw: vi.fn(), export: vi.fn(), document: vi.fn(), can: vi.fn() }))
vi.mock('@/api/nonprofitTax', () => ({ nonprofitTaxApi: mocks }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: '7' } }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ can: mocks.can }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn() }) }))
vi.mock('@/workspace/supplierContext', () => ({ requestSupplierId: () => '22' }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (error: Error) => error.message }))
const snapshot = { period: { id: 7, fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31' }, input_hash: 'current-hash', sources: [], findings: [], funding_reference_sources: {}, accounting_result_cents: 0 }
const result = { accounting_result_cents: 13040000, excluded_revenue_cents: 10940000, cost_addbacks_cents: 7900000, assessed_result_cents: 10000000, activities: [], rows: [], funding_usage: [], external_adjustments: [] }
const response = { snapshot, result, previous_assessment_id: 9 }
const ActionBar = defineComponent({ props: ['actions'], template: '<div><template v-for="action in actions" :key="action.key"><button v-if="action.show !== false" :data-action="action.key" :disabled="action.disabled" @click="action.run">{{ action.label }}</button></template></div>' })
function render() { return mount(Page, { global: { stubs: { ActionBar, FundingEditor: true, DateInput: true } } }) }
beforeEach(() => {
  vi.clearAllMocks()
  mocks.can.mockReturnValue(true)
  mocks.read.mockResolvedValue({ snapshot, latest: null })
  mocks.history.mockResolvedValue([])
  mocks.preview.mockResolvedValue(response)
  mocks.confirm.mockResolvedValue({ id: 10 })
})
describe('nonprofit assessment workflow', () => {
  it('loads sources readonly, shows exact bridge and confirms the preview revision/hash without a client actor', async () => {
    const wrapper = render()
    await flushPromises()
    expect(mocks.confirm).not.toHaveBeenCalled()
    expect(mocks.withdraw).not.toHaveBeenCalled()
    await wrapper.get('select').setValue('narrow_vpp')
    await wrapper.findAll('textarea')[0]!.setValue('Documented profile')
    await wrapper.get('[data-action="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="assessed_result_cents"]').text()).toContain('100000.00')
    expect(wrapper.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    await wrapper.findAll('textarea')[1]!.setValue('Own evidenced decision')
    await wrapper.get('input[type="checkbox"]').setValue(true)
    await wrapper.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.confirm).toHaveBeenCalledWith(7, expect.objectContaining({ input_hash: 'current-hash', expected_assessment_id: 9, reason: 'Own evidenced decision', idempotency_key: expect.any(String) }))
    expect(mocks.confirm.mock.calls[0]![1]).not.toHaveProperty('actor')
    wrapper.unmount()
  })
  it('discards a late preview after evidence changes', async () => {
    let resolve!: (value: typeof response) => void
    mocks.preview.mockReturnValue(new Promise(value => { resolve = value }))
    const wrapper = render()
    await flushPromises()
    await wrapper.get('[data-action="preview"]').trigger('click')
    await wrapper.findAll('textarea')[0]!.setValue('Changed while preview was pending')
    resolve(response)
    await flushPromises()
    expect(wrapper.find('[data-action="confirm"]').exists()).toBe(false)
    expect(mocks.confirm).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('invalidates a completed preview when facts change', async () => {
    const wrapper = render()
    await flushPromises()
    await wrapper.get('[data-action="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-action="confirm"]').exists()).toBe(true)
    await wrapper.findAll('textarea')[0]!.setValue('A different factual conclusion')
    expect(wrapper.find('[data-action="confirm"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('allows readonly preview without exposing confirmation', async () => {
    mocks.can.mockReturnValue(false)
    const wrapper = render()
    await flushPromises()
    await wrapper.get('[data-action="preview"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-action="confirm"]').exists()).toBe(false)
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
