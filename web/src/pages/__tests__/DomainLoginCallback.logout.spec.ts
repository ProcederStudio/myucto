import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { isPortalLoggedOut, markPortalLoggedOut } from '@/security/portalLogout'

const m = vi.hoisted(() => ({ exchange: vi.fn(), read: vi.fn(), clear: vi.fn(), csrf: vi.fn() }))
vi.mock('@/api/auth', () => ({ authApi: { domainLoginExchange: m.exchange } }))
vi.mock('@/api/client', () => ({ setCsrfToken: m.csrf }))
vi.mock('@/security/domainLogin', () => ({ readTargetDomainLogin: m.read, clearTargetDomainLogin: m.clear }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: { request: 'request', code: 'code', state: 'state' } }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))
import DomainLoginCallback from '../DomainLoginCallback.vue'

beforeEach(() => {
  vi.restoreAllMocks()
  vi.clearAllMocks()
  localStorage.clear()
  markPortalLoggedOut()
  m.read.mockReturnValue({ requestToken: 'request', code: 'code', state: 'state', verifier: 'verifier' })
  m.exchange.mockResolvedValue({ csrf_token: 'csrf', return_path: '/portal' })
})

it('zruší stav odhlášení až po úspěšné výměně kódu a bezpečném návratu', async () => {
  const replace = vi.spyOn(window.location, 'replace').mockImplementation(() => {})
  const wrapper = mount(DomainLoginCallback)
  await flushPromises()
  expect(isPortalLoggedOut()).toBe(false)
  expect(m.exchange).toHaveBeenCalledWith('request', 'code', 'state', 'verifier')
  expect(replace).toHaveBeenCalledWith('/portal')
  wrapper.unmount()
})

it.each(['exchange', 'return-path', 'stale-callback'])('při chybě %s ponechá portál odhlášený', async (failure) => {
  const replace = vi.spyOn(window.location, 'replace').mockImplementation(() => {})
  if (failure === 'exchange') m.exchange.mockRejectedValueOnce(new Error('offline'))
  if (failure === 'return-path') m.exchange.mockResolvedValueOnce({ csrf_token: 'csrf', return_path: '//example.invalid' })
  if (failure === 'stale-callback') m.read.mockReturnValueOnce(null)
  const wrapper = mount(DomainLoginCallback)
  await flushPromises()
  expect(isPortalLoggedOut()).toBe(true)
  expect(replace).not.toHaveBeenCalled()
  if (failure === 'stale-callback') expect(m.exchange).not.toHaveBeenCalled()
  wrapper.unmount()
})
