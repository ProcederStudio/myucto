import { getPaneRuntime } from '@/workspace/runtimeRegistry'
import { usePaneId } from '@/workspace/paneActivity'
import { inject } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'

export function usePaneDom() {
  const paneId = usePaneId()
  const window = inject(windowContextKey, null)

  function root(): ParentNode {
    return window?.root ?? (paneId.value ? (getPaneRuntime(paneId.value)?.root ?? document) : document)
  }

  function querySelector<T extends Element = Element>(selector: string): T | null {
    return root().querySelector<T>(selector)
  }

  function querySelectorAll<T extends Element = Element>(selector: string): NodeListOf<T> {
    return root().querySelectorAll<T>(selector)
  }

  return { root, querySelector, querySelectorAll }
}
