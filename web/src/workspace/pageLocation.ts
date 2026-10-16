import type { Router } from 'vue-router'
import { getWindowSupplierScope } from './supplierContext'

export function pageLocation(route: { fullPath: string }): URL {
  const path = getWindowSupplierScope() ? route.fullPath
    : `${window.location.pathname}${window.location.search ?? ''}${window.location.hash ?? ''}`
  return new URL(path, window.location.origin || 'http://localhost')
}

export function replacePageLocation(router: Router, url: URL): void {
  const path = `${url.pathname}${url.search}${url.hash}`
  if (getWindowSupplierScope()) void router.replace(path)
  else window.history.replaceState(window.history.state ?? {}, '', path)
}

export function withoutCallbackTokens(fullPath: string): string {
  const url = new URL(fullPath, 'http://localhost')
  if (['/admin/databox', '/isds-gateway/callback'].includes(url.pathname)) {
    url.searchParams.delete('appToken')
    url.searchParams.delete('sessionId')
  } else if (url.pathname === '/activation/purchase') {
    const params = new URLSearchParams(url.hash.slice(1))
    if (params.has('purchase') || params.has('state')) url.hash = ''
  }
  return `${url.pathname}${url.search}${url.hash}`
}
