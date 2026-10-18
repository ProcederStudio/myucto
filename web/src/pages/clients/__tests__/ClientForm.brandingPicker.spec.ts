import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Regrese: výběr brandingového profilu četl správcovský výpis
 * `/api/settings/branding-profiles`, který vyžaduje `settings.branding`.
 * Účetní bez práva spravovat branding dostala 403, chyba se tiše spolkla
 * a výběr z karty klienta zmizel. Karta proto čte nabídku profilů dostupnou
 * každému, kdo vystavuje doklady.
 */
const m = vi.hoisted(() => ({
  available: vi.fn(async () => [
    { id: 7, name: 'Aktivní profil', is_active: true },
    { id: 8, name: 'Vypnutý profil', is_active: false },
  ]),
  managed: vi.fn(async () => { throw Object.assign(new Error('403'), { response: { status: 403 } }) }),
}))

vi.mock('@/api/clients', () => ({
  clientsApi: {
    create: vi.fn(),
    update: vi.fn(),
    get: vi.fn(),
    findDuplicates: vi.fn(async () => []),
    lookupVies: vi.fn(async () => ({ source: 'error' })),
    lookupBank: vi.fn(async () => ({ source: 'error' })),
    lookupAres: vi.fn(async () => ({})),
  },
  TAX_NUMBER_LABELS: {},
}))
vi.mock('@/api/invoices', () => ({ PAYMENT_METHODS: [] }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (d: string | null | undefined) => d ?? '' }))
vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    countries: vi.fn(async () => [{ iso2: 'CZ', name_cs: 'Česko', name_en: 'Czechia' }]),
    currencies: vi.fn(async () => [{ id: 1, code: 'CZK' }]),
  },
}))
vi.mock('@/api/expenseCategories', () => ({ expenseCategoriesApi: { list: vi.fn(async () => []) } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn(async () => []) } }))
vi.mock('@/api/settings', () => ({
  settingsApi: { listAvailableBrandingProfiles: m.available, listBrandingProfiles: m.managed },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => false, canWrite: () => false }),
}))
vi.mock('@/composables/useDemoMode', () => ({
  useDemoMode: () => ({ blockDemoMutation: () => false }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry', country_iso2: 'CZ' } }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

import ClientForm from '../ClientForm.vue'

describe('ClientForm — výběr brandingového profilu', () => {
  it('nabídne aktivní profily i bez práva spravovat branding', async () => {
    const wrapper = mount(ClientForm, { props: { embedded: true } })
    await flushPromises()

    expect(m.available).toHaveBeenCalled()
    expect(m.managed).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('client.branding_profile')
    expect(wrapper.text()).toContain('Aktivní profil')
    expect(wrapper.text()).not.toContain('Vypnutý profil')
  })
})
