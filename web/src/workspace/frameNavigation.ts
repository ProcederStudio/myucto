import { nextTick } from 'vue'
import type { Router } from 'vue-router'

export function installFrameNavigation(router: Router, traverse: (direction: -1 | 1) => void, resetScroll: () => void): void {
  const history = router.options.history
  const push = history.push.bind(history)
  const replace = history.replace.bind(history)
  const go = router.go.bind(router)
  let length = 1
  const position = () => Number(history.state.workspaceFramePosition ?? 0)
  history.replace = (to, data) => replace(to, { ...data, workspaceFramePosition: position() })
  history.replace(history.location, history.state)
  history.push = (to, data) => {
    const next = position() + 1
    push(to, { ...data, workspaceFramePosition: next })
    length = next + 1
  }
  router.go = delta => {
    if (delta === 0) return
    if (position() + delta >= 0 && position() + delta < length) go(delta)
    else traverse(delta < 0 ? -1 : 1)
  }
  router.back = () => router.go(-1)
  router.forward = () => router.go(1)
  router.afterEach((to, from, failure) => {
    if (!failure && to.path !== from.path) void nextTick(resetScroll)
  })
}
