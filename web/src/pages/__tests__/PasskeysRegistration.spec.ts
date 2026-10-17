import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  register: vi.fn(), load: vi.fn(), refresh: vi.fn(), securityRefresh: vi.fn(),
  advance: vi.fn(), setCsrf: vi.fn(),
}))
vi.mock('@/api/auth', () => ({ authApi: {
  passkeys: m.load,
  passkeyRegisterOptions: vi.fn().mockResolvedValue({ flow_token: 'synthetic-flow', public_key: {} }),
  passkeyRegisterVerify: m.register,
} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  allowedMfaMethods: ['passkey'], requireMfa: false,
  user: { totp_enabled: false }, refresh: m.refresh, setSessionCsrfToken: m.setCsrf,
}) }))
vi.mock('@/stores/sessionSecurity', () => ({ useSessionSecurityStore: () => ({ refresh: m.securityRefresh }) }))
vi.mock('@/security/domainLogin', () => ({
  canonicalDomainLoginSessionCsrf: () => 'owned', advanceCanonicalDomainLoginSession: m.advance,
}))
vi.mock('@/security/webauthn', () => ({
  isWebAuthnAvailable: () => true, createCredential: vi.fn().mockResolvedValue({ id: 'synthetic' }),
  webAuthnErrorKey: () => null,
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/security/RecoveryCodes.vue', () => ({ default: { template: '<div />' } }))
import Passkeys from '../Passkeys.vue'

beforeEach(() => {
  vi.clearAllMocks()
  m.load.mockReset().mockResolvedValue([])
  m.refresh.mockReset().mockResolvedValue(true)
  m.securityRefresh.mockReset().mockResolvedValue(true)
  m.register.mockReset().mockResolvedValue({ csrf_token: 'rotated' })
})

async function register() {
  const wrapper = mount(Passkeys)
  await flushPromises()
  await wrapper.get('input[type="text"]').setValue('Test key')
  await wrapper.get('input[type="password"]').setValue('Synthetic-password-42')
  await wrapper.get('button').trigger('click')
  await flushPromises()
  return wrapper
}

describe('registrace klíče před návratem do portálu', () => {
  it.each([{}, { csrf_token: 'rotated' }])('obnoví profil i zabezpečení nezávisle na rotaci CSRF: %j', async result => {
    m.register.mockResolvedValueOnce(result)
    const wrapper = await register()
    expect(m.refresh).toHaveBeenCalledOnce()
    expect(m.securityRefresh).toHaveBeenCalledExactlyOnceWith({ force: true })
    expect(m.refresh.mock.invocationCallOrder[0]).toBeLessThan(m.securityRefresh.mock.invocationCallOrder[0]!)
    expect(m.load).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('registered')).toHaveLength(1)
    if (!('csrf_token' in result)) {
      expect(m.advance).not.toHaveBeenCalled()
      expect(m.setCsrf).not.toHaveBeenCalled()
    }
    wrapper.unmount()
  })

  it('ohlásí dokončení až po aktualizaci vlastnictví rotované relace', async () => {
    m.refresh.mockImplementation(async () => {
      expect(m.advance).toHaveBeenCalledWith('owned', 'rotated')
      expect(m.setCsrf).toHaveBeenCalledWith('rotated')
    })
    const wrapper = await register()
    expect(m.register).toHaveBeenCalledWith('synthetic-flow', { id: 'synthetic' }, 'Test key', 'owned')
    expect(wrapper.emitted('registered')).toHaveLength(1)
    expect(m.securityRefresh).toHaveBeenCalledWith({ force: true })
    wrapper.unmount()
  })

  it('nevrací uživatele z nedokončené registrace', async () => {
    m.register.mockRejectedValueOnce(new Error('failed'))
    const wrapper = await register()
    expect(wrapper.emitted('registered')).toBeUndefined()
    expect(wrapper.text()).toContain('passkeys.operation_failed')
    wrapper.unmount()
  })

  it('dokončí návrat i při selhání načtení seznamu po úspěšné registraci', async () => {
    m.load.mockResolvedValueOnce([]).mockRejectedValueOnce(new Error('offline'))
    const wrapper = await register()
    expect(m.advance).toHaveBeenCalledWith('owned', 'rotated')
    expect(wrapper.emitted('registered')).toHaveLength(1)
    wrapper.unmount()
  })
})
