import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { clearPortalLoggedOut, isPortalLoggedOut } from '@/security/portalLogout'
import { readTargetDomainLogin } from '@/security/domainLogin'
const m = vi.hoisted(() => ({ logout: vi.fn(), broadcast: vi.fn() }))
vi.mock('@/api/auth', () => ({ authApi: { logout: m.logout } }))
vi.mock('@/security/sessionChannel', () => ({ broadcastSessionEvent: m.broadcast }))
beforeEach(() => {
  setActivePinia(createPinia()); localStorage.clear(); sessionStorage.clear(); vi.clearAllMocks()
  m.logout.mockResolvedValue(undefined)
})
function pendingCallback() {
  const token = 'a'.repeat(43)
  sessionStorage.setItem('myinvoice.domain_login.target', JSON.stringify({
    requestToken: token, state: token, verifier: token, expiresAt: Date.now() + 60000,
  }))
  return () => readTargetDomainLogin(token, 'b'.repeat(43), token)
}
function storeFor(locked: boolean) {
  const store = useAuthStore()
  store.domainContext = {
    mode: locked ? 'custom' : 'canonical', locked, feature_enabled: true,
    hostname: 'portal.example.test', origin: 'https://portal.example.test',
    supplier_id: locked ? 1 : null, purpose: null,
  }
  store.setSessionCsrfToken('csrf-token')
  return store
}
describe('odhlášení vlastní domény', () => {
  it('úspěch uloží marker a zruší dosavadní PKCE callback', async () => {
    const store = storeFor(true); const callback = pendingCallback()
    expect(callback()).not.toBeNull(); await store.logout()
    expect(isPortalLoggedOut()).toBe(true); expect(callback()).toBeNull()
    expect(m.broadcast).toHaveBeenCalledWith('logout'); expect(store.csrfToken).toBe('')
    clearPortalLoggedOut(); expect(isPortalLoggedOut()).toBe(false)
  })
  it('neúspěch netvrdí odhlášení a opakování může uspět', async () => {
    const store = storeFor(true); const callback = pendingCallback()
    m.logout.mockRejectedValueOnce(new Error('offline'))
    await expect(store.logout()).rejects.toThrow('offline')
    expect(isPortalLoggedOut()).toBe(false); expect(callback()).not.toBeNull()
    expect(m.broadcast).not.toHaveBeenCalled(); await store.logout()
    expect(isPortalLoggedOut()).toBe(true); expect(callback()).toBeNull()
  })
  it('kanonické odhlášení nemění marker ani cílový PKCE stav', async () => {
    const store = storeFor(false); const callback = pendingCallback(); await store.logout()
    expect(isPortalLoggedOut()).toBe(false); expect(callback()).not.toBeNull()
  })
})
