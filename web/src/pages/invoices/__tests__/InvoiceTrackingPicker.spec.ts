import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import InvoiceTrackingPicker from '../InvoiceTrackingPicker.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatNumber: (v: number) => String(v) }))

const base = { mode: 'serial' as const, kind: 'sale' as const, choices: [], modelValue: [], requiredBase: 1, baseUnit: 'ks' }

describe('InvoiceTrackingPicker', () => {
  it('na vystavené faktuře bez výběru neukazuje varovný štítek, jen text', () => {
    const wrapper = mount(InvoiceTrackingPicker, { props: { ...base, readonly: true } })
    expect(wrapper.find('[data-test="invoice-tracking-state"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="invoice-tracking-readonly"]').text()).toBe('invoice.tracking.none_selected')
  })

  it('na vystavené faktuře s výběrem stav ukazuje', () => {
    const wrapper = mount(InvoiceTrackingPicker, {
      props: { ...base, readonly: true, modelValue: [{ quantity: '1', serial_number: 'VIN-1', lot_code: null, expires_on: null }] },
    })
    expect(wrapper.find('[data-test="invoice-tracking-state"]').exists()).toBe(true)
  })

  it('akční tlačítka mají ikonu a sdílený styl', () => {
    const retry = mount(InvoiceTrackingPicker, { props: { ...base, error: true } })
    const retryButton = retry.get('button')
    expect(retryButton.find('svg').exists()).toBe(true)
    expect(retryButton.classes()).toContain('h-7')

    const free = mount(InvoiceTrackingPicker, {
      props: { ...base, kind: 'return', mode: 'lot', allowFreeText: true,
        modelValue: [{ quantity: '2', serial_number: null, lot_code: 'L1', expires_on: null }] },
    })
    const buttons = free.findAll('button')
    expect(buttons.length).toBe(2)
    for (const button of buttons) expect(button.find('svg').exists()).toBe(true)
  })
})
