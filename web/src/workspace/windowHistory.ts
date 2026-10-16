import type { Router } from 'vue-router'
import { withoutCallbackTokens } from './pageLocation'

export async function mirrorWindowLocation(router: Router, fullPath: string, id: string | undefined, supplierId: number | undefined): Promise<void> {
  const current = router.currentRoute.value.fullPath
  if (current === fullPath && router.options.history.state.workspaceWindowId === id) return
  const resolved = router.resolve(fullPath)
  const location = { path: resolved.path, query: resolved.query, hash: resolved.hash, force: true,
    state: { workspaceWindowId: id ?? null, workspaceSupplierId: supplierId ?? null } }
  if (current === fullPath || withoutCallbackTokens(current) === fullPath) await router.replace(location)
  else await router.push(location)
}
