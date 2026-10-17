import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { BankTransaction } from '@/api/bank'
const m = vi.hoisted(() => ({ allowed: true, mode: 'double_entry', openBeside: vi.fn(), error: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => m.allowed, hasCommercialFeatures: true, isClientRole: false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ get currentSupplier() { return { accounting_mode: m.mode } } }) }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: m.openBeside }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
  useRouter: () => ({ resolve: (to: unknown) => ({ href: `/${JSON.stringify(to)}` }) }),
}))
import PostingStatusBadge from '../PostingStatusBadge.vue'
const show = (posting: BankTransaction['posting']) => mount(PostingStatusBadge, { props: { posting } })
beforeEach(() => { vi.clearAllMocks(); m.allowed = true; m.mode = 'double_entry'; m.openBeside.mockResolvedValue(undefined) })
describe('stav zaúčtování banky → sousední deník', () => {
  it.each([
    [{ status: 'posted', journal_entry_id: 84, document_no: 'TEST' }, 'bank.posting.badge_posted', 'TEST'],
    [{ status: null, journal_entry_id: 84, note: 'payroll_partial_posting' }, 'bank.posting.badge_payroll_partial', 'bank.posting.payroll_partial_hint'],
  ] as const)('otevře existující zápis ze štítku %j', async (posting, label, title) => {
    const link = show(posting).get('a')
    expect(link.text()).toBe(label)
    expect(link.attributes('title')).toBe(title)
    expect(link.attributes('data-workspace-open')).toBe('beside')
    await link.trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledExactlyOnceWith({ name: 'accounting-journal', query: { entry_id: '84' } })
  })
  it.each([
    { status: 'posted' },
    { status: null, note: 'payroll_partial_posting' },
    { status: 'suggested' },
  ] as const)('stav bez zápisu zůstane prostým štítkem %j', posting => {
    const wrapper = show(posting)
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.find('span').exists()).toBe(true)
  })
  it('bez oprávnění zůstane stav čitelný bez odkazu', () => {
    m.allowed = false
    const wrapper = show({ status: 'posted', journal_entry_id: 84 })
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toBe('bank.posting.badge_posted')
  })
  it('modifikovaný klik ponechá prohlížeči', () => {
    const link = show({ status: 'posted', journal_entry_id: 84 }).get('a')
    const event = new MouseEvent('click', { button: 0, ctrlKey: true, bubbles: true, cancelable: true })
    link.element.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(false)
    expect(m.openBeside).not.toHaveBeenCalled()
  })
})
