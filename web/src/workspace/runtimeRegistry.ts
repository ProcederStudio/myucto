import type { RouteLocationRaw, Router } from 'vue-router'
import type { PaneId } from '@/stores/workspace'

export interface PaneRuntime {
  id: PaneId
  root: HTMLElement
  router: Router
  navigate: (to: RouteLocationRaw) => Promise<void>
  back: () => void
  forward: () => void
  clear: () => void
  destroy: () => void
  focus?: () => void
}

const runtimes = new Map<PaneId, PaneRuntime>()

export function registerPaneRuntime(runtime: PaneRuntime): () => void {
  runtimes.get(runtime.id)?.destroy()
  runtimes.set(runtime.id, runtime)
  return () => {
    if (runtimes.get(runtime.id) === runtime) runtimes.delete(runtime.id)
  }
}

export function getPaneRuntime(id: PaneId): PaneRuntime | undefined {
  return runtimes.get(id)
}

export function listPaneRuntimes(): PaneRuntime[] {
  return [...runtimes.values()]
}

export function destroyPaneRuntimes(): void {
  const current = [...runtimes.values()]
  runtimes.clear()
  for (const runtime of current) runtime.destroy()
}

export function runtimeCount(): number {
  return runtimes.size
}
