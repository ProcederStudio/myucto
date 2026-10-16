import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import SessionLockOverlay from '@/components/SessionLockOverlay.vue'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useRoute } from 'vue-router'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', async () => {
  const { reactive } = await import('vue')
  const route = reactive({ name: 'invoices', fullPath: '/invoices', matched: [{ meta: { requiresAuth: true } }] })
  return { useRoute: () => route }
})
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isAuthenticated: true, domainContext: null }) }))
vi.mock('@/security/webauthn', () => ({ isWebAuthnAvailable: () => false }))
vi.mock('@/security/domainLogin', () => ({ beginDomainLogin: vi.fn() }))
vi.mock('@/stores/sessionSecurity', async () => {
  const { reactive } = await import('vue')
  const security = reactive({
    state: { lock_after_minutes: 1, unlock_methods: [] },
    privacyCurtain: false,
    error: '',
    busy: false,
    recordActivity: vi.fn().mockResolvedValue(undefined),
    refresh: vi.fn().mockResolvedValue(undefined),
  })
  return { useSessionSecurityStore: () => security }
})

let wrapper: VueWrapper | undefined
const security = useSessionSecurityStore()
const route = useRoute()

function activity() {
  window.dispatchEvent(new CustomEvent('myucto:window-activity'))
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-10-15T12:00:00Z'))
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
  security.privacyCurtain = false
  security.state!.lock_after_minutes = 1
  route.matched[0]!.meta.requiresAuth = true
  vi.clearAllMocks()
  wrapper = mount(SessionLockOverlay)
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('iframe session activity bridge', () => {
  it('records validated iframe editing activity and coalesces continuous input into trailing activity', async () => {
    activity()
    expect(security.recordActivity).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(10_000)
    activity()
    activity()
    expect(security.recordActivity).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(20_000)
    expect(security.recordActivity).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(30_000)
    expect(security.recordActivity).toHaveBeenCalledTimes(2)
  })

  it('still rejects synthetic browser input outside the validated activity bridge', () => {
    window.dispatchEvent(new Event('input', { bubbles: true }))
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }))
    expect(security.recordActivity).not.toHaveBeenCalled()
  })

  it.each(['hidden', 'locked', 'public', 'disabled'] as const)('ignores activity while %s', async condition => {
    if (condition === 'hidden') vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    if (condition === 'locked') security.privacyCurtain = true
    if (condition === 'public') route.matched[0]!.meta.requiresAuth = false
    if (condition === 'disabled') security.state!.lock_after_minutes = 0
    await nextTick()
    activity()
    await vi.advanceTimersByTimeAsync(30_000)
    expect(security.recordActivity).not.toHaveBeenCalled()
  })

  it.each(['hidden', 'locked', 'public', 'disabled'] as const)('rechecks %s before sending pending activity', async condition => {
    activity()
    await vi.advanceTimersByTimeAsync(1_000)
    activity()
    if (condition === 'hidden') vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    if (condition === 'locked') security.privacyCurtain = true
    if (condition === 'public') route.matched[0]!.meta.requiresAuth = false
    if (condition === 'disabled') security.state!.lock_after_minutes = 0
    await nextTick()
    await vi.advanceTimersByTimeAsync(29_000)
    expect(security.recordActivity).toHaveBeenCalledTimes(1)
  })

  it('removes the bridge listener and trailing activity on unmount', async () => {
    activity()
    await vi.advanceTimersByTimeAsync(1_000)
    activity()
    wrapper!.unmount()
    wrapper = undefined
    activity()
    await vi.advanceTimersByTimeAsync(30_000)
    expect(security.recordActivity).toHaveBeenCalledTimes(1)
  })
})