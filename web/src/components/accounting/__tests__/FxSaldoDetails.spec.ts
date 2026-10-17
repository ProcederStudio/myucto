import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FxSaldoDetails from '../FxSaldoDetails.vue'
import type { FxDetailRow } from '@/api/closing'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number, currency = 'CZK') => `${value.toFixed(2)} ${currency}` }))

describe('FxSaldoDetails', () => {
  it('shows current-year gain separately from original liability value and prior carrying', () => {
    const row: FxDetailRow = { doc_type: 'purchase_invoice', doc_id: 7, varsymbol: 'SYNTHETIC-7', currency_code: 'EUR',
      remaining_foreign: 1000, fx_rate: 25, rate_cnb: 25.9, diff: 900, carrying_before: '-1000.00', incremental_signed: '100.00' }
    const wrapper = mount(FxSaldoDetails, { props: { rows: [row] } })
    expect(wrapper.get('[data-fx-difference]').text()).toBe('900.00 CZK')
    expect(wrapper.get('[data-fx-carrying]').text()).toBe('-1000.00 CZK')
    expect(wrapper.get('[data-fx-increment]').text()).toBe('100.00 CZK')
    expect(wrapper.get('[data-fx-increment]').classes()).toContain('text-success-600')
    expect(wrapper.get('[data-fx-difference]').classes()).not.toContain('text-success-600')
    expect(wrapper.find('.overflow-x-auto').exists()).toBe(true)
  })

  it('does not present already-carried valuation as new income and marks actual loss', () => {
    const common: FxDetailRow = { doc_type: 'invoice', doc_id: 8, varsymbol: 'SYNTHETIC-8', currency_code: 'EUR',
      remaining_foreign: 1000, fx_rate: 25, rate_cnb: 26, diff: 1000, carrying_before: '1000.00', incremental_signed: '0.00' }
    const wrapper = mount(FxSaldoDetails, { props: { rows: [common, { ...common, doc_id: 9, incremental_signed: '-250.00' }] } })
    const increments = wrapper.findAll('[data-fx-increment]')
    expect(increments[0]!.text()).toBe('0.00 CZK')
    expect(increments[0]!.classes()).not.toContain('text-success-600')
    expect(increments[0]!.classes()).not.toContain('text-danger-500')
    expect(increments[1]!.text()).toBe('-250.00 CZK')
    expect(increments[1]!.classes()).toContain('text-danger-500')
  })
})
