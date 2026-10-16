import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { InternalAxiosRequestConfig } from 'axios'
import { api, setCsrfToken } from '../client'
import { authApi } from '../auth'
const originalAdapter = api.defaults.adapter
const sent: InternalAxiosRequestConfig[] = []
beforeEach(() => {
  sent.length = 0
  localStorage.clear()
  setCsrfToken('different-tab-or-refreshed-csrf')
  api.defaults.adapter = async config => {
    sent.push(config)
    return { config, data: {}, status: 200, statusText: 'OK', headers: {} }
  }
})
afterEach(() => {
  api.defaults.adapter = originalAdapter
  setCsrfToken(null)
})
describe('canonical session CSRF binding', () => {
  it('preserves explicit ownership through the real axios interceptor', async () => {
    await authApi.domainLoginAuthorize('request', 'state', 'owned')
    await authApi.logout('owned')
    expect(sent.map(config => config.headers.get('X-CSRF-Token'))).toEqual(['owned', 'owned'])
  })
  it('binds every supported rotation to the prior owned session', async () => {
    await authApi.passkeyRegisterVerify('flow', {}, 'synthetic passkey', 'owned')
    await authApi.totpEnable('123456', 'owned')
    await authApi.sessionUnlockVerify('flow', {}, 'owned')
    expect(sent.map(config => config.headers.get('X-CSRF-Token'))).toEqual(['owned', 'owned', 'owned'])
  })
  it('uses ordinary current-session default without an explicit binding', async () => {
    await authApi.logout()
    expect(sent[0]?.headers.get('X-CSRF-Token')).toBe('different-tab-or-refreshed-csrf')
  })
})
