import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({ permissions: {} as Record<string, number>, list: vi.fn(), cars: vi.fn(), warnings: vi.fn(), save: vi.fn() }))
vi.mock('@/api/logbook', () => ({ logbookApi: { listFuelings: mocks.list, listCars: mocks.cars, fuelingWarnings: mocks.warnings, updateFueling: mocks.save } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  canRead: (key: string) => (mocks.permissions[key] ?? 0) >= 1,
  canWrite: (key: string) => (mocks.permissions[key] ?? 0) >= 2,
}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
import FuelingsTab from './FuelingsTab.vue'

async function open() {
  const wrapper = mount({ ...FuelingsTab, render: () => null })
  await flushPromises()
  return wrapper
}

describe('delegated fueling permissions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.permissions = { logbook: 1 }
    mocks.list.mockResolvedValue({ data: [], meta: { total: 0, pages: 1 }, years: [] })
    mocks.cars.mockResolvedValue([])
    mocks.warnings.mockResolvedValue({ cars: [], totals: { missing: 0, regressions: 0, vat_mismatches: 0 } })
    mocks.save.mockResolvedValue({})
  })

  it('hides mutations for a read-only role', async () => {
    const wrapper = await open()
    const vm = wrapper.vm as unknown as { toolbarActions: { key: string; show?: boolean }[]; linkKinds: string[] }
    for (const key of ['new', 'import', 'invoices', 'cash']) {
      expect(vm.toolbarActions.find(a => a.key === key)?.show).toBeFalsy()
    }
    expect(vm.linkKinds).toEqual([])
    wrapper.unmount()
  })

  it('uses independent grants for import and source lookups', async () => {
    mocks.permissions = { logbook: 1, 'logbook.write': 2, cash: 1 }
    const wrapper = await open()
    const vm = wrapper.vm as unknown as { toolbarActions: { key: string; show?: boolean }[]; linkKinds: string[] }
    expect(vm.toolbarActions.find(a => a.key === 'new')?.show).toBeTruthy()
    expect(vm.toolbarActions.find(a => a.key === 'invoices')?.show).toBeFalsy()
    expect(vm.toolbarActions.find(a => a.key === 'cash')?.show).toBeTruthy()
    expect(vm.toolbarActions.find(a => a.key === 'import')?.show).toBeFalsy()
    expect(vm.linkKinds).toEqual(['cash_document'])
    wrapper.unmount()
  })

  it('omits inaccessible source keys when saving unrelated edits', async () => {
    mocks.permissions = { logbook: 1, 'logbook.write': 2 }
    const wrapper = await open()
    const vm = wrapper.vm as unknown as { draft: Record<string, unknown>; save: () => Promise<void> }
    Object.assign(vm.draft, { id: 7, fueled_date: '2026-10-16', amount_with_vat: 100 })
    await vm.save()
    expect(mocks.save).toHaveBeenCalledOnce()
    const payload = mocks.save.mock.calls[0]![1] as Record<string, unknown>
    for (const key of ['source_cash_document_id', 'source_bank_transaction_id', 'source_journal_entry_id']) expect(payload).not.toHaveProperty(key)
    wrapper.unmount()
  })
})
