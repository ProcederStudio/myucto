import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { mirrorWindowLocation } from '../windowHistory'

describe('retained window browser history', () => {
  it.each([
    ['/activation/purchase#purchase=test&state=secret', '/activation/purchase'],
    ['/admin/databox?tab=outbox&appToken=secret&sessionId=test#details', '/admin/databox?tab=outbox#details'],
    ['/isds-gateway/callback?appToken=secret&sessionId=test', '/isds-gateway/callback'],
  ])('odstraní návratové tokeny z aktuální položky historie: %s', async (from, to) => {
    const history = createMemoryHistory()
    const router = createRouter({ history, routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    await router.push(from)
    await mirrorWindowLocation(router, from, 'window-1', 1)
    const push = vi.spyOn(history, 'push')
    await mirrorWindowLocation(router, to, 'window-1', 1)
    expect(router.currentRoute.value.fullPath).toBe(to)
    expect(push).not.toHaveBeenCalled()
  })

  it('keeps tabs, filters and anchors and adopts the initial entry without adding a duplicate', async () => {
    const history = createMemoryHistory()
    const router = createRouter({ history, routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    await router.push('/profile/password?tab=shortcuts#bindings')
    const push = vi.spyOn(history, 'push')
    await mirrorWindowLocation(router, router.currentRoute.value.fullPath, 'window-1', 11)
    expect(push).not.toHaveBeenCalled()
    expect(history.state.workspaceWindowId).toBe('window-1')
    expect(router.currentRoute.value.query.tab).toBe('shortcuts')
    expect(router.currentRoute.value.hash).toBe('#bindings')

    await mirrorWindowLocation(router, '/invoices?status=unpaid&search=A%26B#totals', 'window-2', 11)
    expect(push).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.query).toEqual({ status: 'unpaid', search: 'A&B' })
    expect(router.currentRoute.value.hash).toBe('#totals')
    await mirrorWindowLocation(router, router.currentRoute.value.fullPath, 'window-2', 11)
    expect(push).toHaveBeenCalledTimes(1)
    const backed = new Promise<void>(resolve => { const off = router.afterEach(() => { off(); resolve() }) })
    router.back()
    await backed
    expect(router.currentRoute.value.fullPath).toBe('/profile/password?tab=shortcuts#bindings')
    expect(history.state.workspaceWindowId).toBe('window-1')
  })
})
