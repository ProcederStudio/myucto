import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h } from 'vue'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteLocationRaw } from 'vue-router'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useWorkspaceStore, type PaneCount, type PaneId } from '@/stores/workspace'
import { destroyPaneRuntimes, registerPaneRuntime, type PaneRuntime } from '@/workspace/runtimeRegistry'
import { setWindowManager } from '@/workspace/windowRegistry'
import type { WorkspaceWindowManager } from '@/workspace/windowManager'
import { windowContextKey } from '@/workspace/windowContext'

let wrapper: VueWrapper | undefined

beforeEach(() => {
  setActivePinia(createPinia())
  setWindowManager(null)
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  destroyPaneRuntimes()
  setWindowManager(null)
})

async function setup(count: PaneCount, source: PaneId | null = null) {
  const store = useWorkspaceStore()
  store.resetLayout(count, '/source')
  for (const pane of store.panes) store.updatePane(pane.id, { fullPath: `/${pane.id}` })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }],
  })
  await router.push('/source')
  let navigation!: ReturnType<typeof useWorkspaceNavigation>
  wrapper = mount(defineComponent({
    setup() {
      navigation = useWorkspaceNavigation()
      return () => h('div')
    },
  }), {
    global: {
      plugins: [router],
      provide: source ? {
        [windowContextKey as symbol]: {
          paneId: computed(() => source),
          active: computed(() => true),
          root: document.createElement('div'),
          portal: document.createElement('div'),
        },
      } : {},
    },
  })
  return { store, router, navigation }
}

function runtime(id: PaneId): PaneRuntime {
  const router = createRouter({ history: createMemoryHistory(), routes: [] })
  const registered: PaneRuntime = {
    id, router, root: document.createElement('div'),
    navigate: vi.fn(async to => { useWorkspaceStore().updatePane(id, { fullPath: String(to) }) }),
    back: vi.fn(), forward: vi.fn(), clear: vi.fn(), destroy: vi.fn(),
  }
  registerPaneRuntime(registered)
  return registered
}

describe('openBeside', () => {
  it.each([
    [1, 'primary', 'primary'],
    [2, 'primary', 'secondary-2'],
    [3, 'secondary-2', 'secondary-3'],
    [3, 'secondary-3', 'primary'],
  ] as const)('navigates with %i panels from %s to %s', async (count, source, target) => {
    const { store, navigation } = await setup(count, source)
    const originalIds = store.panes.map(pane => pane.id)
    const runtimes = store.panes.map(pane => runtime(pane.id))
    await navigation.openBeside('/detail/42')
    expect(store.panes.map(pane => pane.id)).toEqual(originalIds)
    expect(store.paneCount).toBe(count)
    expect(store.panes.find(pane => pane.id === target)?.fullPath).toBe('/detail/42')
    for (const current of runtimes) {
      expect(current.navigate).toHaveBeenCalledTimes(current.id === target ? 1 : 0)
      expect(current.destroy).not.toHaveBeenCalled()
    }
  })

  it('uses the injected source even when another panel is active, and prefers an empty panel', async () => {
    const { store, navigation } = await setup(3, 'primary')
    store.activatePane('secondary-2')
    store.updatePane('secondary-3', { fullPath: null })
    const source = runtime('primary')
    const active = runtime('secondary-2')
    const empty = runtime('secondary-3')
    await navigation.openBeside('/detail/42')
    expect(empty.navigate).toHaveBeenCalledWith('/detail/42')
    expect(source.navigate).not.toHaveBeenCalled()
    expect(active.navigate).not.toHaveBeenCalled()
  })

  it('falls back to the active panel as source outside a window context', async () => {
    const { store, navigation } = await setup(2)
    store.activatePane('secondary-2')
    const target = runtime('primary')
    await navigation.openBeside('/detail/42')
    expect(target.navigate).toHaveBeenCalledWith('/detail/42')
  })

  it('opens through the window manager without replacing source runtime content', async () => {
    const { store, navigation } = await setup(2, 'primary')
    const source = runtime('primary')
    const target = runtime('secondary-2')
    const open = vi.fn(async (to: RouteLocationRaw, id: PaneId) => { store.updatePane(id, { fullPath: String(to) }) })
    setWindowManager({ open } as unknown as WorkspaceWindowManager)
    await navigation.openBeside('/detail/42')
    expect(open).toHaveBeenCalledWith('/detail/42', 'secondary-2')
    expect(store.panes[0]?.fullPath).toBe('/primary')
    expect(store.panes[1]?.fullPath).toBe('/detail/42')
    expect(source.navigate).not.toHaveBeenCalled()
    expect(target.navigate).not.toHaveBeenCalled()
    expect(source.destroy).not.toHaveBeenCalled()
  })

  it('uses the source runtime when the target runtime is unavailable', async () => {
    const { navigation, router } = await setup(2, 'primary')
    const source = runtime('primary')
    await navigation.openBeside('/detail/42')
    expect(source.navigate).toHaveBeenCalledWith('/detail/42')
    expect(router.currentRoute.value.fullPath).toBe('/source')
  })

  it('uses the router when no manager or runtime is registered', async () => {
    const { navigation, router } = await setup(1)
    await navigation.openBeside('/detail/42')
    expect(router.currentRoute.value.fullPath).toBe('/detail/42')
  })
})
