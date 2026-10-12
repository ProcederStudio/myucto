import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  enqueueIsds: vi.fn(),
  gatewayStartTax: vi.fn(),
  passwordSend: vi.fn(),
  certificateSend: vi.fn(),
  cancelIsds: vi.fn(),
}))

vi.mock('@/api/epoSubmissions', () => ({
  epoSubmissionsApi: { enqueueIsds: m.enqueueIsds, cancelIsds: m.cancelIsds },
}))
vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    gatewayStartTax: m.gatewayStartTax,
    passwordSend: m.passwordSend,
    certificateSend: m.certificateSend,
  },
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}|${JSON.stringify(params)}` : key,
  }),
}))

import TaxIsdsSendDialog from '../TaxIsdsSendDialog.vue'

function queued(overrides: Record<string, unknown> = {}) {
  return {
    outbox_id: 77,
    created: true,
    row: { id: 77, dispatch_state: 'ready' },
    recipient: { id: 5, box_id: 'abc1234', name: 'Finanční úřad pro Testovací kraj', code: 'fu_451', source: 'office_code' },
    xml_office_code: '451',
    office_mismatch: false,
    subject: 'DPH 2026-09',
    attachment: { filename: 'dphdp3.xml', mime: 'application/xml', sha256: '0123456789abcdef0123', bytes: 2048 },
    transport: { automatic: false, channel: 'mobile_key', reason: null },
    ...overrides,
  }
}

function mountDialog() {
  return mount(TaxIsdsSendDialog, {
    props: { submissionId: 12, title: 'DPH · 2026-09' },
    global: { stubs: { MobileKeySendButton: { template: '<div data-test="mobile-key-stub" />' } } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('TaxIsdsSendDialog', () => {
  it('zařadí podání do fronty a nabídne všechny čtyři cesty odeslání', async () => {
    m.enqueueIsds.mockResolvedValue(queued())
    const wrapper = mountDialog()
    await flushPromises()

    expect(m.enqueueIsds).toHaveBeenCalledWith(12)
    expect(wrapper.get('[data-test="tax-isds-recipient"]').text()).toContain('abc1234')
    expect(wrapper.get('[data-test="tax-isds-attachment"]').text()).toContain('dphdp3.xml')
    expect(wrapper.get('[data-test="tax-isds-attachment"]').text()).toContain('0123456789ab')
    for (const method of ['gateway', 'mobile_key', 'password', 'certificate']) {
      expect(wrapper.find(`[data-test="tax-isds-method-${method}"]`).exists()).toBe(true)
    }
    // Bez automatické brány se doporučí Mobilní klíč.
    expect(wrapper.get('[data-test="tax-isds-method-mobile_key"]').attributes('aria-checked')).toBe('true')
    expect(wrapper.find('[data-test="mobile-key-stub"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="tax-isds-mismatch"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="tax-isds-open-outbox"]').attributes('href'))
      .toBe('/admin/databox?tab=outbox&outbox=77')
  })

  it('varuje, když XML míří na jiný finanční úřad než vybraná schránka', async () => {
    m.enqueueIsds.mockResolvedValue(queued({ office_mismatch: true, xml_office_code: '461' }))
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.get('[data-test="tax-isds-mismatch"]').text())
      .toContain('reports.submissions.isds.office_mismatch')
    expect(wrapper.get('[data-test="tax-isds-mismatch"]').text()).toContain('461')
  })

  it('automatickou bránu doporučí a spustí přes daňový endpoint', async () => {
    m.enqueueIsds.mockResolvedValue(queued({ transport: { automatic: true, channel: 'gateway', reason: null } }))
    m.gatewayStartTax.mockResolvedValue({
      session_id: 1,
      app_token: 'x',
      redirect_url: 'https://example.invalid/isds',
      login_guidance: 'Přihlaste se.',
      login_policy_documented: true,
      expires_at: '2026-10-12T10:00:00Z',
      resumed: false,
    })
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.get('[data-test="tax-isds-method-gateway"]').attributes('aria-checked')).toBe('true')
    await wrapper.get('[data-test="tax-isds-gateway-start"]').trigger('click')
    await flushPromises()
    expect(m.gatewayStartTax).toHaveBeenCalledWith(77)
    expect(wrapper.text()).toContain('Přihlaste se.')
  })

  it('certifikátem odešle až po potvrzení a ohlásí odeslání', async () => {
    m.enqueueIsds.mockResolvedValue(queued())
    m.certificateSend.mockResolvedValue({ row: { id: 77, dispatch_state: 'sent' }, dispatched: true })
    const wrapper = mountDialog()
    await flushPromises()

    await wrapper.get('[data-test="tax-isds-method-certificate"]').trigger('click')
    const send = wrapper.get('[data-test="tax-isds-certificate-send"]')
    expect(send.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="tax-isds-certificate-confirm"]').setValue(true)
    await wrapper.get('[data-test="tax-isds-certificate-send"]').trigger('click')
    await flushPromises()

    expect(m.certificateSend).toHaveBeenCalledWith(77, 'production')
    expect(wrapper.emitted('sent')).toHaveLength(1)
    expect(wrapper.find('[data-test="tax-isds-sent"]').exists()).toBe(true)
  })

  it('jménem a heslem: 403 vysvětlí chybějící oprávnění a heslo zahodí', async () => {
    m.enqueueIsds.mockResolvedValue(queued())
    m.passwordSend.mockRejectedValue({ response: { status: 403 } })
    const wrapper = mountDialog()
    await flushPromises()

    await wrapper.get('[data-test="tax-isds-method-password"]').trigger('click')
    await wrapper.get('[data-test="tax-isds-username"]').setValue('user1')
    await wrapper.get('[data-test="tax-isds-password"]').setValue('secret')
    await wrapper.get('[data-test="tax-isds-password-send"]').trigger('click')
    await flushPromises()

    expect(m.passwordSend).toHaveBeenCalledWith(77, 'production', 'user1', 'secret')
    expect(wrapper.get('[data-test="tax-isds-send-error"]').text())
      .toContain('reports.submissions.isds.signing_forbidden')
    expect((wrapper.get('[data-test="tax-isds-password"]').element as HTMLInputElement).value).toBe('')
    expect(wrapper.emitted('sent')).toBeUndefined()
  })

  it('zprávu ve frontě zruší přes daňový endpoint podle ID podání', async () => {
    m.enqueueIsds.mockResolvedValue(queued())
    m.cancelIsds.mockResolvedValue({ id: 12 })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.get('[data-test="tax-isds-open-access"]').attributes('href'))
      .toBe('/admin/databox?tab=access')
    await wrapper.get('[data-test="tax-isds-cancel"]').trigger('click')
    await flushPromises()

    expect(m.cancelIsds).toHaveBeenCalledWith(12)
    expect(wrapper.emitted('close')).toHaveLength(1)
    confirm.mockRestore()
  })

  it('chybu zařazení ukáže a cesty odeslání nenabídne', async () => {
    m.enqueueIsds.mockRejectedValue({ response: { status: 422 } })
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.get('[data-test="tax-isds-enqueue-error"]').text())
      .toBe('reports.submissions.isds.enqueue_failed')
    expect(wrapper.find('[data-test="tax-isds-methods"]').exists()).toBe(false)
  })
})
