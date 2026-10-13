import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import PaymentOrders from '../PaymentOrders.vue'
import type { PayerAccount, TaxPaymentCandidate } from '@/api/paymentOrders'

const m = vi.hoisted(() => ({
  list: vi.fn(), candidates: vi.fn(), create: vi.fn(), markOrdered: vi.fn(), download: vi.fn(),
  success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn(),
}))
vi.mock('@/api/paymentOrders', () => ({
  paymentOrdersApi: {
    list: m.list, candidates: m.candidates, create: m.create, markOrdered: m.markOrdered,
    downloadPaymentOrder: m.download,
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error, warning: m.warning, info: m.info }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ push: vi.fn() }) }))

const payer = { id: 7, code: 'CZK', label: 'Synthetic account', account_number: '1000000005', bank_code: '0100', iban: null, bic: null } as unknown as PayerAccount
const tax = (over: Partial<TaxPaymentCandidate>): TaxPaymentCandidate => ({
  key: 'vat:11', kind: 'vat', id: 11, label: 'DPH 2026 Q3', amount: 1000, due_date: '2026-10-26',
  office_name: 'Finanční úřad pro Plzeňský kraj', account_number: '705-77627311', bank_code: '0710',
  iban: null, account: '705-77627311/0710', variable_symbol: '24681357', ordered_at: null, ...over,
})
const empty = { data: [], meta: { total: 0, page: 1, pages: 1, per_page: 50 } }

type State = {
  toggleTax: (c: TaxPaymentCandidate) => void
  createAndDownload: (format: 'abo') => Promise<void>
  markOnly: () => Promise<void>
  selectedTotal: number
  taxCandidates: TaxPaymentCandidate[]
}

beforeEach(() => {
  vi.clearAllMocks()
  m.list.mockResolvedValue(empty)
  m.candidates.mockResolvedValue({
    ...empty,
    payer_accounts: [payer],
    tax_candidates: [tax({}), tax({ key: 'advance:21', kind: 'advance', id: 21, label: 'Záloha DPPO 4/2026', amount: 173000 }),
      tax({ key: 'advance:22', kind: 'advance', id: 22, account_number: null, account: null })],
  })
  m.create.mockResolvedValue({ order_id: 5, view: { item_count: 2 }, skipped: [], clamped_date: false })
})

describe('Platební příkazy — daně finančnímu úřadu', () => {
  it('posílá vybrané daně jako ID závazků, ne jako účet a částku', async () => {
    const wrapper = shallowMount(PaymentOrders)
    await flushPromises()
    const vm = wrapper.vm as unknown as State
    const [vat, advance, noOffice] = vm.taxCandidates
    vm.toggleTax(vat)
    vm.toggleTax(advance)
    vm.toggleTax(noOffice)
    expect(vm.selectedTotal).toBe(174000)

    await vm.createAndDownload('abo')
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      invoice_ids: [],
      tax_submission_ids: [11],
      tax_advance_ids: [21],
      payer_currency_id: 7,
    }))
    wrapper.unmount()
  })

  it('„Jen označit" daně neoznačuje', async () => {
    const wrapper = shallowMount(PaymentOrders)
    await flushPromises()
    const vm = wrapper.vm as unknown as State
    vm.toggleTax(vm.taxCandidates[0])
    await vm.markOnly()
    expect(m.markOrdered).not.toHaveBeenCalled()
    expect(m.error).toHaveBeenCalledWith('payment_order.tax_mark_only_unsupported')
    wrapper.unmount()
  })
})
