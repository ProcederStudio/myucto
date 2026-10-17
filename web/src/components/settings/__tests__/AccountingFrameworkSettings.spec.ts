import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { AccountingFrameworkState } from '@/api/settings'

const m = vi.hoisted(() => ({ save: vi.fn(), refresh: vi.fn(), blockDemoMutation: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ refresh: m.refresh }) }))
vi.mock('@/api/settings', () => ({ settingsApi: { updateAccountingFramework: m.save } }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: m.blockDemoMutation }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (date: string) => date }))

import AccountingFrameworkSettings from '../AccountingFrameworkSettings.vue'

const state: AccountingFrameworkState = {
  id: 42,
  accounting_framework: 'cz_500',
  tax_accounting_decree_effective: '500',
  tax_accounting_decree_is_derived: true,
  accounting_framework_history: [{
    id: 7, effective_from: '1900-01-01', accounting_framework: 'cz_500',
    created_at: '2026-01-01 00:00:00', created_by: null,
  }],
}

function render(canChange = true, realDateInput = false) {
  return mount(AccountingFrameworkSettings, {
    props: { supplier: structuredClone(state), canChange },
    global: { stubs: realDateInput ? {} : { DateInput: {
      props: ['modelValue', 'disabled'],
      emits: ['update:modelValue'],
      template: '<input type="date" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    } } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  m.blockDemoMutation.mockReturnValue(false)
  m.refresh.mockResolvedValue(true)
  m.save.mockResolvedValue(structuredClone(state))
})

describe('AccountingFrameworkSettings', () => {
  it('shows history and offers framework 504 for testing', () => {
    const wrapper = render()
    expect(wrapper.text()).toContain('settings.accounting_framework.baseline')
    expect(wrapper.get('option[value="cz_504"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="framework-testing-warning"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="save-framework"]').attributes('disabled')).toBeDefined()
    expect(m.save).not.toHaveBeenCalled()
  })

  it('saves only the explicit framework and period date for the displayed company', async () => {
    const wrapper = render()
    await wrapper.get('input').setValue('2027-07-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(42, 'cz_500', '2027-07-01')
    expect(m.refresh).toHaveBeenCalledOnce()
    expect(wrapper.emitted('updated')).toEqual([[state]])
  })

  it('warns prominently before saving 504 and keeps the warning for an active 504 company', async () => {
    const wrapper = render()
    await wrapper.get('select').setValue('cz_504')
    const warning = wrapper.get('[data-test="framework-testing-warning"]')
    expect(warning.text()).toContain('settings.accounting_framework.testing_warning_title')
    expect(warning.text()).toContain('settings.accounting_framework.testing_warning')
    expect(warning.classes()).toContain('border-warning-500')
    expect(warning.classes()).toContain('bg-warning-50')
    expect(warning.find('svg').exists()).toBe(true)
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(42, 'cz_504', '2027-01-01')
    await wrapper.setProps({ supplier: { ...state, accounting_framework: 'cz_504' }, canChange: false })
    await wrapper.get('select').setValue('cz_500')
    expect(wrapper.find('[data-test="framework-testing-warning"]').exists()).toBe(true)
  })

  it('shows the backend refusal without claiming the change was saved', async () => {
    m.save.mockRejectedValue({ response: { data: { error: { message: 'Posted entries prevent the change.' } } } })
    const wrapper = render()
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Posted entries prevent the change.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.emitted('updated')).toBeUndefined()
  })

  it('does not apply a delayed response after switching company', async () => {
    let resolve!: (value: AccountingFrameworkState) => void
    m.save.mockReturnValue(new Promise<AccountingFrameworkState>(r => { resolve = r }))
    const wrapper = render()
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await wrapper.setProps({ supplier: { ...state, id: 43 } })
    resolve(state)
    await flushPromises()
    expect(wrapper.emitted('updated')).toBeUndefined()
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('')
  })

  it('prevents writes when unavailable or in demo mode', async () => {
    const wrapper = render(false)
    await wrapper.get('input').setValue('2027-01-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    expect(m.save).not.toHaveBeenCalled()
    await wrapper.setProps({ canChange: true })
    m.blockDemoMutation.mockReturnValue(true)
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    expect(m.save).not.toHaveBeenCalled()
  })

  it('passes the standard field size and styling to the actual DateInput text input', async () => {
    const wrapper = render(true, true)
    const input = wrapper.get('input[type="text"]')
    const select = wrapper.get('select')
    for (const token of ['w-full', 'h-9', 'px-3', 'border', 'border-neutral-300', 'rounded-md', 'bg-surface', 'text-sm']) {
      expect(input.classes()).toContain(token)
      expect(select.classes()).toContain(token)
    }
    expect(select.classes()).toContain('focus:ring-2')
    expect(select.classes()).toContain('focus:border-primary-500')
    expect(input.classes()).toContain('focus:ring-2')
    expect(input.classes()).toContain('focus:border-primary-500')
    expect(wrapper.get('[data-test="save-framework"]').element.parentElement?.classList.contains('flex-wrap')).toBe(true)
    await wrapper.setProps({ canChange: false })
    expect(input.attributes('disabled')).toBeDefined()
    expect(select.attributes('disabled')).toBeDefined()
  })

})
