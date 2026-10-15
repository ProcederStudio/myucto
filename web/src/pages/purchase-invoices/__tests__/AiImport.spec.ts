import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import type { SupplierBrief, User } from '@/api/auth'
import type { AiExtractionStatus } from '@/api/integrations'
import AiImport from '../AiImport.vue'

const m = vi.hoisted(() => ({ status: vi.fn(), extract: vi.fn(), batches: vi.fn(), push: vi.fn() }))
vi.mock('@/api/integrations', () => ({ integrationsApi: { getAiExtractionStatus: m.status, extractPdfAi: m.extract } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { listImportBatches: m.batches } }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: m.push }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('vue-i18n', async (importOriginal) => ({ ...(await importOriginal<typeof import('vue-i18n')>()), useI18n: () => ({ t: (key: string) => key }) }))

const status = (enabled = true, configured = true): AiExtractionStatus => ({
  ai_provider: 'anthropic', client_ai_extraction_enabled: enabled,
  providers: { anthropic: { configured, models: ['synthetic-model'], default_model: 'synthetic-model' } },
})
function setupRole(client = true) {
  const auth = useAuthStore()
  auth.user = { role: { type: client ? 'client' : 'staff' }, is_superadmin: false } as User
  auth.permissions = { purchase_invoices: 2, 'purchase_invoices.create': 2, 'purchase_invoices.extract': 2 }
  useSupplierStore().setAvailable([{ id: 1 }, { id: 2 }] as SupplierBrief[], 1)
  return auth
}
async function mountPage() {
  const w = mount(AiImport, { global: { stubs: { RouterLink: true, ExtractionReviewModal: true } } })
  await flushPromises()
  return w
}
async function pickFiles(w: Awaited<ReturnType<typeof mountPage>>, names: string[]) {
  const input = w.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: names.map(name => new File(['synthetic'], name, { type: 'application/pdf' })), configurable: true })
  await input.trigger('change')
}

describe('klientský AI import přijatých faktur', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    m.status.mockReset().mockResolvedValue(status())
    m.extract.mockReset()
    m.batches.mockReset().mockResolvedValue([])
  })

  it.each([
    ['povolený', true, true, true],
    ['zakázaný', false, true, false],
    ['bez klíče', true, false, false],
  ])('%s import zobrazí odpovídající dostupnost bez odkazu do administrace', async (_label, enabled, configured, visible) => {
    setupRole()
    m.status.mockResolvedValue(status(enabled, configured))
    const w = await mountPage()
    expect(w.find('[data-test="ai-extraction-upload"]').exists()).toBe(visible)
    expect(w.find('[data-test="client-ai-unavailable"]').exists()).toBe(!visible)
    expect(w.find('[to="/admin/integrations?tab=ai"]').exists()).toBe(false)
    w.unmount()
  })

  it.each(['purchase_invoices', 'purchase_invoices.create', 'purchase_invoices.extract'] as const)('bez práva %s nenabídne upload', async permission => {
    setupRole().permissions[permission] = 1
    const w = await mountPage()
    expect(w.find('input[type="file"]').exists()).toBe(false)
    w.unmount()
  })

  it('účetní používá import i bez klientského přepínače', async () => {
    setupRole(false)
    m.status.mockResolvedValue(status(false))
    const w = await mountPage()
    expect(w.find('[data-test="ai-extraction-upload"]').exists()).toBe(true)
    w.unmount()
  })

  it('po vytěžení otevře kontrolu i u konceptu bez varování', async () => {
    setupRole()
    m.extract.mockResolvedValue({ ok: true, purchase_invoice_id: 17, source: 'ai', document_kind: 'invoice' })
    const w = await mountPage()
    await pickFiles(w, ['synthetic.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.run_extract'))!.trigger('click')
    await flushPromises()
    const review = w.getComponent({ name: 'ExtractionReviewModal' })
    expect(review.props('invoiceIds')).toEqual([17])
    expect(review.props('onlyFlagged')).toBe(false)
    w.unmount()
  })

  it('opožděná konfigurace původní firmy nezpřístupní upload v nové firmě', async () => {
    setupRole()
    let finish!: (value: AiExtractionStatus) => void
    m.status.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    m.status.mockResolvedValue(status(false))
    const w = await mountPage()
    useSupplierStore().setSupplier(2)
    await flushPromises()
    finish(status())
    await flushPromises()
    expect(w.find('[data-test="ai-extraction-upload"]').exists()).toBe(false)
    expect(w.find('[data-test="client-ai-unavailable"]').exists()).toBe(true)
    w.unmount()
  })

  it('při přepnutí firmy zahodí opožděný výsledek a nepokračuje v původní dávce', async () => {
    setupRole()
    let finish!: (value: unknown) => void
    m.extract.mockImplementation(() => new Promise(resolve => { finish = resolve }))
    const w = await mountPage()
    await pickFiles(w, ['synthetic-a.pdf', 'synthetic-b.pdf'])
    await w.findAll('button').find(b => b.text().includes('integrations.ai.batch_run'))!.trigger('click')
    expect(m.extract).toHaveBeenCalledTimes(1)
    useSupplierStore().setSupplier(2)
    await flushPromises()
    finish({ ok: true, purchase_invoice_id: 17, source: 'ai' })
    await flushPromises()
    expect(m.extract).toHaveBeenCalledTimes(1)
    expect(w.findComponent({ name: 'ExtractionReviewModal' }).exists()).toBe(false)
    expect(w.text()).not.toContain('synthetic-a.pdf')
    w.unmount()
  })
})
