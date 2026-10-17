import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, reactive } from 'vue'

const m = vi.hoisted(() => ({
  route: { query: {} as Record<string, unknown> },
  auth: { user: { id: 11 } }, supplier: { currentSupplierId: 22 },
  frameSupplier: null as string | null,
  storedSupplier: null as string | null,
  settings: vi.fn(),
  replace: vi.fn(),
}))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({ replace: m.replace }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m.auth }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.supplier }))
vi.mock('@/workspace/supplierContext', () => ({
  requestSupplierId: () => m.frameSupplier ?? m.storedSupplier,
  getWindowSupplierScope: () => m.frameSupplier ? { supplierId: Number(m.frameSupplier), windowId: 'test' } : null,
}))
vi.mock('@/api/reports', () => ({ reportsApi: { dphSettings: m.settings } }))

import { lastClosedVatPeriod, useVatReportPeriod } from '../useVatReportPeriod'
import { clearVatReportPeriodSession } from '@/utils/vatReportPeriodSession'

const scopes: ReturnType<typeof effectScope>[] = []
function create(kind: Parameters<typeof useVatReportPeriod>[0] = 'vat') {
  const scope = effectScope()
  scopes.push(scope)
  return scope.run(() => useVatReportPeriod(kind))!
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date(2026, 9, 15, 12))
  sessionStorage.clear()
  m.route = reactive({ query: {} })
  m.auth = reactive({ user: { id: 11 } })
  m.supplier = reactive({ currentSupplierId: 22 })
  m.frameSupplier = null
  m.storedSupplier = null
  m.settings.mockReset().mockResolvedValue({ vat_period: 'quarterly', taxpayer_type: 'fo' })
  m.replace.mockReset().mockImplementation(async ({ query }: { query: Record<string, unknown> }) => {
    await Promise.resolve()
    m.route.query = query
  })
})
afterEach(() => {
  scopes.splice(0).forEach(scope => scope.stop())
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe('poslední uzavřené období DPH', () => {
  it.each(['monthly', 'quarterly'] as const)('řídí %s účetní zónou i v prohlížeči používajícím UTC', period => {
    const now = new Date('2026-09-30T22:30:00Z')
    vi.spyOn(now, 'getMonth').mockReturnValue(now.getUTCMonth())
    vi.spyOn(now, 'getFullYear').mockReturnValue(now.getUTCFullYear())
    expect(lastClosedVatPeriod(period, now)).toEqual({ year: 2026, month: 9 })
  })

  it.each([
    [new Date(2026, 0, 1), 'monthly', 2025, 12],
    [new Date(2026, 0, 31), 'quarterly', 2025, 12],
    [new Date(2026, 2, 31), 'quarterly', 2025, 12],
    [new Date(2026, 3, 1), 'quarterly', 2026, 3],
    [new Date(2026, 9, 15), 'quarterly', 2026, 9],
    [new Date(2026, 9, 15), 'monthly', 2026, 9],
  ] as const)('%s / %s => %s-%s', (now, period, year, month) => {
    expect(lastClosedVatPeriod(period, now)).toEqual({ year, month })
  })
})

describe('období výkazů v relaci uživatele a firmy', () => {
  it('čeká na nastavení a nepovažuje předběžný měsíc za hotový výběr', async () => {
    let resolve!: (settings: unknown) => void
    m.settings.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const period = create()
    const loading = period.initialize()
    expect(period.ready.value).toBe(false)
    resolve({ vat_period: 'quarterly', taxpayer_type: 'fo' })
    expect(await loading).toBe(true)
    expect([period.year.value, period.month.value, period.effectivePeriod.value]).toEqual([2026, 9, 'quarterly'])
  })

  it('přenese výběr do dalšího výkazu, ale nemění již otevřený výkaz', async () => {
    const first = create()
    await first.initialize()
    first.year.value = 2024
    first.month.value = 6
    first.periodOverride.value = 'monthly'
    const second = create()
    await second.initialize()
    expect([second.year.value, second.month.value, second.effectivePeriod.value]).toEqual([2024, 6, 'monthly'])
    second.month.value = 8
    expect(first.month.value).toBe(6)
  })

  it('SH začíná měsíčně i u čtvrtletního plátce a jeho periodicita nemění DPH', async () => {
    const vat = create()
    await vat.initialize()
    vat.year.value = 2026
    vat.month.value = 6
    const sh = create('sh')
    await sh.initialize()
    expect([sh.year.value, sh.month.value, sh.effectivePeriod.value]).toEqual([2026, 6, 'monthly'])
    sh.periodOverride.value = 'quarterly'
    sh.periodOverride.value = 'monthly'
    const nextVat = create()
    await nextVat.initialize()
    expect([nextVat.year.value, nextVat.month.value, nextVat.effectivePeriod.value]).toEqual([2026, 6, 'quarterly'])
    const nextSh = create('sh')
    await nextSh.initialize()
    expect(nextSh.effectivePeriod.value).toBe('monthly')
  })

  it('rozpis z výkazu (remember=0) zobrazí období odkazu, ale zapamatovanou volbu nezmění', async () => {
    const vat = create()
    await vat.initialize()
    m.route.query = { year: '2026', month: '8', period: 'monthly', remember: '0' }
    const book = create()
    await book.initialize()
    expect([book.year.value, book.month.value, book.effectivePeriod.value]).toEqual([2026, 8, 'monthly'])
    m.route.query = {}
    const next = create()
    await next.initialize()
    expect([next.year.value, next.month.value, next.effectivePeriod.value]).toEqual([2026, 9, 'quarterly'])
  })

  it('SH si pamatuje vlastní čtvrtletní volbu', async () => {
    const sh = create('sh')
    await sh.initialize()
    expect([sh.year.value, sh.month.value, sh.effectivePeriod.value]).toEqual([2026, 9, 'monthly'])
    sh.periodOverride.value = 'quarterly'
    const again = create('sh')
    await again.initialize()
    expect(again.effectivePeriod.value).toBe('quarterly')
    const vat = create()
    await vat.initialize()
    expect(vat.periodOverride.value).toBe('')
  })

  it('výchozí období zůstane v relaci i po překročení hranice kvartálu', async () => {
    const first = create()
    await first.initialize()
    vi.setSystemTime(new Date(2027, 0, 1))
    const second = create()
    await second.initialize()
    expect([second.year.value, second.month.value, second.effectivePeriod.value]).toEqual([2026, 9, 'quarterly'])
  })

  it('platné hodnoty URL mají přednost včetně budoucího období; neplatné hodnoty ignoruje', async () => {
    const first = create()
    await first.initialize()
    first.year.value = 2023
    first.month.value = 2
    m.route.query = { year: '2099', month: '12', period: 'monthly' }
    await nextTick()
    expect([first.year.value, first.month.value, first.effectivePeriod.value]).toEqual([2099, 12, 'monthly'])
    m.route.query = { year: '2200', month: '13', period: 'bad' }
    await nextTick()
    expect([first.year.value, first.month.value, first.effectivePeriod.value]).toEqual([2099, 12, 'monthly'])
  })

  it('návrat běžným menu obnoví zapamatovaný výběr', async () => {
    m.route.query = { year: '2024', month: '7', period: 'monthly' }
    const period = create()
    await period.initialize()
    m.route.query = {}
    await nextTick()
    expect([period.year.value, period.month.value, period.effectivePeriod.value]).toEqual([2024, 7, 'monthly'])
  })

  it('ruční změna explicitního období aktualizuje odkaz a zůstane i po obnovení', async () => {
    m.route.query = { year: '2025', month: '9', period: 'quarterly', section: 'a4' }
    const first = create()
    await first.initialize()
    first.month.value = 6
    await nextTick()
    await nextTick()
    expect(m.route.query).toEqual({ year: '2025', month: '6', period: 'quarterly', section: 'a4' })
    const reopened = create()
    await reopened.initialize()
    expect([reopened.year.value, reopened.month.value]).toEqual([2025, 6])
  })

  it('ruční změny roku, měsíce a periodicity synchronizuje jedinou navigací', async () => {
    m.route.query = { year: '2025', month: '9', period: 'quarterly' }
    const period = create()
    await period.initialize()
    period.year.value = 2024
    period.month.value = 4
    period.periodOverride.value = 'monthly'
    await nextTick()
    await nextTick()
    expect(m.replace).toHaveBeenCalledTimes(1)
    expect(m.route.query).toEqual({ year: '2024', month: '4', period: 'monthly' })
    await nextTick()
    expect(m.replace).toHaveBeenCalledTimes(1)
  })

  it('nový příchozí odkaz má přednost před čekající ruční synchronizací', async () => {
    m.route.query = { year: '2025', month: '9', period: 'quarterly' }
    const period = create()
    await period.initialize()
    period.month.value = 6
    m.route.query = { year: '2023', month: '4', period: 'monthly' }
    await nextTick()
    await nextTick()
    expect([period.year.value, period.month.value, period.effectivePeriod.value]).toEqual([2023, 4, 'monthly'])
    expect(m.replace).not.toHaveBeenCalled()
  })

  it('běžná adresa zůstane bez parametrů období a pamatuje ruční výběr', async () => {
    m.route.query = { section: 'a4' }
    const period = create()
    await period.initialize()
    period.month.value = 6
    await nextTick()
    expect(m.replace).not.toHaveBeenCalled()
    expect(m.route.query).toEqual({ section: 'a4' })
    const reopened = create()
    await reopened.initialize()
    expect(reopened.month.value).toBe(6)
  })

  it('změna nesouvisejícího parametru nepřevezme období z jiného otevřeného výkazu', async () => {
    m.route.query = { section: 'a4' }
    const first = create()
    await first.initialize()
    const second = create()
    await second.initialize()
    second.month.value = 6
    await nextTick()
    m.route.query = { section: 'b3' }
    await nextTick()
    expect(first.month.value).toBe(9)
    expect(second.month.value).toBe(6)
  })

  it('nucenou měsíční periodicitu zachová i v aktualizovaném odkazu', async () => {
    m.route.query = { year: '2025', month: '9', period: 'quarterly' }
    const period = create('monthly')
    await period.initialize()
    period.month.value = 6
    await nextTick()
    await nextTick()
    expect(m.route.query).toEqual({ year: '2025', month: '6', period: 'monthly' })
  })

  it('samotná periodicita URL určí poslední uzavřený kvartál i u měsíční firmy', async () => {
    vi.setSystemTime(new Date(2026, 4, 15))
    m.settings.mockResolvedValue({ vat_period: 'monthly', taxpayer_type: 'fo' })
    m.route.query = { period: 'quarterly' }
    const period = create()
    await period.initialize()
    expect([period.year.value, period.month.value, period.effectivePeriod.value]).toEqual([2026, 3, 'quarterly'])
  })

  it('ignoruje poškozený nebo neplatný záznam relace', async () => {
    sessionStorage.setItem('myinvoice.vat-report-period.11.22.vat', '{bad')
    const period = create()
    await period.initialize()
    expect(period.month.value).toBe(9)
    sessionStorage.setItem('myinvoice.vat-report-period.11.22.vat', JSON.stringify({ year: 1900, month: 13, periodOverride: 'quarterly' }))
    await period.initialize()
    expect([period.year.value, period.month.value]).toEqual([2026, 9])
  })

  it('odhlášení smaže pouze paměť DPH a další výkaz použije uzavřené období', async () => {
    const period = create()
    await period.initialize()
    period.year.value = 2023
    sessionStorage.setItem('myinvoice.window-lock', 'keep')
    clearVatReportPeriodSession()
    const fresh = create()
    await fresh.initialize()
    expect(fresh.year.value).toBe(2026)
    expect(sessionStorage.getItem('myinvoice.window-lock')).toBe('keep')
  })

  it.each(['kh', 'oss', 'monthly'] as const)('%s nemění společnou periodicitu firmy', async kind => {
    m.settings.mockResolvedValue({ vat_period: 'quarterly', taxpayer_type: 'po' })
    const common = create()
    await common.initialize()
    common.year.value = 2025
    common.month.value = 6
    const forced = create(kind)
    await forced.initialize()
    expect([forced.year.value, forced.month.value]).toEqual([2025, 6])
    expect(forced.effectivePeriod.value).toBe(kind === 'oss' ? 'quarterly' : 'monthly')
    forced.month.value = 7
    const later = create()
    await later.initialize()
    expect([later.month.value, later.effectivePeriod.value]).toEqual([6, 'quarterly'])
  })

  it('odděluje uživatele, firmy i pevný kontext panelu', async () => {
    const first = create()
    await first.initialize()
    first.year.value = 2024
    m.supplier.currentSupplierId = 23
    await nextTick()
    await first.initialize()
    expect(first.year.value).toBe(2026)
    first.year.value = 2023
    m.auth.user.id = 12
    await nextTick()
    await first.initialize()
    expect(first.year.value).toBe(2026)
    m.auth.user.id = 11
    m.frameSupplier = '22'
    const frame = create()
    await frame.initialize()
    expect(frame.year.value).toBe(2024)
  })

  it('funguje při zablokovaném sessionStorage', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked') })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked') })
    const period = create()
    expect(await period.initialize()).toBe(true)
    expect([period.month.value, period.effectivePeriod.value]).toEqual([9, 'quarterly'])
    period.month.value = 2
  })

  it('chyba nastavení nedovolí náhled a opakování obnoví explicitní URL', async () => {
    m.settings.mockRejectedValueOnce(new Error('offline'))
    m.route.query = { year: '2025', month: '4', period: 'monthly' }
    const period = create()
    expect(await period.initialize()).toBe(false)
    expect(period.ready.value).toBe(false)
    expect(period.settingsError.value).toBeInstanceOf(Error)
    expect(await period.initialize()).toBe(true)
    expect([period.year.value, period.month.value, period.effectivePeriod.value]).toEqual([2025, 4, 'monthly'])
  })

  it('zahodí odpověď nastavení předchozí firmy', async () => {
    let resolve!: (settings: unknown) => void
    m.settings.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const period = create()
    const old = period.initialize()
    m.supplier.currentSupplierId = 23
    await nextTick()
    await period.initialize()
    resolve({ vat_period: 'monthly', taxpayer_type: 'fo' })
    expect(await old).toBe(false)
    expect(period.effectivePeriod.value).toBe('quarterly')
  })

  it('při změně firmy reaguje na store dříve než na následný zápis localStorage', async () => {
    let resolveOld!: (settings: unknown) => void
    m.storedSupplier = '22'
    m.settings.mockImplementationOnce(() => new Promise(done => { resolveOld = done }))
    const period = create()
    const old = period.initialize()
    m.supplier.currentSupplierId = 23
    m.storedSupplier = '23'
    await nextTick()
    expect(m.settings).toHaveBeenCalledTimes(2)
    resolveOld({ vat_period: 'monthly', taxpayer_type: 'po' })
    expect(await old).toBe(false)
    await nextTick()
    expect(period.ready.value).toBe(true)
    expect(period.settings.value?.taxpayer_type).toBe('fo')
    expect(period.effectivePeriod.value).toBe('quarterly')
  })
})
