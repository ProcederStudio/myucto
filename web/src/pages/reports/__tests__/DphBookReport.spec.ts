import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { DphBookPreview, DphBookRow } from '@/api/reports'

const m = vi.hoisted(() => ({ preview: vi.fn(), canRead: vi.fn(), openBeside: vi.fn(), error: vi.fn() }))
vi.mock('@/api/reports', async original => ({ ...await original<typeof import('@/api/reports')>(), reportsApi: { dphBookPreview: m.preview } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: m.canRead }) }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: m.openBeside }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [2026] }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ resolve: (to: unknown) => ({ href: `/#${JSON.stringify(to)}` }) }) }))
import DphBookReport from '../DphBookReport.vue'

const row = (over: Partial<DphBookRow>): DphBookRow => ({
  invoice_id: 42, direction: 'issued', doc_number: 'TEST-42', original_doc_number: null,
  tax_date: '2026-10-01', accounting_date: null, claim_date: null, claim_basis: null, received_at: null,
  description: 'Test', counterparty_name: 'Test', counterparty_dic: '', vat_classification_code: null,
  vat_rate: 21, currency: 'CZK', exchange_rate: 1, base: 100, vat: 21, total: 121,
  status: 'issued', is_draft: false, kh_section: null, ...over,
})

beforeEach(() => {
  vi.clearAllMocks()
  m.canRead.mockReturnValue(true)
  m.openBeside.mockResolvedValue(undefined)
  const rows = [row({}), row({ direction: 'received', doc_number: 'TEST-PF' })]
  m.preview.mockResolvedValue({
    period: { label: '10/2026' },
    sections: [{ key: '1', direction: 'USKUTEČNĚNÁ', label: 'Test', rows, subtotal_base: 200, subtotal_vat: 42, subtotal_total: 242 }],
    totals: { issued: { base: 100, vat: 21, total: 121 }, received: { base: 100, vat: 21, total: 121 }, vat_balance: 0 },
  } as DphBookPreview)
})

describe('Kniha DPH – proklik dokladů', () => {
  it('v řádcích sestavy zachová čísla a nabídne skutečné odkazy na správné doklady', async () => {
    const wrapper = mount(DphBookReport)
    await flushPromises()
    const links = wrapper.findAll('tbody a')
    expect(links).toHaveLength(2)
    expect(links[0]!.text()).toBe('VF TEST-42')
    expect(links[1]!.text()).toBe('PF TEST-PF')
    expect(links[0]!.attributes('href')).toContain('invoice-detail')
    expect(links[1]!.attributes('href')).toContain('purchase-invoice-detail')
    await links[1]!.trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledWith({ name: 'purchase-invoice-detail', params: { id: 42 } })
  })

  it('bez oprávnění k dokladům zůstane sestava čitelná bez prokliků', async () => {
    m.canRead.mockReturnValue(false)
    const wrapper = mount(DphBookReport)
    await flushPromises()
    expect(wrapper.findAll('tbody a')).toHaveLength(0)
    expect(wrapper.text()).toContain('VF TEST-42')
    expect(wrapper.text()).toContain('PF TEST-PF')
  })
})
