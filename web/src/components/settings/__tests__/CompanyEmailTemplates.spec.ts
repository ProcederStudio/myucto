import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import type { CompanyEmailTemplate } from '@/api/companyEmailTemplates'

const m = vi.hoisted(() => ({ list: vi.fn(), get: vi.fn(), save: vi.fn(), reset: vi.fn(), preview: vi.fn(), factory: vi.fn(), supplier: undefined as any, success: vi.fn() }))
vi.mock('@/api/companyEmailTemplates', () => ({ companyEmailTemplatesApi: m.factory }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.supplier }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ te: () => false, t: (key: string, params?: unknown) => params ? `${key} ${JSON.stringify(params)}` : key }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success }) }))
import CompanyEmailTemplates from '../CompanyEmailTemplates.vue'

const item = { code: 'invoice_send', locale: 'en', source: 'global' as const, resolved_locale: 'en', has_override: false, updated_at: null }
const detail: CompanyEmailTemplate = { ...item, subject: 'Shared subject', body_html: '<p>{{ invoice.number }}</p>', body_text: '{{ invoice.number }}', variables: { 'invoice.number': 'Invoice number' } }
function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(r => { resolve = r })
  return { promise, resolve }
}
async function opened(override = false) {
  m.get.mockResolvedValue({ ...detail, has_override: override })
  const wrapper = mount(CompanyEmailTemplates, { props: { clientScoped: true } })
  await flushPromises()
  await wrapper.get('[data-test="template-edit"]').trigger('click')
  await flushPromises()
  return wrapper
}
beforeEach(() => {
  vi.resetAllMocks()
  m.supplier = reactive({ currentSupplierId: 1, currentSupplier: { company_name: 'Synthetic Company A' } })
  m.factory.mockReturnValue({ list: m.list, get: m.get, save: m.save, reset: m.reset, preview: m.preview })
  m.list.mockImplementation(() => Promise.resolve({ data: [{ ...item }], supplier_id: m.supplier.currentSupplierId }))
  m.save.mockResolvedValue({})
  m.reset.mockResolvedValue({})
})
describe('CompanyEmailTemplates', () => {
  it('opens inherited content without writes and isolates an unsaved synthetic preview', async () => {
    const wrapper = await opened()
    expect(wrapper.text()).toContain('Synthetic Company A')
    expect(wrapper.text()).toContain('invoice.number')
    expect(m.save).not.toHaveBeenCalled()
    expect(m.reset).not.toHaveBeenCalled()
    await wrapper.get('[data-test="template-subject"]').setValue('Unsaved subject')
    m.preview.mockResolvedValue({ subject: 'Unsaved subject', body_html: '<script>parent.alert(1)</script><img src="https://example.invalid/pixel">', body_text: 'Synthetic invoice' })
    await wrapper.get('[data-test="template-preview"]').trigger('click')
    await flushPromises()
    expect(m.preview).toHaveBeenCalledWith('invoice_send', 'en', { subject: 'Unsaved subject', body_html: detail.body_html, body_text: detail.body_text })
    const iframe = wrapper.get('iframe')
    expect(iframe.attributes('sandbox')).toBe('')
    expect(iframe.attributes('srcdoc')).toContain("default-src 'none'")
    expect(iframe.attributes('srcdoc')).toContain("form-action 'none'")
    expect(m.save).not.toHaveBeenCalled()
    await wrapper.get('[data-test="template-subject"]').setValue('New edit')
    expect(wrapper.find('iframe').exists()).toBe(false)
    wrapper.unmount()
  })
  it('saves only editable fields in the captured company context', async () => {
    const wrapper = await opened()
    await wrapper.get('[data-test="template-subject"]').setValue('Own subject')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.factory).toHaveBeenLastCalledWith(true, 1)
    expect(m.save).toHaveBeenCalledWith('invoice_send', 'en', { subject: 'Own subject', body_html: detail.body_html, body_text: detail.body_text })
    wrapper.unmount()
  })
  it('requires confirmation before removing an override', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = await opened(true)
    await wrapper.get('[data-test="template-reset"]').trigger('click')
    expect(m.reset).not.toHaveBeenCalled()
    confirm.mockReturnValue(true)
    await wrapper.get('[data-test="template-reset"]').trigger('click')
    await flushPromises()
    expect(m.reset).toHaveBeenCalledWith('invoice_send', 'en')
    confirm.mockRestore()
    wrapper.unmount()
  })
  it('drops an old-company detail response after a supplier switch', async () => {
    const pending = deferred<CompanyEmailTemplate>()
    m.get.mockReturnValue(pending.promise)
    const wrapper = mount(CompanyEmailTemplates)
    await flushPromises()
    await wrapper.get('[data-test="template-edit"]').trigger('click')
    m.supplier.currentSupplierId = 2
    m.supplier.currentSupplier.company_name = 'Synthetic Company B'
    await flushPromises()
    pending.resolve(detail)
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('Synthetic Company B')
    expect(m.save).not.toHaveBeenCalled()
    expect(m.factory).toHaveBeenLastCalledWith(false, 2)
    wrapper.unmount()
  })
  it('keeps the new company editor when an old-company save finishes', async () => {
    const wrapper = await opened()
    const pending = deferred<unknown>()
    m.save.mockReturnValue(pending.promise)
    await wrapper.get('form').trigger('submit')
    m.supplier.currentSupplierId = 2
    await flushPromises()
    m.get.mockResolvedValue({ ...detail, subject: 'Company B' })
    await wrapper.get('[data-test="template-edit"]').trigger('click')
    await flushPromises()
    pending.resolve({})
    await flushPromises()
    expect((wrapper.get('[data-test="template-subject"]').element as HTMLInputElement).value).toBe('Company B')
    expect(m.success).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('drops stale list responses even when the supplier switches back', async () => {
    const first = deferred<{ data: typeof item[]; supplier_id: number }>()
    m.list.mockReturnValueOnce(first.promise)
    const wrapper = mount(CompanyEmailTemplates)
    m.supplier.currentSupplierId = 2
    await nextTick()
    m.supplier.currentSupplierId = 1
    await flushPromises()
    first.resolve({ data: [{ ...item, code: 'password_reset' }], supplier_id: 1 })
    await flushPromises()
    expect(wrapper.text()).toContain('invoice_send')
    expect(wrapper.text()).not.toContain('password_reset')
    wrapper.unmount()
  })
  it('keeps the draft after server validation failure', async () => {
    const wrapper = await opened()
    m.save.mockRejectedValue({ response: { data: { error: { message: 'Synthetic validation error' } } } })
    await wrapper.get('[data-test="template-subject"]').setValue('Invalid draft')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Synthetic validation error')
    expect((wrapper.get('[data-test="template-subject"]').element as HTMLInputElement).value).toBe('Invalid draft')
    expect(m.success).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('drops pending preview data after switching company', async () => {
    const wrapper = await opened()
    const pending = deferred<{subject: string; body_html: string; body_text: string}>()
    m.preview.mockReturnValue(pending.promise)
    await wrapper.get('[data-test="template-preview"]').trigger('click')
    m.supplier.currentSupplierId = 2
    await flushPromises()
    pending.resolve({ subject: 'Old company', body_html: '<p>Old company</p>', body_text: 'Old company' })
    await flushPromises()
    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Old company')
    wrapper.unmount()
  })

})
