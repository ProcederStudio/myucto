import { describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const mocks = vi.hoisted(() => ({ switchTo: vi.fn(), setSupplier: vi.fn() }))
vi.mock('@/composables/useSupplierSwitch', () => ({ useSupplierSwitch: () => ({ switchTo: mocks.switchTo }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1, setSupplier: mocks.setSupplier }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isSuperadmin: false, canRead: () => false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/api/suppliers', () => ({ suppliersApi: { directory: async () => [{
  id: 2, company_name: 'Syntetická firma', clients_count: 0, invoices_count: 0, urgency: { level: 'none' },
}] } }))

import SuppliersPage from '../SuppliersPage.vue'

describe('přepnutí firmy ze správy firem v pracovním okně', () => {
  it('předá přepnutí shellu místo změny připnuté firmy a reloadu iframe', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/admin/suppliers', component: { render: () => null } }] })
    await router.push('/admin/suppliers')
    const wrapper = mount(SuppliersPage, { global: { plugins: [router], stubs: { SupplierUrgency: true } } })
    await flushPromises()
    const buttons = wrapper.findAll('button').filter(button => button.text() === 'supplier.switch')
    expect(buttons).toHaveLength(2)
    await buttons[0]!.trigger('click')
    expect(mocks.switchTo).toHaveBeenCalledWith(2)
    expect(mocks.setSupplier).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
