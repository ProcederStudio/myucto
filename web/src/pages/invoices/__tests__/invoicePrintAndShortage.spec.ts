import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ActionItem } from '@/components/ui/ActionBar.vue'

// Detail faktury: akce Tisk vedle zobrazení PDF a okno nedostatku zásob místo
// toastu, když vystavení zablokuje sklad.

const m = vi.hoisted(() => ({
  invoice: {} as Record<string, unknown>,
  issue: vi.fn(),
  printPdf: vi.fn(),
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}))

function baseInvoice(over: Record<string, unknown> = {}) {
  return {
    id: 9, supplier_id: 1, status: 'sent', invoice_type: 'invoice',
    varsymbol: '2610001', currency: 'CZK', total: 1210, amount_to_pay: 1210,
    issue_date: '2026-10-01', tax_date: '2026-10-01', due_date: '2026-10-15',
    items: [{ id: 1, description: 'Šroub', quantity: 5, unit_price_without_vat: 100 }],
    public_token: null, public_viewed_at: null,
    ...over,
  }
}

function emptyApi(overrides: Record<string, unknown> = {}) {
  return new Proxy(overrides, {
    get: (target, key: string) => (key in target ? target[key] : vi.fn().mockResolvedValue([])),
  })
}

vi.mock('@/api/invoices', () => ({
  invoicesApi: emptyApi({
    get: vi.fn(async () => m.invoice),
    issue: m.issue,
    pdfUrl: (id: number, download = false) => `/api/invoices/${id}/pdf${download ? '?download=1' : ''}`,
  }),
}))
vi.mock('@/api/settings', () => ({ settingsApi: emptyApi() }))
vi.mock('@/api/admin', () => ({ adminApi: emptyApi() }))
vi.mock('@/api/stock', () => ({ stockApi: emptyApi() }))
vi.mock('@/api/eshop', () => ({ eshopApi: emptyApi() }))
vi.mock('@/api/accounting', () => ({ accountingApi: emptyApi(), postingErrorI18nKey: () => 'error' }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: emptyApi() }))
vi.mock('@/composables/usePrintPdf', () => ({ printPdf: m.printPdf }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => true,
    isClientRole: false,
    isSuperadmin: false,
    isCompanyAdminRole: true,
    hasCommercialFeatures: true,
    invoicePublicLinksEnabled: true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_records', stock_enabled: true } }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => m.toast }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, te: () => false, locale: { value: 'cs' } }),
}))

import InvoiceDetail from '../InvoiceDetail.vue'

type DetailVm = { invoiceActions: ActionItem[]; insufficientStock: unknown[] | null }

async function mountDetail(invoice: Record<string, unknown>) {
  m.invoice = invoice
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:p(.*)*', component: { render: () => null } }],
  })
  await router.push('/invoices/9')
  const wrapper = mount({ ...InvoiceDetail, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

afterEach(() => { vi.clearAllMocks() })

describe('InvoiceDetail: tisk', () => {
  it('nabízí Tisk stejně jako zobrazení PDF a tiskne náhledové PDF faktury', async () => {
    const wrapper = await mountDetail(baseInvoice())
    const actions = (wrapper.vm as unknown as DetailVm).invoiceActions
    const view = actions.find(a => a.key === 'view-pdf')!
    const print = actions.find(a => a.key === 'print')
    expect(print).toBeDefined()
    expect(print!.show).toBe(view.show)
    expect(actions.indexOf(print!)).toBe(actions.indexOf(view) + 1)

    m.printPdf.mockResolvedValue('printed')
    await print!.run!()
    expect(m.printPdf).toHaveBeenCalledWith('/invoices/9/pdf', '/api/invoices/9/pdf')
    wrapper.unmount()
  })

  it('chybu tisku oznámí toastem s odkazem na zobrazení PDF (nové okno až po kliknutí)', async () => {
    const wrapper = await mountDetail(baseInvoice())
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    m.printPdf.mockRejectedValue(new Error('print frame timeout'))
    await (wrapper.vm as unknown as DetailVm).invoiceActions.find(a => a.key === 'print')!.run!()
    await flushPromises()
    expect(open).not.toHaveBeenCalled()
    expect(m.toast.error).toHaveBeenCalledWith('invoice.print_failed', expect.objectContaining({ label: 'invoice.view_pdf' }))
    const action = m.toast.error.mock.calls[0][1] as { handler: () => void }
    action.handler()
    expect(open).toHaveBeenCalledWith('/api/invoices/9/pdf', '_blank', 'noopener')
    open.mockRestore()
    wrapper.unmount()
  })
})

describe('InvoiceDetail: nedostatek zásob při vystavení', () => {
  it('otevře okno s položkami místo toastu', async () => {
    vi.stubGlobal('confirm', () => true)
    const items = [{ stock_item_id: 7, sku: 'SR-10', name: 'Šroub', requested: '5.000', available: '2.000', warehouse_id: 3, warehouse_name: 'Prodejna', unit: 'ks' }]
    m.issue.mockRejectedValue({ response: { status: 409, data: { error: { code: 'stock.error.insufficient_stock', message: 'Nedostatek', items } } } })
    const wrapper = await mountDetail(baseInvoice({ status: 'draft', varsymbol: null }))
    const vm = wrapper.vm as unknown as DetailVm
    await vm.invoiceActions.find(a => a.key === 'issue')!.run!()
    await flushPromises()
    expect(vm.insufficientStock).toEqual(items)
    expect(m.toast.error).not.toHaveBeenCalled()
    wrapper.unmount()
    vi.unstubAllGlobals()
  })
})
