import type { PaneId, WorkspacePaneState } from '@/stores/workspace'

export function paneForBeside(
  currentPaneId: PaneId,
  panes: Pick<WorkspacePaneState, 'id' | 'fullPath'>[],
): PaneId {
  const index = panes.findIndex(pane => pane.id === currentPaneId)
  return panes.find(pane => pane.id !== currentPaneId && !pane.fullPath)?.id
    ?? panes[(index + 1) % panes.length]?.id ?? currentPaneId
}

export function paneForCtrlClick(
  currentPaneId: PaneId,
  panes: Pick<WorkspacePaneState, 'id' | 'fullPath'>[],
): PaneId | null {
  const currentIndex = panes.findIndex(pane => pane.id === currentPaneId)
  if (currentIndex < 0) return null
  const panesToRight = panes.slice(currentIndex + 1)
  return panesToRight.find(pane => !pane.fullPath)?.id ?? panesToRight[0]?.id ?? null
}

export function internalRouteFromAnchor(anchor: HTMLAnchorElement, origin = window.location.origin): string | null {
  if (anchor.hasAttribute('download')) return null
  if (anchor.target && anchor.target !== '_self') return null

  const url = new URL(anchor.href, origin)
  if (url.origin !== origin || !['http:', 'https:'].includes(url.protocol)) return null
  return `${url.pathname}${url.search}${url.hash}`
}

export function internalRouteFromElement(element: Element): string | null {
  const anchor = element.closest<HTMLAnchorElement>('a[href]')
  if (anchor) return internalRouteFromAnchor(anchor)
  if (element.closest('button, input, select, textarea, [contenteditable="true"]')) return null
  return element.closest<HTMLElement>('[data-workspace-route]')?.dataset.workspaceRoute ?? null
}
