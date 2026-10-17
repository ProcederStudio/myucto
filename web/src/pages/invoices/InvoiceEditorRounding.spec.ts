import { flushPromises, shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import type { InvoiceRoundingMode, InvoiceType, PaymentMethod } from '@/api/invoices'
import type { SupplierBrief } from '@/api/auth'
import { useSupplierStore } from '@/stores/supplier'

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: () => new Promise(() => {}),
    currencies: vi.fn(),
    units: vi.fn(),
  },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))

import InvoiceEditor from './InvoiceEditor.vue'

describe('InvoiceEditor rounding preview', () => {
  it.each([
    { mode: 'auto', payment: 'cash', type: 'invoice', currency: 'CZK', price: 9.7, advance: 0, due: 10 },
    { mode: 'auto', payment: 'cash', type: 'invoice', currency: 'CZK', price: 9.3, advance: 0, due: 9 },
    { mode: 'none', payment: 'cash', type: 'invoice', currency: 'CZK', price: 9.7, advance: 0, due: 9.7 },
    { mode: 'auto', payment: 'cash_on_delivery', type: 'invoice', currency: 'CZK', price: 9.7, advance: 0, due: 9.7 },
    { mode: 'whole_czk', payment: 'cash_on_delivery', type: 'invoice', currency: 'CZK', price: 9.7, advance: 0, due: 10 },
    { mode: 'whole_czk', payment: 'bank_transfer', type: 'invoice', currency: 'CZK', price: 9.7, advance: 0.3, due: 9 },
    { mode: 'whole_czk', payment: 'card', type: 'invoice', currency: 'CZK', price: 9.7, advance: 0, due: 9.7 },
    { mode: 'whole_czk', payment: 'cash', type: 'invoice', currency: 'EUR', price: 9.7, advance: 0, due: 9.7 },
    { mode: 'whole_czk', payment: 'cash', type: 'proforma', currency: 'CZK', price: 9.7, advance: 0, due: 9.7 },
    { mode: 'whole_czk', payment: 'cash', type: 'credit_note', currency: 'CZK', price: -9.5, advance: 0, due: -10 },
    { mode: 'whole_czk', payment: 'cash', type: 'credit_note', currency: 'CZK', price: -0.3, advance: 0, due: 0 },
  ])('$mode $payment $type $currency: $price minus $advance becomes $due', async (scenario) => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/invoices/new', component: InvoiceEditor }],
    })
    await router.push('/invoices/new')
    await router.isReady()
    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [router, createPinia(), createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
        directives: { math: {} },
      },
    })
    const vm = wrapper.vm as unknown as {
      form: {
        invoice_type: InvoiceType
        currency: string
        payment_method: PaymentMethod
        rounding_mode: InvoiceRoundingMode
        advance_paid_amount: number
        items: Array<Record<string, unknown>>
      }
      computed_totals: { with_vat: number; amount_to_pay: number; rounding: number; without_vat: number; vat: number }
    }
    Object.assign(vm.form, {
      invoice_type: scenario.type,
      currency: scenario.currency,
      payment_method: scenario.payment,
      rounding_mode: scenario.mode,
      advance_paid_amount: scenario.advance,
      items: [{ quantity: 1, unit: 'ks', unit_price_without_vat: scenario.price, vat_rate_id: 0, vat_rate_snapshot: 0 }],
    })
    expect(vm.computed_totals.amount_to_pay).toBe(scenario.due)
    expect(vm.computed_totals.with_vat).toBeCloseTo(scenario.due + scenario.advance, 2)
    expect(vm.computed_totals.without_vat).toBe(scenario.price)
    expect(vm.computed_totals.vat).toBe(0)
    expect(vm.computed_totals.rounding).toBeCloseTo(scenario.due + scenario.advance - scenario.price, 2)
    wrapper.unmount()
  })

  it.each([
    { stored: 'whole_czk', expected: 'whole_czk' },
    { stored: 'none', expected: 'none' },
    { stored: undefined, expected: 'auto' },
  ])('nová faktura převezme výchozí zaokrouhlení firmy $stored', async ({ stored, expected }) => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/invoices/new', component: InvoiceEditor }],
    })
    await router.push('/invoices/new')
    await router.isReady()
    const pinia = createPinia()
    setActivePinia(pinia)
    const supplierStore = useSupplierStore()
    supplierStore.setAvailable([{ id: 1, company_name: 'Syntetická firma', default_rounding_mode: stored } as unknown as SupplierBrief], 1, true)
    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
        directives: { math: {} },
      },
    })
    await flushPromises()
    const vm = wrapper.vm as unknown as { form: { rounding_mode: InvoiceRoundingMode } }
    expect(vm.form.rounding_mode).toBe(expected)
    wrapper.unmount()
  })
})
