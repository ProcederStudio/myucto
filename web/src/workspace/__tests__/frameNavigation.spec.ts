import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { installFrameNavigation, internalAnchorNavigation } from '../frameNavigation'

describe('navigace uvnitř pracovního okna', () => {
  it('označený odkaz otevře vedle i z posledního panelu; Ctrl klik ponechá původní chování', () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
    const anchor = document.createElement('a')
    anchor.href = '/bank/7?tx=42&detail=1'
    anchor.dataset.workspaceOpen = 'beside'
    function click(ctrlKey = false) {
      const event = new MouseEvent('click', { button: 0, ctrlKey })
      Object.defineProperty(event, 'target', { value: anchor })
      return event
    }
    expect(internalAnchorNavigation(router, click(), false)).toEqual({ to: '/bank/7?tx=42&detail=1', placement: 'beside' })
    expect(internalAnchorNavigation(router, click(true), true)).toEqual({ to: '/bank/7?tx=42&detail=1', newPane: true })
    expect(internalAnchorNavigation(router, click(true), false)).toBeNull()
    delete anchor.dataset.workspaceOpen
    expect(internalAnchorNavigation(router, click(), true)).toEqual({ to: '/bank/7?tx=42&detail=1' })
  })
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
