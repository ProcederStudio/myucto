import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// Přepnutí firmy se ukládá i jako výchozí firma účtu (PUT /auth/default-supplier),
// a to PŘED reloadem stránky — jinak by ho prohlížeč přerušil.

const m = vi.hoisted(() => ({
  calls: [] as string[],
  setDefaultSupplier: vi.fn(),
  refresh: vi.fn(),
  setSupplier: vi.fn(),
  clearPermissions: vi.fn(),
  isDemo: false,
  currentSupplierId: 1,
  suppliers: [] as Array<{ id: number, company_name: string, ic: string | null }>,
}))

const DEFAULT_SUPPLIERS = [
  { id: 1, company_name: 'První s.r.o.', ic: '12345678' },
  { id: 2, company_name: 'Druhá s.r.o.', ic: '87654321' },
]

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

vi.mock('@/api/auth', () => ({ authApi: { setDefaultSupplier: m.setDefaultSupplier } }))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    get currentSupplierId() { return m.currentSupplierId },
    hasMultiple: true,
    currentSupplier: { id: 1, company_name: 'První s.r.o.', ic: '12345678' },
    get availableSuppliers() { return m.suppliers },
    setSupplier: m.setSupplier,
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isDemo() { return m.isDemo },
    clearPermissions: m.clearPermissions,
    refresh: m.refresh,
  }),
}))

vi.mock('@/router', () => ({
  router: { currentRoute: { value: { name: 'dashboard', path: '/', fullPath: '/' } } },
}))

import SupplierSwitcher from '../SupplierSwitcher.vue'
import { resetSupplierSwitchForTests } from '@/composables/useSupplierSwitch'

async function pickSecond() {
  const w = mount(SupplierSwitcher)
  await w.find('button').trigger('click')
  const option = w.findAll('button').find(b => b.text().includes('Druhá s.r.o.'))
  await option!.trigger('click')
  await flushPromises()
}

describe('SupplierSwitcher — výchozí firma účtu', () => {
  const reload = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    resetSupplierSwitchForTests()
    m.calls = []
    m.isDemo = false
    m.suppliers = DEFAULT_SUPPLIERS
    m.setDefaultSupplier.mockImplementation(async () => { m.calls.push('default') })
    m.refresh.mockImplementation(async () => { m.calls.push('refresh'); return true })
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { pathname: '/', href: '/', reload },
    })
  })

  it('uloží zvolenou firmu jako výchozí dřív, než stránku přenačte', async () => {
    await pickSecond()

    expect(m.setSupplier).toHaveBeenCalledWith(2)
    expect(m.setDefaultSupplier).toHaveBeenCalledWith(2)
    expect(m.calls).toEqual(['default', 'refresh'])
    expect(reload).toHaveBeenCalled()
  })

  it('selhání uložení přepnutí nezastaví', async () => {
    m.setDefaultSupplier.mockRejectedValue(new Error('offline'))

    await pickSecond()

    expect(m.refresh).toHaveBeenCalled()
    expect(reload).toHaveBeenCalled()
  })

  it('v demu výchozí firmu neukládá', async () => {
    m.isDemo = true

    await pickSecond()

    expect(m.setDefaultSupplier).not.toHaveBeenCalled()
    expect(reload).toHaveBeenCalled()
  })
})

describe('SupplierSwitcher — pořadí firem', () => {
  it('řadí firmy abecedně podle češtiny, ne podle id', async () => {
    resetSupplierSwitchForTests()
    m.suppliers = [
      { id: 98, company_name: 'Domy a byty s.r.o.', ic: null },
      { id: 102, company_name: 'ZENERGO a.s.', ic: null },
      { id: 103, company_name: 'ZENERGO Moravia s.r.o.', ic: null },
      { id: 105, company_name: '2DB s.r.o.', ic: null },
      { id: 106, company_name: 'Čtvrtá s.r.o.', ic: null },
      { id: 107, company_name: 'AGROVOLTAIKA Milotice s.r.o.', ic: null },
      { id: 108, company_name: 'ZENERGO Agro s.r.o.', ic: null },
    ]
    const w = mount(SupplierSwitcher)
    await w.find('button').trigger('click')
    const names = w.findAll('button').slice(1).map(b => b.find('.font-medium').text())

    expect(names).toEqual([
      '2DB s.r.o.',
      'AGROVOLTAIKA Milotice s.r.o.',
      'Čtvrtá s.r.o.',
      'Domy a byty s.r.o.',
      'ZENERGO a.s.',
      'ZENERGO Agro s.r.o.',
      'ZENERGO Moravia s.r.o.',
    ])
  })
})
