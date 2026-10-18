import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => `T:${key}` }),
}))
vi.mock('../../../api/closing', () => ({ closingApi: { checkFindings: vi.fn() } }))

import CheckFindings from '@/components/accounting/CheckFindings.vue'

function mountFor(checkKey: string) {
  return mount(CheckFindings, {
    props: {
      checkKey,
      label: 'K3',
      value: { kind: 'document', count: 1, findings: [{ doc_id: 5, doc_no: 'F1', amount: 100, issues: ['marked_paid_unposted'] }] },
    },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, Modal: { template: '<div><slot /></div>' } } },
  })
}

describe('CheckFindings — nález marked_paid_unposted', () => {
  it('u přijatých faktur má vlastní popisek bez GoPay', async () => {
    const wrapper = mountFor('paid_purchases_open_saldo')
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('checks.issue.marked_paid_unposted_payable')
  })

  it('u vydaných faktur zůstává popisek s GoPay', async () => {
    const wrapper = mountFor('paid_invoices_open_saldo')
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('checks.issue.marked_paid_unposted')
    expect(wrapper.text()).not.toContain('marked_paid_unposted_payable')
  })
})

describe('CheckFindings FX', () => {
  it('nedoložený zůstatek zůstane neznámý a ukáže přeložitelný důvod', async () => {
    const wrapper = mount(CheckFindings, {
      props: { checkKey: 'fx_open_items', label: 'FX', value: { kind: 'document', count: 1,
        findings: [{ doc_type: 'invoice', doc_id: 7, amount: null, currency: 'EUR', issues: ['fx_settlement_unproven'] }] } },
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, Modal: { template: '<div><slot /></div>' } } },
    })
    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('checks.issue.fx_settlement_unproven')
    expect(wrapper.text()).not.toContain('0,00')
    expect(wrapper.text()).toContain('EUR')
  })
})
