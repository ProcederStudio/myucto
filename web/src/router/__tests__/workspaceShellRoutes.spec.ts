import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { workspaceShellRoutes } from '@/router/workspaceShellRoutes'

describe('workspace shell routes', () => {
  it('zrcadlí detail faktury bez načítání jeho komponenty do hlavní aplikace', async () => {
    const load = vi.fn(async () => ({ render: () => null }))
    const records: RouteRecordRaw[] = [{
      path: '/invoices/:id', name: 'invoice-detail', component: load,
      meta: { requiresAuth: true, permission: 'invoices' }, props: true,
    }]
    const shell = createRouter({ history: createMemoryHistory(), routes: workspaceShellRoutes(records) })
    await shell.push('/invoices/42?tab=items#payments')
    expect(load).not.toHaveBeenCalled()
    expect(shell.currentRoute.value).toMatchObject({
      name: 'invoice-detail', params: { id: '42' }, query: { tab: 'items' }, hash: '#payments',
      meta: { requiresAuth: true, permission: 'invoices' },
    })
    expect(records[0]!.component).toBe(load)

    const window = createRouter({ history: createMemoryHistory(), routes: records })
    await window.push('/invoices/42')
    expect(load).toHaveBeenCalledTimes(1)
  })

  it('zachová přesměrování do profilu včetně query', async () => {
    const load = vi.fn(async () => ({ render: () => null }))
    const routes: RouteRecordRaw[] = [
      { path: '/profile/shortcuts', redirect: { path: '/profile/password', query: { tab: 'shortcuts' } } },
      { path: '/profile/password', name: 'profile-password', component: load },
    ]
    const router = createRouter({ history: createMemoryHistory(), routes: workspaceShellRoutes(routes) })
    await router.push('/profile/shortcuts')
    expect(router.currentRoute.value.fullPath).toBe('/profile/password?tab=shortcuts')
    expect(load).not.toHaveBeenCalled()
  })
})
