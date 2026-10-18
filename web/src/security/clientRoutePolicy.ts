import routePolicy from '@shared/client-route-policy.json'
import type { DomainContext } from '@/api/auth'
import type { AccessLevel, PermissionKey } from '@/security/permissions'

export type ClientDomainRouteKind = 'permission' | 'self_service' | 'client_redirect' | 'router_redirect'

export interface ClientDomainRouteDefinition {
  name: string
  path_pattern: string
  kind: ClientDomainRouteKind
  permission?: PermissionKey
  permission_any?: PermissionKey[]
  access?: AccessLevel
  redirect_to?: string
  redirect_destinations?: string[]
  canonical_handoff?: {
    match_query?: Record<string, string | string[]>
    query_targets?: Record<string, Record<string, string>>
    to: string
  }
}

interface ClientRouteManifest {
  routes: ClientDomainRouteDefinition[]
  flow_paths: Array<{ name: string; path_pattern: string }>
  portal_api_paths: Array<{ method: string; path: string }>
}

const manifest = routePolicy as ClientRouteManifest
const routeByName = new Map(manifest.routes.map(route => [route.name, route]))
const authenticatedRoutes = manifest.routes.map(route => ({
  definition: route,
  pattern: new RegExp(route.path_pattern),
}))
const flowRouteNames = new Set(manifest.flow_paths.map(route => route.name))
const canonicalHandoffDestinations = new Set(
  manifest.routes.flatMap(route => {
    const handoff = route.canonical_handoff
    if (!handoff) return []
    return [
      handoff.to,
      ...Object.values(handoff.query_targets ?? {}).flatMap(targets => Object.values(targets)),
    ]
  }),
)

export const clientDomainRoutes: readonly ClientDomainRouteDefinition[] = manifest.routes

export function isClientDomainRouteName(name: unknown): boolean {
  return typeof name === 'string' && routeByName.has(name)
}

export function isClientDomainFlowRouteName(name: unknown): boolean {
  return typeof name === 'string' && flowRouteNames.has(name)
}

export function clientDomainRedirect(name: unknown): string | null {
  if (typeof name !== 'string') return null
  const route = routeByName.get(name)
  return route?.kind === 'client_redirect' ? route.redirect_to ?? null : null
}

export function isClientDomainAuthenticatedPath(value: unknown): value is string {
  if (typeof value !== 'string' || value.length === 0 || value.length > 500
      || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')
      || /[\x00-\x1f\x7f]/.test(value)) return false

  try {
    const base = 'https://client-route.invalid'
    const target = new URL(value, base)
    return target.origin === base && authenticatedRoutes.some(route => route.pattern.test(target.pathname))
  } catch {
    return false
  }
}

/**
 * Citlivá klientská URL se na vlastní doméně obslouží canonical handoffem.
 * Výsledek je vždy pevný cíl z manifestu, nikdy origin ani cesta ze vstupu.
 */
export function clientDomainCanonicalHandoffPath(value: unknown): string | null {
  if (!isClientDomainAuthenticatedPath(value)) return null

  try {
    const target = new URL(value, 'https://client-route.invalid')
    for (const route of authenticatedRoutes) {
      const handoff = route.definition.canonical_handoff
      if (!handoff || !route.pattern.test(target.pathname)) continue
      const queryMatches = Object.entries(handoff.match_query ?? {}).every(([key, expected]) => {
        const values = target.searchParams.getAll(key)
        return values.length === 1 && (Array.isArray(expected)
          ? expected.includes(values[0])
          : values[0] === expected)
      })
      if (!queryMatches) continue
      for (const [key, targets] of Object.entries(handoff.query_targets ?? {})) {
        const value = target.searchParams.get(key)
        if (value !== null && targets[value]) return targets[value]
      }
      return handoff.to
    }
  } catch {
    // Syntaktické a cross-origin vstupy odmítl už isClientDomainAuthenticatedPath.
  }
  return null
}

/** Query canonical loginu smí vybrat jen jednu z manifestem deklarovaných cest. */
export function canonicalDomainLoginHandoffPath(value: unknown): string | null {
  return typeof value === 'string' && canonicalHandoffDestinations.has(value) ? value : null
}

export function usesClientNavigation(
  isClientRole: boolean,
  context: Pick<DomainContext, 'locked'> | null,
): boolean {
  return isClientRole || context?.locked === true
}
