import { getWindowSupplierScope } from '@/workspace/supplierContext'

export type FullPageNavigationMode = 'assign' | 'replace'

export function navigateFullPage(target: string | URL, mode: FullPageNavigationMode = 'assign'): void {
  const to = target instanceof URL ? target.toString() : target
  const frame = getWindowSupplierScope()
  if (frame) {
    window.parent.postMessage({
      type: 'myucto-window-global',
      id: frame.windowId,
      to,
      mode,
    }, window.location.origin)
    return
  }

  window.location[mode](to)
}
