import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { CashJournalRow } from '@/api/taxEvidence'

const m = vi.hoisted(() => ({ openBeside: vi.fn(), canRead: vi.fn(), error: vi.fn(), workspace: { paneCount: 2 } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: m.canRead }) }))
vi.mock('@/stores/workspace', () => ({ useWorkspaceStore: () => m.workspace }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: m.openBeside }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ resolve: (to: unknown) => ({ href: `/${JSON.stringify(to)}` }) }) }))
import CashJournalSourceLink from '../CashJournalSourceLink.vue'

const bank = { source_type: 'bank', source_id: 42, bank_statement_id: 7, invoice_id: 10, purchase_invoice_id: null, gopay_clearing_id: null } as CashJournalRow
const show = (row: Partial<CashJournalRow> = {}) => mount(CashJournalSourceLink, { props: { row: { ...bank, ...row } } })
beforeEach(() => {
  vi.clearAllMocks()
  m.canRead.mockReturnValue(true)
  m.openBeside.mockReset().mockResolvedValue(undefined)
  m.workspace.paneCount = 2
})

describe('zdrojový záznam peněžního deníku', () => {
  it('bankovní řádek otevře konkrétní transakci ve výpisu i když je spárovaný s fakturou', async () => {
    const wrapper = show()
    expect(wrapper.get('a').attributes('title')).toBe('tax_evidence.cash_journal.open_source')
    expect(wrapper.get('a').attributes('aria-label')).toBe('tax_evidence.cash_journal.open_source')
    expect(wrapper.get('a').text()).toBe('')
    expect(wrapper.find('a svg').exists()).toBe(true)
    await wrapper.get('a').trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledExactlyOnceWith({ name: 'bank-detail', params: { id: 7 }, query: { tx: '42', detail: '1' } })
  })

  it.each([
    [{ source_type: 'cash' }, { name: 'accounting-cash', query: { document_id: '42' } }],
    [{ source_type: 'invoice_payment' }, { name: 'invoice-detail', params: { id: 10 } }],
    [{ source_type: 'purchase_invoice', invoice_id: null, purchase_invoice_id: 9 }, { name: 'purchase-invoice-detail', params: { id: 9 } }],
    [{ source_type: 'gopay', invoice_id: null, gopay_clearing_id: 5 }, { name: 'gopay', query: { clearing: '5' } }],
  ] as const)('otevře vlastní zdroj pro %j', async (row, target) => {
    await show(row).get('a').trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledWith(target)
  })

  it('bez oprávnění k bance nenabídne ani náhradní odkaz na fakturu', () => {
    m.canRead.mockImplementation((permission: string) => permission !== 'bank')
    expect(show().find('a').exists()).toBe(false)
  })

  it('bez dohledatelného zdroje nezobrazuje nefunkční proklik', () => {
    expect(show({ source_type: 'unknown', bank_statement_id: null, invoice_id: null }).find('a').exists()).toBe(false)
  })

  it('při jednom panelu používá stejnou navigaci a odpovídající nápovědu', async () => {
    m.workspace.paneCount = 1
    const wrapper = show()
    expect(wrapper.get('a').attributes('data-workspace-open')).toBe('beside')
    await wrapper.get('a').trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledOnce()
  })

  it('modifikovaný klik ponechá standardnímu zpracování odkazů', async () => {
    await show().get('a').trigger('click', { button: 0, ctrlKey: true })
    expect(m.openBeside).not.toHaveBeenCalled()
  })

  it('chybu navigace zobrazí a dovolí nový pokus', async () => {
    m.openBeside.mockRejectedValueOnce(new Error('navigation failed'))
    const wrapper = show()
    await wrapper.get('a').trigger('click', { button: 0 })
    await flushPromises()
    expect(m.error).toHaveBeenCalledWith('common.error')
    await wrapper.get('a').trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledTimes(2)
  })
})
