import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { installFrameNavigation } from '../frameNavigation'

describe('navigace uvnitř pracovního okna', () => {
  it('na začátku a konci místní historie předá Zpět a Vpřed správci oken', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    await router.push('/projects/new')
    const traverse = vi.fn()
    installFrameNavigation(router, traverse, vi.fn())
    router.back()
    expect(traverse).toHaveBeenLastCalledWith(-1)
    await router.push('/projects/42')
    const back = new Promise<void>(resolve => { const off = router.afterEach(() => { off(); resolve() }) })
    router.back()
    await back
    expect(router.currentRoute.value.path).toBe('/projects/new')
    expect(traverse).toHaveBeenCalledTimes(1)
    const forward = new Promise<void>(resolve => { const off = router.afterEach(() => { off(); resolve() }) })
    router.forward()
    await forward
    expect(router.currentRoute.value.path).toBe('/projects/42')
    router.forward()
    expect(traverse).toHaveBeenLastCalledWith(1)
  })

  it('po úspěšné změně stránky resetuje scroll, změna filtru a zamítnutá navigace ho zachovají', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    await router.push('/invoices/new')
    const reset = vi.fn()
    installFrameNavigation(router, vi.fn(), reset)
    await router.push('/invoices/42')
    await nextTick()
    expect(reset).toHaveBeenCalledTimes(1)
    await router.replace('/invoices/42?tab=payments')
    router.beforeEach(to => to.path !== '/blocked')
    await router.push('/blocked')
    await nextTick()
    expect(reset).toHaveBeenCalledTimes(1)
  })
})
