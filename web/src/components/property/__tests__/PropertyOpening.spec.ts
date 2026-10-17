import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { reactive } from 'vue'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import PropertyOpeningView from '@/views/PropertyOpeningView.vue'
const calls = vi.hoisted(() => ({ list: vi.fn(), preview: vi.fn(), confirm: vi.fn() }))
vi.mock('@/api/propertyOpening', () => ({ propertyOpeningApi: calls }))
const store = reactive({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
const actions = { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' }
const request = { mode: 'post_new', accounting_date: '2026-01-01', effective_at: '2026-01-01T00:00:00+01:00', expected_revision: 0, evidence_ref: 'Synthetic title', reason: 'Synthetic opening', items: [{ kind: 'credit', case_ref: 'credit', occurrence_ref: '2026', unit_id: 1, components: [{ key: 'credit', role: 'credit', account_code: '379.910', amount_cents: '10000' }] }] }
const view = () => mount(PropertyOpeningView, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })], stubs: { ActionBar: actions, DateInput: true, PropertyInstantInput: true } } })
async function upload(wrapper: ReturnType<typeof view>, value: unknown) {
 const input = wrapper.get('input[type=file]'); Object.defineProperty(input.element, 'files', { configurable: true, value: [{ name: 'opening.json', size: 100, text: async () => JSON.stringify(value) }] }); await input.trigger('change'); await flushPromises()
}
beforeEach(() => { vi.resetAllMocks(); store.currentSupplierId = 1; calls.list.mockResolvedValue({ items: [], capabilities: { manage: true, post: true, revise: true } }); calls.preview.mockResolvedValue({ snapshot: { journal: { lines: [] }, reversal: null }, preview_hash: 'synthetic-hash' }); calls.confirm.mockResolvedValue({}) })
describe('opening takeover', () => {
 it('imports the own format, confirms the exact preview and clears it', async () => {
  const wrapper = view(); await flushPromises(); await upload(wrapper, { format: 'myucto.property.opening.v1', request }); await wrapper.get('[data-action=preview]').trigger('click'); await flushPromises(); await wrapper.get('[data-action=confirm]').trigger('click'); await flushPromises(); expect(calls.confirm).toHaveBeenCalledWith(1, expect.objectContaining(request), 'synthetic-hash', expect.any(String)); expect(wrapper.find('[data-action=confirm]').exists()).toBe(false); wrapper.unmount()
 })
 it('rejects malformed component arrays before rendering', async () => {
  const wrapper = view(); await flushPromises(); await upload(wrapper, { format: 'myucto.property.opening.v1', request: { ...request, items: [{ ...request.items[0], components: null }] } }); expect(wrapper.get('[role=alert]').text()).toContain('invalidFile'); expect(calls.preview).not.toHaveBeenCalled(); wrapper.unmount()
 })
 it('discards an old supplier preview and respects revoked posting permission', async () => {
  let resolve!: (value: unknown) => void; calls.preview.mockReturnValue(new Promise(done => { resolve = done })); const wrapper = view(); await flushPromises(); await upload(wrapper, { format: 'myucto.property.opening.v1', request }); await wrapper.get('[data-action=preview]').trigger('click'); calls.list.mockResolvedValue({ items: [], capabilities: { manage: true, post: false, revise: false } }); store.currentSupplierId = 2; await flushPromises(); resolve({ snapshot: { journal: { lines: [] } }, preview_hash: 'old' }); await flushPromises(); expect(wrapper.find('[data-action=confirm]').exists()).toBe(false); calls.preview.mockResolvedValue({ snapshot: { journal: { lines: [] } }, preview_hash: 'new' }); await wrapper.get('[data-action=preview]').trigger('click'); await flushPromises(); expect(wrapper.get<HTMLButtonElement>('[data-action=confirm]').element.disabled).toBe(true); expect(calls.confirm).not.toHaveBeenCalled(); wrapper.unmount()
 })
})
