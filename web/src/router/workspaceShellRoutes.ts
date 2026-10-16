import type { RouteRecordRaw } from 'vue-router'

const shellRoute = { render: () => null }

export function workspaceShellRoutes(routes: RouteRecordRaw[]): RouteRecordRaw[] {
  return routes.map(route => route.component ? { ...route, component: shellRoute } : route)
}
