import { inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'
import { getWindowSupplierScope } from '@/workspace/supplierContext'

export function useWindowRefresh(
  resources: readonly string[],
  refresh: () => void | Promise<unknown>,
  canRefresh: () => boolean = () => true,
) {
  const context = inject(windowContextKey, null)
  const supplierId = getWindowSupplierScope()?.supplierId
  const pending = ref(false)
  let running = false
  let disposed = false
  let observer: MutationObserver | undefined

  function ready() {
    return context?.active.value && canRefresh()
      && !document.querySelector('[role="dialog"], [aria-modal="true"]')
  }

  async function flush() {
    if (disposed || running || !pending.value || !ready()) return
    pending.value = false
    running = true
    let succeeded = false
    try {
      await refresh()
      succeeded = true
    } catch {
      pending.value = true
    } finally {
      running = false
      if (succeeded) void flush()
    }
  }

  function changed(event: Event) {
    const detail = (event as CustomEvent<unknown>).detail
    if (!detail || typeof detail !== 'object') return
    const { supplierId: changedSupplier, resource } = detail as Record<string, unknown>
    if (changedSupplier !== supplierId || typeof resource !== 'string' || !resources.includes(resource)) return
    pending.value = true
    void flush()
  }

  if (context && supplierId) {
    watch([context.active, canRefresh], () => { void flush() }, { flush: 'post' })
    onMounted(() => {
      window.addEventListener('myucto:window-data-changed', changed)
      observer = new MutationObserver(() => { if (pending.value) void flush() })
      observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['role', 'aria-modal'] })
    })
    onBeforeUnmount(() => {
      disposed = true
      window.removeEventListener('myucto:window-data-changed', changed)
      observer?.disconnect()
    })
  }

  return { pending }
}
