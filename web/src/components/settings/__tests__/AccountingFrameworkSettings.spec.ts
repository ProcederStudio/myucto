import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { AccountingFrameworkState } from '@/api/settings'

const m = vi.hoisted(() => ({ save: vi.fn(), blockDemoMutation: vi.fn() }))
vi.mock('@/api/settings', () => ({ settingsApi: { updateAccountingFramework: m.save } }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: m.blockDemoMutation }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (date: string) => date }))

import AccountingFrameworkSettings from '../AccountingFrameworkSettings.vue'

const state: AccountingFrameworkState = {
  id: 42,
  accounting_framework: 'cz_500',
  accounting_framework_history: [{
    id: 7, effective_from: '1900-01-01', accounting_framework: 'cz_500',
    created_at: '2026-01-01 00:00:00', created_by: null,
  }],
}

function render(canChange = true) {
  return mount(AccountingFrameworkSettings, {
    props: { supplier: structuredClone(state), canChange },
    global: { stubs: { DateInput: {
      props: ['modelValue', 'disabled'],
      emits: ['update:modelValue'],
      template: '<input type="date" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    } } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  m.blockDemoMutation.mockReturnValue(false)
  m.save.mockResolvedValue(structuredClone(state))
})

describe('AccountingFrameworkSettings', () => {
  it('shows history and keeps unsupported framework disabled', () => {
    const wrapper = render()
    expect(wrapper.text()).toContain('settings.accounting_framework.baseline')
    expect(wrapper.get('option[value="cz_504"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="save-framework"]').attributes('disabled')).toBeDefined()
    expect(m.save).not.toHaveBeenCalled()
  })

  it('saves only the explicit framework and period date for the displayed company', async () => {
    const wrapper = render()
    await wrapper.get('input').setValue('2027-07-01')
    await wrapper.get('[data-test="save-framework"]').trigger('click')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(42, 'cz_500', '2027-07-01')
    expect(wrapper.emitted('updated')).toEqual([[state]])
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
})
