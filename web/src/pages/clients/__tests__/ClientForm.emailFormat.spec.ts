import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Předmět e-mailu a název přiloženého PDF podle klienta (#277): sekce
 * „E-mail s fakturou" na kartě klienta — výchozí text jako placeholder, živá
 * ukázka, odeslání v payloadu a serverová chyba u pole.
 */
const m = vi.hoisted(() => ({
  create: vi.fn(),
  update: vi.fn(),
  get: vi.fn(),
  params: {} as Record<string, string>,
}))

vi.mock('@/api/clients', () => ({
  clientsApi: {
    create: m.create,
    update: m.update,
    get: m.get,
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
vi.mock('@/api/settings', () => ({ settingsApi: { listBrandingProfiles: vi.fn(async () => []) } }))
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
  useSupplierStore: () => ({
    currentSupplier: { accounting_mode: 'double_entry', country_iso2: 'CZ', company_name: 'Dodavatel s.r.o.' },
  }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: m.params, query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
// Parametry se vypisují, aby šlo ověřit text ukázky.
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => (params ? `${key} ${JSON.stringify(params)}` : key),
    locale: { value: 'cs' },
  }),
}))

import ClientForm from '../ClientForm.vue'

const SUBJECT = '#client-email-subject-format'
const ATTACHMENT = '#client-email-attachment-name-format'

function existingClient(extra: Record<string, unknown> = {}) {
  return {
    id: 5, company_name: 'Klient a.s.', street: 'Ulice 1', city: 'Praha', zip: '11000', country_iso2: 'CZ',
    main_email: 'klient@example.test', language: 'cs', currency_default_id: 1, reverse_charge: false,
    is_customer: true, is_vendor: false, email_contacts: [], bank_accounts: [], ...extra,
  }
}

// Editace uloženého klienta běží jen jako samostatná stránka (embedded = nový v modálu).
async function mountForm(embedded = true) {
  const wrapper = mount(ClientForm, { props: { embedded } })
  await flushPromises()
  return wrapper
}

describe('ClientForm — e-mail s fakturou podle klienta', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.params = {}
    Element.prototype.scrollIntoView = vi.fn()
    // Ukázka počítá s fakturou vystavenou dnes za předchozí měsíc.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-06T10:00:00Z'))
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('u nového klienta je sekce zavřená a výchozí text je jen placeholder', async () => {
    const wrapper = await mountForm()

    const details = wrapper.find(SUBJECT).element.closest('details')!
    expect(details.open).toBe(false)
    expect(wrapper.find(SUBJECT).attributes('placeholder')).toBe('Faktura {VS} — {DODAVATEL}')
    expect(wrapper.find(ATTACHMENT).attributes('placeholder')).toBe('Faktura-{VS}')
    expect((wrapper.find(SUBJECT).element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).not.toContain('client.email_format_preview')
  })

  it('ukáže živou ukázku vyplněného formátu', async () => {
    const wrapper = await mountForm()

    await wrapper.find(SUBJECT).setValue('Klient_{DUZP_MM}_{DUZP_YYYY}_{DODAVATEL}')
    await wrapper.find(ATTACHMENT).setValue('Dodavatel_{DUZP_MM}_{DUZP_YYYY}')

    expect(wrapper.text()).toContain('"value":"Klient_09_2026_Dodavatel s.r.o."')
    expect(wrapper.text()).toContain('"value":"Dodavatel_09_2026.pdf"')
    expect(wrapper.text()).toContain('"vs":"2610001"')
  })

  it('pošle formáty v payloadu nového klienta', async () => {
    m.create.mockResolvedValue({ id: 9 })
    const wrapper = await mountForm()

    await wrapper.find(SUBJECT).setValue('Klient_{DUZP_MM}')
    await wrapper.find(ATTACHMENT).setValue('Dodavatel_{MM}')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      email_subject_format: 'Klient_{DUZP_MM}',
      email_attachment_name_format: 'Dodavatel_{MM}',
    }))
  })

  it('u uloženého klienta s formátem sekci rovnou otevře', async () => {
    m.params = { id: '5' }
    m.get.mockResolvedValue(existingClient({ email_attachment_name_format: 'Dodavatel_{MM}' }))

    const wrapper = await mountForm(false)

    expect(wrapper.find(ATTACHMENT).element.closest('details')!.open).toBe(true)
    expect((wrapper.find(ATTACHMENT).element as HTMLInputElement).value).toBe('Dodavatel_{MM}')
  })

  it('serverovou chybu formátu ukáže u pole a sekci otevře', async () => {
    m.create.mockImplementation(() => Promise.reject({
      response: { data: { error: {
        code: 'validation_failed',
        message: 'Validace selhala',
        fields: { email_subject_format: ['Neznámý zástupný znak {CISLO}.'] },
      } } },
    }))
    const wrapper = await mountForm()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const details = wrapper.find(SUBJECT).element.closest('details')!
    expect(details.open).toBe(true)
    expect(details.textContent).toContain('Neznámý zástupný znak {CISLO}.')
  })

  it.each([null, true, false])('nový klient uloží tříhodnotovou volbu ISDOC %s', async choice => {
    m.create.mockResolvedValue({ id: 9 })
    const wrapper = await mountForm()
    const select = wrapper.find('#client-email-attach-isdoc')
    expect((select.element as HTMLSelectElement).selectedIndex).toBe(0)
    const optionIndex = choice === null ? 0 : choice ? 1 : 2
    await select.setValue((select.findAll('option')[optionIndex]!.element as HTMLOptionElement).value)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({ email_attach_isdoc: choice }))
  })

  it.each([
    { name: 'zděděná volba', choice: null, index: 0 },
    { name: 'zapnuto', choice: true, index: 1 },
    { name: 'vypnuto', choice: false, index: 2 },
    { name: 'starší odpověď bez pole', choice: undefined, index: 0 },
  ])('editace načte a zachová ISDOC: $name', async ({ choice, index }) => {
    m.params = { id: '5' }
    m.get.mockResolvedValue(existingClient(choice === undefined ? {} : { email_attach_isdoc: choice }))
    m.update.mockResolvedValue(existingClient({ email_attach_isdoc: choice ?? null }))
    const wrapper = await mountForm(false)
    const select = wrapper.find('#client-email-attach-isdoc')
    expect((select.element as HTMLSelectElement).selectedIndex).toBe(index)
    expect(select.element.closest('details')!.open).toBe(choice != null)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(5, expect.objectContaining({ email_attach_isdoc: choice ?? null }))
  })

  it.each([
    { initial: null, choice: true, index: 1 },
    { initial: true, choice: false, index: 2 },
    { initial: false, choice: null, index: 0 },
  ])('editace a opětovné načtení zachová změnu ISDOC $initial → $choice', async ({ initial, choice, index }) => {
    m.params = { id: '5' }
    m.get.mockResolvedValue(existingClient({ email_attach_isdoc: initial }))
    m.update.mockImplementation(async (_id, payload) => existingClient(payload))
    const wrapper = await mountForm(false)
    const select = wrapper.find('#client-email-attach-isdoc')
    await select.setValue((select.findAll('option')[index]!.element as HTMLOptionElement).value)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(5, expect.objectContaining({ email_attach_isdoc: choice }))
    const saved = await m.update.mock.results[0]!.value
    wrapper.unmount()
    m.get.mockResolvedValue(saved)
    const reloaded = await mountForm(false)
    expect((reloaded.find('#client-email-attach-isdoc').element as HTMLSelectElement).selectedIndex).toBe(index)
  })

  it('serverovou chybu ISDOC ukáže u volby a otevře sekci', async () => {
    m.create.mockRejectedValue({ response: { data: { error: {
      code: 'validation_failed', message: 'Validace selhala',
      fields: { email_attach_isdoc: ['Neplatná volba ISDOC.'] },
    } } } })
    const wrapper = await mountForm()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    const details = wrapper.find('#client-email-attach-isdoc').element.closest('details')!
    expect(details.open).toBe(true)
    expect(details.textContent).toContain('Neplatná volba ISDOC.')
  })

})
