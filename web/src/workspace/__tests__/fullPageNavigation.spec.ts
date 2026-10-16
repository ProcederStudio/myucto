import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({
  scope: null as { windowId: string; supplierId: number } | null,
}))

vi.mock('@/workspace/supplierContext', () => ({
  getWindowSupplierScope: () => state.scope,
}))

import { navigateFullPage } from '@/workspace/fullPageNavigation'

describe('full page navigation', () => {
  const originalParent = window.parent

  beforeEach(() => {
    state.scope = null
  })

  afterEach(() => {
    Object.defineProperty(window, 'parent', { configurable: true, value: originalParent })
    vi.restoreAllMocks()
  })

  it('zachová assign a replace mimo workspace frame', () => {
    const assign = vi.spyOn(window.location, 'assign').mockImplementation(() => undefined)
    const replace = vi.spyOn(window.location, 'replace').mockImplementation(() => undefined)

    navigateFullPage('https://bank.example/authorize')
    navigateFullPage('/login', 'replace')

    expect(assign).toHaveBeenCalledWith('https://bank.example/authorize')
    expect(replace).toHaveBeenCalledWith('/login')
  })

  it('pošle hard navigation parentu a nenaviguje iframe', () => {
    state.scope = { windowId: 'window-a', supplierId: 11 }
    const postMessage = vi.fn()
    Object.defineProperty(window, 'parent', { configurable: true, value: { postMessage } })
    const assign = vi.spyOn(window.location, 'assign').mockImplementation(() => undefined)
    const replace = vi.spyOn(window.location, 'replace').mockImplementation(() => undefined)

    navigateFullPage(new URL('https://bank.example/authorize'))
    navigateFullPage('/domain/callback', 'replace')

    expect(postMessage).toHaveBeenNthCalledWith(1, {
      type: 'myucto-window-global',
      id: 'window-a',
      to: 'https://bank.example/authorize',
      mode: 'assign',
    }, window.location.origin)
    expect(postMessage).toHaveBeenNthCalledWith(2, {
      type: 'myucto-window-global',
      id: 'window-a',
      to: '/domain/callback',
      mode: 'replace',
    }, window.location.origin)
    expect(assign).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()
  })
})
