import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { BankTransaction } from '@/api/bank'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

const m = vi.hoisted(() => ({ mode: 'tax_evidence', commercial: true, client: false, canRead: vi.fn(), openBeside: vi.fn(), error: vi.fn() }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ get currentSupplier() { return { accounting_mode: m.mode } } }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: m.canRead, canWrite: () => false, get hasCommercialFeatures() { return m.commercial }, get isClientRole() { return m.client } }) }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: m.openBeside }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error }) }))
vi.mock('@/composables/useDimensions', () => ({ useDimensions: () => ({ enabled: ref(false), documentTypes: ref([]), load: () => Promise.resolve() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value, formatMoney: (value: number) => String(value) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="`/${JSON.stringify(to)}`"><slot /></a>' },
  useRouter: () => ({ resolve: (to: unknown) => ({ href: `/${JSON.stringify(to)}` }) }),
}))
import BankTransactionRow from '../BankTransactionRow.vue'

const transaction = (partial: Partial<BankTransaction> = {}) => ({
  id: 42, statement_id: 7, amount: -1210, currency: 'CZK', posted_at: '2025-12-31', match_status: 'unmatched',
  variable_symbol: null, constant_symbol: null, specific_symbol: null, counterparty_account: null, counterparty_bank: null,
  counterparty_name: 'Test', description: null, bank_ref: null, matched_invoice_id: null, matched_at: null,
  ...partial,
}) as BankTransaction
async function show(tx = transaction(), layout: 'desktop' | 'mobile' = 'desktop', isDoubleEntry = false, openMenu = true) {
  const actions = {
    canRunDetailAction: () => false, expandedSuggestions: ref(new Set()), expandedDocs: ref(new Set()),
    suggestionFor: () => null, reviewingSuggestion: ref(false), textDetail: ref(null),
  } as unknown as BankTransactionActions
  const wrapper = mount(BankTransactionRow, {
    props: { tx, layout, isDoubleEntry, actions },
    global: { stubs: { PostingRowActions: true, PostingStatusBadge: true, WorkspaceTeleport: { template: '<slot />' } } },
  })
  const trigger = wrapper.find('button[aria-haspopup="menu"]')
  if (openMenu && trigger.exists()) await trigger.trigger('click')
  return wrapper
}
const linkSelector = '[data-test="bank-transaction-journal"], [data-action="open-cash-journal"]'
beforeEach(() => {
  vi.clearAllMocks()
  m.mode = 'tax_evidence'
  m.commercial = true
  m.client = false
  m.canRead.mockReturnValue(true)
  m.openBeside.mockResolvedValue(undefined)
})

describe('bankovní pohyb → deník', () => {
  it.each(['desktop', 'mobile'] as const)('daňový deník je pouze pod třemi tečkami (%s)', async layout => {
    const wrapper = await show(transaction(), layout, false, false)
    expect(wrapper.find(linkSelector).exists()).toBe(false)
    await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    expect(wrapper.get('[role="menu"] [data-action="open-cash-journal"]').text()).toBe('bank.open_cash_journal')
  })

  it.each(['desktop', 'mobile'] as const)('otevře vlastní řádek peněžního deníku podle data pohybu (%s)', async layout => {
    const wrapper = await show(transaction({ matched_invoice_id: 19 }), layout)
    const link = wrapper.get(linkSelector)
    const target = { name: 'tax-evidence-cash-journal', query: { year: '2025', source_type: 'bank', source_id: '42' } }
    expect(link.attributes('href')).toBe(`/${JSON.stringify(target)}`)
    expect(link.text()).toBe('bank.open_cash_journal')
    expect(link.find('svg').exists()).toBe(true)
    await link.trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledExactlyOnceWith(target)
  })

  it('podvojné účetnictví otevře existující zápis i bez komerčního přepínače bankovních akcí', async () => {
    m.mode = 'double_entry'
    const wrapper = await show(transaction({ posting: { status: 'posted', journal_entry_id: 84 } }))
    expect(wrapper.get(linkSelector).text()).toBe('bank.open_accounting_journal')
    await wrapper.get(linkSelector).trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledExactlyOnceWith({ name: 'accounting-journal', query: { entry_id: '84' } })
  })

  it.each([null, { status: 'posted' as const }, { status: 'posted' as const, journal_entry_id: 0 }])('nezobrazuje podvojný proklik bez zaúčtování %j', async posting => {
    m.mode = 'double_entry'
    expect((await show(transaction({ posting }))).find(linkSelector).exists()).toBe(false)
  })

  it('otevře existující zápis částečného mzdového zaúčtování i s prázdným stavem', async () => {
    m.mode = 'double_entry'
    await (await show(transaction({ posting: { status: null, journal_entry_id: 84 } }))).get(linkSelector).trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledWith({ name: 'accounting-journal', query: { entry_id: '84' } })
  })

  it.each(['tax_evidence', 'double_entry'])('skryje proklik v klientské roli (%s)', async mode => {
    m.mode = mode
    m.client = true
    expect((await show(transaction({ posting: { status: 'posted', journal_entry_id: 84 } }))).find(linkSelector).exists()).toBe(false)
  })

  it('peněžní deník není komerční funkce, účetní deník ano', async () => {
    m.commercial = false
    const tx = transaction({ posting: { status: 'posted', journal_entry_id: 84 } })
    expect((await show(tx)).find(linkSelector).exists()).toBe(true)
    m.mode = 'double_entry'
    expect((await show(tx)).find(linkSelector).exists()).toBe(false)
  })

  it('pohyb rozdělený na více dokladů otevře svůj jediný řádek peněžního deníku', async () => {
    const link = (await show(transaction({ match_status: 'manual', matched_invoice_id: 19, amount: 2420 }))).get(linkSelector)
    await link.trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledExactlyOnceWith({ name: 'tax-evidence-cash-journal', query: { year: '2025', source_type: 'bank', source_id: '42' } })
  })

  it('ignorovaný pohyb ve výpisu může být v peněžním deníku', async () => {
    expect((await show(transaction({ match_status: 'ignored' }))).find(linkSelector).exists()).toBe(true)
  })

  it.each([
    ['tax_evidence', { id: 1.5 }],
    ['tax_evidence', { id: Number.NaN }],
    ['tax_evidence', { posted_at: '0000-01-01' }],
    ['tax_evidence', { posted_at: '1899-12-31' }],
    ['double_entry', { posting: { status: 'posted', journal_entry_id: Number.POSITIVE_INFINITY } }],
    ['double_entry', { posting: { status: 'posted', journal_entry_id: 1.5 } }],
  ] as const)('nezobrazuje neplatný cíl %s %j', async (mode, partial) => {
    m.mode = mode
    expect((await show(transaction(partial))).find(linkSelector).exists()).toBe(false)
  })

  it.each(['none', ''])('neodvozuje daňovou evidenci z vypnutých podvojných akcí (%s)', async mode => {
    m.mode = mode
    expect((await show()).find(linkSelector).exists()).toBe(false)
  })

  it.each([['tax_evidence', 'tax_evidence'], ['double_entry', 'accounting']])('respektuje oprávnění %s', async (mode, permission) => {
    m.mode = mode
    m.canRead.mockImplementation((key: string) => key !== permission)
    expect((await show(transaction({ posting: { status: 'posted', journal_entry_id: 84 } }))).find(linkSelector).exists()).toBe(false)
    expect(m.canRead).toHaveBeenCalledWith(permission)
  })

  it.each([{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }])('zachová standardní chování modifikovaného odkazu %j', async modifiers => {
    const link = (await show()).get(linkSelector)
    const event = new MouseEvent('click', { button: 0, bubbles: true, cancelable: true, ...modifiers })
    link.element.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(false)
    expect(m.openBeside).not.toHaveBeenCalled()
  })

  it('chybu navigace ohlásí a další klik dovolí opakovat', async () => {
    m.openBeside.mockRejectedValueOnce(new Error('failed'))
    const link = (await show()).get(linkSelector)
    await link.trigger('click', { button: 0 })
    await flushPromises()
    expect(m.error).toHaveBeenCalledWith('common.error')
    await link.trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledTimes(2)
  })
})
