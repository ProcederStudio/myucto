import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, defineComponent, h, provide, ref } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'
import { useWindowRefresh } from '../useWindowRefresh'

vi.mock('@/workspace/supplierContext', () => ({ getWindowSupplierScope: () => ({ supplierId: 123, windowId: 'window-test' }) }))

const wrappers: ReturnType<typeof mount>[] = []
afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
  document.body.innerHTML = ''
})

function open(activeInitially = true) {
  const active = ref(activeInitially)
  const editing = ref(false)
  const refresh = vi.fn().mockResolvedValue(undefined)
  const draft = ref('unsaved value')
  let pending = ref(false)
  const Child = defineComponent({
    setup() {
      pending = useWindowRefresh(['invoices', 'clients'], refresh, () => !editing.value).pending
      return () => h('input', { value: draft.value, onInput: (event: Event) => { draft.value = (event.target as HTMLInputElement).value } })
    },
  })
  const wrapper = mount(defineComponent({
    setup() {
      provide(windowContextKey, {
        active: computed(() => active.value), paneId: computed(() => 'primary' as const), root: document.body, portal: document.body,
      })
      return () => h(Child)
    },
  }), { attachTo: document.body })
  wrappers.push(wrapper)
  return { wrapper, active, editing, refresh, pending, draft }
}

function changed(resource: unknown = 'invoices', supplierId: unknown = 123) {
  window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: { supplierId, resource } }))
}

describe('retained window data refresh', () => {
  it('coalesces parked changes and refreshes once when the window becomes active', async () => {
    const { active, refresh, pending, wrapper } = open(false)
    await wrapper.find('input').setValue('still editing this draft')
    changed()
    changed('clients')
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    expect(pending.value).toBe(true)
    active.value = true
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
    expect(pending.value).toBe(false)
    expect(wrapper.find('input').element.value).toBe('still editing this draft')
  })

  it('ignores another company, unrelated resources, and malformed messages', async () => {
    const { refresh } = open()
    changed('invoices', 456)
    changed('invoices', '123')
    changed('purchase-invoices')
    changed(null)
    window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: null }))
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    changed('clients')
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('defers refreshing inline editing until its draft is saved or cancelled', async () => {
    const { refresh, editing } = open()
    editing.value = true
    changed()
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    editing.value = false
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('keeps an open modal intact and refreshes after it closes', async () => {
    const { refresh } = open()
    const dialog = document.createElement('div')
    dialog.setAttribute('role', 'dialog')
    document.body.append(dialog)
    changed()
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
    dialog.remove()
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('serializes another invalidation arriving during refresh', async () => {
    const { refresh } = open()
    let complete!: () => void
    refresh.mockImplementationOnce(() => new Promise<void>(resolve => { complete = resolve }))
    changed()
    changed()
    expect(refresh).toHaveBeenCalledTimes(1)
    complete()
    await flushPromises()
    expect(refresh).toHaveBeenCalledTimes(2)
  })

  it('stops listening after the retained window is evicted', async () => {
    const { wrapper, refresh } = open()
    wrapper.unmount()
    changed()
    await flushPromises()
    expect(refresh).not.toHaveBeenCalled()
  })
})
