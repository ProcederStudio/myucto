import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  complete: vi.fn(),
  completePayroll: vi.fn(),
  completeTax: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    gatewayComplete: m.complete,
    gatewayCompletePayroll: m.completePayroll,
    gatewayCompleteTax: m.completeTax,
  },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) =>
      params?.value ? `${key}:${String(params.value)}` : key,
  }),
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_error: unknown, fallback: string) => fallback }))

import IsdsGatewayCallback from '../IsdsGatewayCallback.vue'

beforeEach(() => {
  vi.clearAllMocks()
  window.history.replaceState({}, '', '/isds-gateway/callback')
})

describe('ISDS gateway callback', () => {
  it('dokončí mzdovou relaci po zamítnutí obecného oprávnění a odstraní tokeny z URL', async () => {
    window.history.replaceState({}, '', '/isds-gateway/callback?appToken=app-1&sessionId=session-1')
    m.complete.mockRejectedValue({ isAxiosError: true, response: { status: 403 } })
    m.completePayroll.mockResolvedValue({
      state: 'approved',
      outbox_id: 7,
      redirect_url: null,
      external_message_id: 'DM-123',
      message: 'Odesláno.',
    })

    const wrapper = mount(IsdsGatewayCallback, {
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(m.complete).toHaveBeenCalledWith('app-1', 'session-1')
    expect(m.completePayroll).toHaveBeenCalledWith('app-1', 'session-1')
    expect(m.completeTax).not.toHaveBeenCalled()
    expect(window.location.search).toBe('')
    expect(wrapper.get('[data-test="isds-callback-result"]').text()).toContain('DM-123')
  })

  it('po zamítnutí obecné i mzdové cesty dokončí relaci daňového podání', async () => {
    window.history.replaceState({}, '', '/isds-gateway/callback?appToken=app-2&sessionId=session-2')
    m.complete.mockRejectedValue({ isAxiosError: true, response: { status: 403 } })
    m.completePayroll.mockRejectedValue({ isAxiosError: true, response: { status: 403 } })
    m.completeTax.mockResolvedValue({
      state: 'approved',
      outbox_id: 9,
      redirect_url: null,
      external_message_id: 'DM-456',
      message: 'Odesláno.',
    })

    const wrapper = mount(IsdsGatewayCallback, {
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(m.completeTax).toHaveBeenCalledWith('app-2', 'session-2')
    expect(wrapper.get('[data-test="isds-callback-result"]').text()).toContain('DM-456')
    expect(wrapper.text()).toContain('databox.gateway.callback.tax')
  })

  it('jiná chyba než 403 nepropadne na další cestu', async () => {
    window.history.replaceState({}, '', '/isds-gateway/callback?appToken=app-3&sessionId=session-3')
    m.complete.mockRejectedValue({ isAxiosError: true, response: { status: 403 } })
    m.completePayroll.mockRejectedValue({ isAxiosError: true, response: { status: 422 } })

    const wrapper = mount(IsdsGatewayCallback, {
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(m.completeTax).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="isds-callback-error"]').text())
      .toBe('databox.gateway.callback.failed')
  })

  it('bez identifikátorů relace nevolá API', async () => {
    const wrapper = mount(IsdsGatewayCallback, {
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(m.complete).not.toHaveBeenCalled()
    expect(m.completePayroll).not.toHaveBeenCalled()
    expect(m.completeTax).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="isds-callback-error"]').text())
      .toBe('databox.gateway.callback.invalid')
  })
})
