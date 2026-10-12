import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import InvoicePublic from './InvoicePublic.vue'

const api = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { token: 'synthetic' } }) }))
vi.mock('@/api/publicInvoice', () => ({ publicInvoiceApi: { get: api.get, pdfUrl: () => '', attachmentUrl: () => '' } }))

describe('public invoice time presentation', () => {
  it.each([
    { minutes: 1, quantity: 0.017, rate: 1000, base: 16.67, gross: false, duration: '0:01', price: '1,000.00 CZK' },
    { minutes: 1, quantity: 0.017, rate: 1000, base: 13.78, gross: true, duration: '0:01', price: '826.80 CZK' },
    { minutes: null, quantity: 3, rate: 333.333333, base: 1000, gross: false, duration: '3', price: '333.333333 CZK' },
    { minutes: null, quantity: 3, rate: 333.333333, base: 826.45, gross: true, duration: '3', price: '275.483333 CZK' },
    { minutes: null, quantity: 0.33, rate: 1000, base: 330, gross: false, duration: '0.33', price: '1,000.00 CZK' },
  ])('renders $duration at $price (gross: $gross)', async ({ minutes, quantity, rate, base, gross, duration, price }) => {
    api.get.mockResolvedValue({
      invoice: {
        invoice_type: 'invoice', status: 'issued', language: 'en', currency: 'CZK',
        amount_to_pay: base, paid_total: 0, due_date: '2099-01-01', prices_include_vat: gross,
        totals: { without_vat: base, vat: 0, with_vat: base, amount_to_pay: base },
        vat_breakdown: [], items: [{ description: 'Synthetic service', item_kind: 'standard',
          quantity, duration_minutes: minutes, unit: 'h', unit_price_without_vat: rate,
          total_without_vat: base, total_with_vat: base, vat_rate_snapshot: 0 }],
      },
      supplier: { is_vat_payer: false }, client: {}, bank: null, attachments: [], oss_clause: null,
    })
    const wrapper = mount(InvoicePublic)
    await flushPromises()
    try {
      const cells = wrapper.find('tbody tr').findAll('td')
      expect(cells[1]!.text()).toBe(duration)
      expect(cells[3]!.text()).toBe(price)
    } finally {
      wrapper.unmount()
    }
  })
})

describe('public invoice paid state', () => {
  it.each([
    { isPaid: true, qr: false },
    { isPaid: false, qr: true },
  ])('status paid with server is_paid=$isPaid shows QR: $qr', async ({ isPaid, qr }) => {
    api.get.mockResolvedValue({
      invoice: {
        invoice_type: 'invoice', status: 'paid', is_paid: isPaid, language: 'cs', currency: 'CZK',
        amount_to_pay: 1100, paid_total: 1000, due_date: '2099-01-01', prices_include_vat: false,
        payment_method: 'bank_transfer',
        totals: { without_vat: 1100, vat: 0, with_vat: 1100, amount_to_pay: 1100 },
        vat_breakdown: [], items: [],
      },
      supplier: { is_vat_payer: false }, client: {}, bank: { account_number: '1000000005', bank_code: '0100' },
      attachments: [], oss_clause: null, qr_data_uri: 'data:image/png;base64,QR',
    })
    const wrapper = mount(InvoicePublic)
    await flushPromises()
    try {
      expect(wrapper.find('img[alt="QR"]').exists()).toBe(qr)
    } finally {
      wrapper.unmount()
    }
  })
})
