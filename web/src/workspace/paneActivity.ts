import { computed, inject, type ComputedRef, type InjectionKey } from 'vue'
import { useWorkspaceStore, type PaneId } from '@/stores/workspace'
import { windowContextKey } from './windowContext'

export const paneIdKey: InjectionKey<PaneId> = Symbol('workspace-pane-id')

export function usePaneId(): ComputedRef<PaneId | null> {
  const window = inject(windowContextKey, null)
  const paneId = inject(paneIdKey, null)
  return window?.paneId ?? computed(() => paneId)
}

export function usePaneActivity(): ComputedRef<boolean> {
  const window = inject(windowContextKey, null)
  if (window) return window.active
  const paneId = usePaneId()
  if (paneId.value === null) return computed(() => true)
  const workspace = useWorkspaceStore()
  return computed(() => paneId.value === null || workspace.activePaneId === paneId.value)
}
