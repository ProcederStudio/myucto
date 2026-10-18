import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { DphBookRow } from '@/api/reports'

const m = vi.hoisted(() => ({ canRead: vi.fn(), openBeside: vi.fn(), error: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: m.canRead }) }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ openBeside: m.openBeside }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ resolve: (to: unknown) => ({ href: `/#${JSON.stringify(to)}` }) }) }))
import DphBookDocumentLink from '../DphBookDocumentLink.vue'

const show = (row: Partial<DphBookRow> = {}) => mount(DphBookDocumentLink, {
  props: { row: { invoice_id: 42, direction: 'issued', doc_number: 'TEST-42', ...row } as DphBookRow },
})
beforeEach(() => {
  vi.clearAllMocks()
  m.canRead.mockReturnValue(true)
  m.openBeside.mockReset().mockResolvedValue(undefined)
})

describe('doklad z knihy DPH', () => {
  it.each([
    [{ direction: 'issued' }, 'invoices', { name: 'invoice-detail', params: { id: 42 } }],
    [{ direction: 'received' }, 'purchase_invoices', { name: 'purchase-invoice-detail', params: { id: 42 } }],
    [{ direction: 'issued', document_kind: 'cash' }, 'cash', { name: 'accounting-cash', query: { document_id: '42' } }],
    [{ direction: 'received', document_kind: 'cash' }, 'cash', { name: 'accounting-cash', query: { document_id: '42' } }],
  ] as const)('otevře správný zdroj i při kolizi ID: %j', async (row, permission, target) => {
    const wrapper = show(row)
    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe(`/#${JSON.stringify(target)}`)
    expect(link.attributes('data-workspace-open')).toBe('beside')
    expect(link.attributes('title')).toBe('common.detail')
    const event = new MouseEvent('click', { button: 0, cancelable: true })
    link.element.dispatchEvent(event)
    await flushPromises()
    expect(event.defaultPrevented).toBe(true)
    expect(m.canRead).toHaveBeenCalledWith(permission)
    expect(m.openBeside).toHaveBeenCalledExactlyOnceWith(target)
  })

  it.each([
    [{ direction: 'issued' }, 'invoices'],
    [{ direction: 'received' }, 'purchase_invoices'],
    [{ direction: 'issued', document_kind: 'cash' }, 'cash'],
    [{ direction: 'received', document_kind: 'cash' }, 'cash'],
  ] as const)('bez oprávnění %s zachová číslo bez náhradního odkazu', (row, permission) => {
    m.canRead.mockImplementation((candidate: string) => candidate !== permission)
    const wrapper = show(row)
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toContain('TEST-42')
  })

  it('§ 79 s ID korekce neodkazuje na fakturu se stejným ID', () => {
    const wrapper = show({ direction: 'correction' })
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toContain('TEST-42')
    expect(m.canRead).not.toHaveBeenCalled()
  })

  it.each([undefined, null, 0, -1, 1.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1])('neznámé nebo neplatné ID %s nemá proklik', id => {
    expect(show({ invoice_id: id as number }).find('a').exists()).toBe(false)
  })

  it.each([{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }])(
    'modifikovaný nebo prostřední klik %j ponechá standardnímu zpracování', async options => {
      const event = new MouseEvent('click', { button: 0, cancelable: true, ...options })
      show().get('a').element.dispatchEvent(event)
      await flushPromises()
      expect(event.defaultPrevented).toBe(false)
      expect(m.openBeside).not.toHaveBeenCalled()
    },
  )

  it('ohlásí chybu navigace a dovolí opakovat proklik', async () => {
    m.openBeside.mockRejectedValueOnce(new Error('navigation failed'))
    const wrapper = show()
    await wrapper.get('a').trigger('click', { button: 0 })
    await flushPromises()
    expect(m.error).toHaveBeenCalledExactlyOnceWith('common.error')
    await wrapper.get('a').trigger('click', { button: 0 })
    expect(m.openBeside).toHaveBeenCalledTimes(2)
  })
})
