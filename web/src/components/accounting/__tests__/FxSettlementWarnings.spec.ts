import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FxSettlementWarnings from '../FxSettlementWarnings.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

describe('FxSettlementWarnings', () => {
  it('ke každému upozornění nabídne správný doklad', () => {
    const wrapper = mount(FxSettlementWarnings, {
      props: { warnings: [
        { code: 'payment_header_divergence', doc_type: 'invoice', doc_id: 7 },
        { code: 'payment_header_divergence', doc_type: 'purchase_invoice', doc_id: 8 },
      ] },
      global: { stubs: { RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' } } },
    })
    expect(wrapper.findAll('li')).toHaveLength(2)
    expect(wrapper.text()).toContain('checks.issue.payment_header_divergence')
    expect(wrapper.findAllComponents({ name: 'RouterLink' }).map(link => link.props('to'))).toEqual([
      { name: 'invoice-detail', params: { id: 7 } },
      { name: 'purchase-invoice-detail', params: { id: 8 } },
    ])
  })

  it('bez upozornění nevykreslí stavový box', () => {
    expect(mount(FxSettlementWarnings, { props: { warnings: [] } }).find('[role="status"]').exists()).toBe(false)
  })
})
