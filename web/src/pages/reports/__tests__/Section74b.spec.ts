import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { S74bPreview } from '@/api/reports'

// ── Mockovaný stav (hoisted, ať na něj mají mock-factory přístup) ─────────────
const m = vi.hoisted(() => ({
  s74bPreview: vi.fn(),
  s74bRecord: vi.fn(),
  canRead: true,
  canWrite: true,
}))

// API vrstva — jen §74b endpointy (preview = dry-run, record = evidence).
vi.mock('@/api/reports', () => ({
  reportsApi: {
    s74bPreview: m.s74bPreview,
    s74bRecord: m.s74bRecord,
  },
}))

// apiErrorMessage — jednoduchý průchod, ať se chybová větev nechytne na importu.
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e),
}))

vi.mock('@/composables/useVatReportPeriod', async () => {
  const { ref } = await import('vue')
  return { useVatReportPeriod: () => {
    const ready = ref(false)
    return { year: ref(2026), month: ref(7), ready, settingsError: ref(null),
      initialize: async () => { ready.value = true; return true } }
  } }
})

// vue-router — RouterLink jako jednoduchý stub (proklik na detail dokladu).
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

// useYearOptions dělá síťové volání codebooksApi.years() — nahradíme stabilním computed.
vi.mock('@/composables/useYearOptions', async () => {
  const { computed } = await import('vue')
  return { useYearOptions: () => computed(() => [2026, 2025]) }
})

// i18n stub — `t` vrací klíč (na překlady se v testu nespoléháme), locale je čtené jen jako .value.
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

// Auth store — canRead/canWrite řízené z mock-stavu (m.canRead / m.canWrite).
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => m.canRead,
    canWrite: () => m.canWrite,
  }),
}))

// Import až PO mockách (Vitest hoistuje vi.mock, ale kvůli čitelnosti explicitně dole).
import Section74b from '@/pages/reports/Section74b.vue'

function makePreview(overrides: Partial<S74bPreview> = {}): S74bPreview {
  return {
    period: { year: 2026, month: 7, period_end: '2026-07-31' },
    rows: [
      {
        purchase_invoice_id: 1,
        vendor_name: 'Dodavatel Alfa s.r.o.',
        vendor_dic: 'CZ11111111',
        vendor_invoice_number: 'FP-2026-001',
        tax_date: '2026-01-15',
        due_date: '2026-02-15',
        total_with_vat: 12100,
        claimed_deduction_vat: 2100,
        unpaid_ratio: 1,
        aged: true,
        target_reduction: 2100,
        net_corrected: 2100,
        delta: -2100,
        movement: 'reduction',
        state: 'aged',
        dphdp3_line_hint: null,
        kh_zdph_44: false,
      },
      {
        purchase_invoice_id: 2,
        vendor_name: 'Dodavatel Beta a.s.',
        vendor_dic: 'CZ22222222',
        vendor_invoice_number: 'FP-2026-002',
        tax_date: '2026-02-10',
        due_date: '2026-03-10',
        total_with_vat: 6050,
        claimed_deduction_vat: 1050,
        unpaid_ratio: 0,
        aged: true,
        target_reduction: 0,
        net_corrected: 0,
        delta: 1050,
        movement: 'restoration',
        state: 'restored',
        dphdp3_line_hint: null,
        kh_zdph_44: false,
      },
    ],
    totals: { reduction: 2100, restoration: 1050, net_delta: -1050 },
    ...overrides,
  }
}

// Normalizace whitespace — Intl (cs-CZ) používá NBSP/narrow-NBSP jako oddělovač tisíců,
// tak porovnáváme bez mezer, ať test nespadne na typu mezery.
const norm = (s: string) => s.replace(/\s+/g, '')
const fmtMoney = (v: number) =>
  new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)

function recordButtons(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('button').filter((b) => b.text().includes('reports.s74b.action_record'))
}

describe('Section74b.vue', () => {
  beforeEach(() => {
    m.canRead = true
    m.canWrite = true
    m.s74bPreview.mockReset()
    m.s74bRecord.mockReset()
  })

  it('(a) po načtení vykreslí řádky z preview a souhrnné totals', async () => {
    m.s74bPreview.mockResolvedValue(makePreview())
    const wrapper = mount(Section74b)
    await flushPromises()

    // preview endpoint zavolán pro aktuální období
    expect(m.s74bPreview).toHaveBeenCalledTimes(1)

    // řádky odpovídají preview.rows
    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(wrapper.text()).toContain('Dodavatel Alfa s.r.o.')
    expect(wrapper.text()).toContain('Dodavatel Beta a.s.')

    // souhrn (3 karty totals) je vykreslený a obsahuje částky
    const text = norm(wrapper.text())
    expect(text).toContain(norm('reports.s74b.totals.reduction'))
    expect(text).toContain(norm('reports.s74b.totals.restoration'))
    expect(text).toContain(norm('reports.s74b.totals.net_delta'))
    expect(text).toContain(norm(fmtMoney(2100)))
    expect(text).toContain(norm(fmtMoney(1050)))
  })

  it('(a2) sloupec „Doklad" je RouterLink na detail přijaté faktury se správným id', async () => {
    m.s74bPreview.mockResolvedValue(makePreview())
    const wrapper = mount(Section74b)
    await flushPromises()

    const links = wrapper.findAllComponents({ name: 'RouterLink' })
    // Odkaz na první řádek (purchase_invoice_id = 1, vendor_invoice_number = 'FP-2026-001')
    const docLink = links.find((l) => l.text() === 'FP-2026-001')
    expect(docLink).toBeTruthy()
    expect(docLink!.props('to')).toEqual({
      name: 'purchase-invoice-detail',
      params: { id: 1 },
    })

    // Prázdné číslo dokladu → fallback na #{id}
    m.s74bPreview.mockResolvedValue(
      makePreview({
        rows: [{ ...makePreview().rows[0], purchase_invoice_id: 7, vendor_invoice_number: null }],
      }),
    )
    const wrapper2 = mount(Section74b)
    await flushPromises()
    const fallback = wrapper2
      .findAllComponents({ name: 'RouterLink' })
      .find((l) => l.text() === '#7')
    expect(fallback).toBeTruthy()
    expect(fallback!.props('to')).toEqual({
      name: 'purchase-invoice-detail',
      params: { id: 7 },
    })
  })

  it('(b) tlačítko „Zaevidovat období" je vidět jen s canWrite(reports.finalize)', async () => {
    // s právem zápisu → tlačítko record viditelné
    m.canWrite = true
    m.s74bPreview.mockResolvedValue(makePreview())
    const withWrite = mount(Section74b)
    await flushPromises()
    expect(recordButtons(withWrite)).toHaveLength(1)

    // bez práva zápisu → tlačítko record skryté (zůstane jen preview)
    m.canWrite = false
    m.s74bPreview.mockResolvedValue(makePreview())
    const readOnly = mount(Section74b)
    await flushPromises()
    expect(recordButtons(readOnly)).toHaveLength(0)
  })

  it('(c) prázdný stav — žádné řádky → hláška no_data a nulová tabulka', async () => {
    m.s74bPreview.mockResolvedValue(
      makePreview({ rows: [], totals: { reduction: 0, restoration: 0, net_delta: 0 } }),
    )
    const wrapper = mount(Section74b)
    await flushPromises()

    expect(wrapper.findAll('tbody tr')).toHaveLength(0)
    expect(wrapper.text()).toContain('reports.s74b.no_data')
  })
})
