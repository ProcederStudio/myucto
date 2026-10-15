import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { i18n } from '@/i18n'
import type { TaxCalendarItem } from '@/api/crm'

const mock = vi.hoisted(() => ({ taxCalendar: vi.fn() }))
vi.mock('@/api/crm', () => ({ crmApi: { taxCalendar: mock.taxCalendar } }))

const locale = ref('cs')
const translations: Record<string, Record<string, string>> = {
  cs: {
    'crm.tax_calendar.title': 'Daňový kalendář',
    'crm.tax_calendar.period_month': '{month} {year}',
    'crm.tax_calendar.period_quarter': '{quarter}. čtvrtletí {year}',
    'crm.tax_calendar.period_year': '{year}',
    'crm.tax_calendar.filing_deadline': 'Termín podání',
    'crm.tax_calendar.due_date': 'Splatnost',
    'crm.tax_calendar.deadline': 'Termín',
    'crm.tax_calendar.submitted': 'Podáno',
    'crm.tax_calendar.not_submitted': 'Nepodáno',
    'crm.tax_calendar.paid': 'Zaplaceno',
    'crm.tax_calendar.due': 'Splatné',
    'crm.tax_calendar.to_pay': 'K úhradě',
  },
  en: {
    'crm.tax_calendar.title': 'Tax calendar',
    'crm.tax_calendar.period_month': '{month} {year}',
    'crm.tax_calendar.period_quarter': 'Q{quarter} {year}',
    'crm.tax_calendar.period_year': '{year}',
    'crm.tax_calendar.filing_deadline': 'Filing deadline',
    'crm.tax_calendar.due_date': 'Due date',
    'crm.tax_calendar.deadline': 'Deadline',
    'crm.tax_calendar.submitted': 'Filed',
    'crm.tax_calendar.not_submitted': 'Not filed',
    'crm.tax_calendar.paid': 'Paid',
    'crm.tax_calendar.due': 'Due',
    'crm.tax_calendar.to_pay': 'To pay',
  },
}

vi.mock('vue-i18n', async importOriginal => {
  const actual = await importOriginal<typeof import('vue-i18n')>()
  return {
    ...actual,
    useI18n: () => ({
      locale,
      t: (key: string, params: Record<string, string | number> = {}) =>
        Object.entries(params).reduce((value, [name, replacement]) => value.replace(`{${name}}`, String(replacement)), translations[locale.value]?.[key] ?? key),
    }),
  }
})

import TaxCalendarWidget from '../TaxCalendarWidget.vue'

function makeItems(quarterly = true): TaxCalendarItem[] {
  const period = quarterly
    ? { year: 2026, month: null, quarter: 3 }
    : { year: 2026, month: 7, quarter: null }
  const vatLink = quarterly
    ? '/reports/dph?year=2026&month=7&period=quarterly'
    : '/reports/dph?year=2026&month=7'
  return [
    {
      type: 'tax_deadline',
      title: 'DPH přiznání',
      deadline: '2026-10-26',
      days: 11,
      link: vatLink,
      period,
      submitted: false,
    },
    {
      type: 'income_tax_deadline',
      title: 'Daň z příjmů',
      deadline: '2026-07-01',
      days: -100,
      link: '/reports/income-tax?year=2025',
      period: { year: 2025, month: null, quarter: null },
      submitted: true,
    },
    {
      type: 'tax_advance',
      title: 'Záloha na daň',
      deadline: '2026-12-15',
      days: 61,
      link: '/reports/income-tax',
      submitted: null,
      status: 'planned',
    },
    {
      type: 'vat_registration_deadline',
      title: 'Registrace k DPH',
      deadline: '2026-11-01',
      days: 17,
      link: '/admin/settings',
      submitted: false,
    },
  ]
}

async function render(localeCode: 'cs' | 'en', quarterly = true) {
  locale.value = localeCode
  i18n.global.locale.value = localeCode
  const items = makeItems(quarterly)
  mock.taxCalendar.mockResolvedValue({ items, total: items.length })
  const wrapper = mount(TaxCalendarWidget, {
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :href="to"><slot /></a>',
        },
      },
    },
  })
  await flushPromises()
  return { wrapper, items }
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('TaxCalendarWidget', () => {
  it('shows Czech reporting periods and labels deadlines while preserving backend links', async () => {
    const { wrapper } = await render('cs')
    const text = wrapper.text()

    expect(text).toContain('3. čtvrtletí 2026')
    expect(text).toContain('2025')
    expect(text).toContain('Termín podání: 26. 10. 2026')
    expect(text).toContain('Splatnost: 15. 12. 2026')
    expect(text).toContain('Termín: 01. 11. 2026')
    expect(wrapper.findAll('a').map(link => link.attributes('href'))).toEqual(makeItems().map(item => item.link))
    expect(wrapper.findAll('a')[2].text()).not.toContain('·')
    wrapper.unmount()

    const { wrapper: monthly } = await render('cs', false)
    expect(monthly.text()).toContain('červenec 2026')
    monthly.unmount()
  })

  it('localizes month and quarter periods and deadline labels in English', async () => {
    const { wrapper } = await render('en')
    expect(wrapper.text()).toContain('Q3 2026')
    expect(wrapper.text()).toContain('2025')
    expect(wrapper.text()).toContain('Filing deadline: 10/26/2026')
    expect(wrapper.text()).toContain('Due date: 12/15/2026')
    expect(wrapper.text()).toContain('Deadline: 11/01/2026')

    wrapper.unmount()

    const { wrapper: monthly } = await render('en', false)
    expect(monthly.text()).toContain('July 2026')
    monthly.unmount()
  })
})
