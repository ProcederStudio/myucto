import { onMounted, onUnmounted, watch, type Ref } from 'vue'

export function useScrollLoadMore(
  target: Ref<HTMLElement | null>,
  canLoad: () => boolean,
  loadMore: () => Promise<unknown>,
): void {
  let previousY = 0
  let busy = false
  let host: Window | HTMLElement | null = null

  function position(): number {
    return host === window ? window.scrollY : (host as HTMLElement | null)?.scrollTop ?? 0
  }

  function onScroll() {
    const y = position()
    const movingDown = y > previousY
    previousY = y
    if (!movingDown || busy || !canLoad()) return
    const el = target.value
    if (!el || el.getClientRects().length === 0 || getComputedStyle(el).clipPath === 'inset(50%)') return
    const bottom = host === window
      ? window.innerHeight
      : Math.min((host as HTMLElement).getBoundingClientRect().bottom, window.innerHeight)
    if (el.getBoundingClientRect().top > bottom + 200) return
    busy = true
    void loadMore().catch(() => {}).finally(() => { busy = false })
  }

  function bind(el: HTMLElement | null) {
    host?.removeEventListener('scroll', onScroll)
    const pane = el?.closest<HTMLElement>('.workspace-pane-scroll.overflow-auto')
    host = el ? pane ?? window : null
    previousY = position()
    host?.addEventListener('scroll', onScroll, { passive: true })
  }

  watch(target, bind)
  onMounted(() => bind(target.value))
  onUnmounted(() => host?.removeEventListener('scroll', onScroll))
}
