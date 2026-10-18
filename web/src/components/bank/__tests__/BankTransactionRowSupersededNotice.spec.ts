import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { BankTransaction } from '@/api/bank'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: vi.fn() }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: null }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: false }, canEdit: { value: false }, documentTypes: { value: [] }, load: () => Promise.resolve(),
  }),
}))
vi.mock('vue-router', () => ({ useRouter: () => ({}), RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))

import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'

function actions(): BankTransactionActions {
  return {
    canRunDetailAction: () => true,
    expandedSuggestions: ref(new Set()), expandedDocs: ref(new Set()), toggleSuggestion: vi.fn(), toggleDocs: vi.fn(),
    suggestionFor: () => null, reviewingSuggestion: ref(false), acceptTxSuggestion: vi.fn(), rejectTxSuggestion: vi.fn(),
    startMatch: vi.fn(), openCreate: vi.fn(), openRequestDoc: vi.fn(), ignoreTx: vi.fn(), unmatchTx: vi.fn(),
    textDetail: ref(null),
  } as unknown as BankTransactionActions
}

const notice = (superseded: boolean) => ({
  id: 21, statement_id: 4, source: 'email_notice', amount: 1000, currency: 'CZK', posted_at: '2026-05-05',
  match_status: 'unmatched', ignore_origin: superseded ? 'superseded' : null, notice_superseded: superseded,
  variable_symbol: '2026001', constant_symbol: null, specific_symbol: null, counterparty_account: null, counterparty_bank: null,
  counterparty_name: 'Odběratel', description: null, bank_ref: null, matched_invoice_id: null, matched_at: null,
}) as unknown as BankTransaction

function mountRow(layout: 'desktop' | 'mobile', row: BankTransaction) {
  return mount(BankTransactionRow, {
    props: { tx: row, actions: actions(), layout, isDoubleEntry: false },
    global: {
      stubs: {
        BankTransactionJournalLink: true, RowActionsMenu: true, PostingStatusBadge: true, WhyChip: true, PostingRowActions: true,
        MatchSuggestionPanel: true, LinkedDocumentsPanel: true, DimensionChips: true, DocumentDimensionsPanel: true,
      },
    },
  })
}

describe('BankTransactionRow — avízo převzaté výpisem', () => {
  it.each(['desktop', 'mobile'] as const)('ukáže neutrální štítek „Nahrazeno výpisem" (%s)', async layout => {
    const wrapper = mountRow(layout, notice(true))
    await flushPromises()
    const badge = wrapper.get('[data-testid="tx-status-badge"]')
    expect(badge.text()).toBe('bank.match_status.superseded')
    expect(badge.element.tagName).toBe('SPAN')
    expect(badge.classes()).toContain('bg-neutral-100')
    expect(badge.attributes('title')).toBe('bank.notice_superseded_hint')
    // Ruční spárování (třeba po smazání výpisu) zůstává v nabídce.
    const list = wrapper.findComponent(RowActionsMenu).props('actions') as RowAction[]
    expect(list.find(a => a.key === 'match')?.show).toBe(true)
    wrapper.unmount()
  })

  it('aktivní avízo zůstává nespárované a klikatelné', async () => {
    const wrapper = mountRow('desktop', notice(false))
    await flushPromises()
    const badge = wrapper.get('[data-testid="tx-status-badge"]')
    // statusLabel() vrací při chybějícím překladu kód stavu.
    expect(badge.text()).toBe('unmatched')
    expect(badge.element.tagName).toBe('BUTTON')
    wrapper.unmount()
  })
})
