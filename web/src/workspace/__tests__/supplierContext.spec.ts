import { beforeEach, describe, expect, it, vi } from 'vitest'

describe('supplierContext', () => {
  beforeEach(() => {
    vi.resetModules()
    localStorage.clear()
  })

  it('připne firmu okna nezávisle na změnách aktivní firmy v localStorage', async () => {
    localStorage.setItem('myinvoice.current_supplier_id', '22')
    const context = await import('@/workspace/supplierContext')

    context.installWindowSupplierScope({ windowId: 'window-a', supplierId: 11 })
    expect(context.requestSupplierId()).toBe('11')
    expect(context.requestSupplierIdNumber()).toBe(11)

    localStorage.setItem('myinvoice.current_supplier_id', '33')
    expect(context.requestSupplierId()).toBe('11')
    expect(context.getWindowSupplierScope()).toEqual({ windowId: 'window-a', supplierId: 11 })
  })

  it('nedovolí změnit identitu již inicializovaného okna', async () => {
    const context = await import('@/workspace/supplierContext')
    context.installWindowSupplierScope({ windowId: 'window-a', supplierId: 11 })

    expect(() => context.installWindowSupplierScope({ windowId: 'window-a', supplierId: 11 })).not.toThrow()
    expect(() => context.installWindowSupplierScope({ windowId: 'window-a', supplierId: 12 }))
      .toThrow('Window supplier scope is immutable.')
    expect(() => context.installWindowSupplierScope({ windowId: 'window-b', supplierId: 11 }))
      .toThrow('Window supplier scope is immutable.')
    expect(context.requestSupplierId()).toBe('11')
  })

  it('supplier store ve frame nepřepne firmu ani nepřepíše sdílený výběr', async () => {
    localStorage.setItem('myinvoice.current_supplier_id', '22')
    const context = await import('@/workspace/supplierContext')
    context.installWindowSupplierScope({ windowId: 'window-a', supplierId: 11 })
    const { createPinia, setActivePinia } = await import('pinia')
    const { useSupplierStore } = await import('@/stores/supplier')
    setActivePinia(createPinia())
    const store = useSupplierStore()

    expect(store.currentSupplierId).toBe(11)
    store.setSupplier(22)

    expect(store.currentSupplierId).toBe(11)
    expect(context.requestSupplierId()).toBe('11')
    expect(localStorage.getItem('myinvoice.current_supplier_id')).toBe('22')
  })
})

describe('frameProtocol', () => {
  beforeEach(() => vi.resetModules())

  it('přijme bootstrap jen ve vloženém frame s platnými identifikátory', async () => {
    const { parseWindowFrameBootstrap } = await import('@/workspace/frameProtocol')
    const valid = '?__myucto_window=window-7&__myucto_supplier=42'

    expect(parseWindowFrameBootstrap(valid, false)).toBeNull()
    expect(parseWindowFrameBootstrap(valid, true)).toEqual({ id: 'window-7', supplierId: 42 })
    expect(parseWindowFrameBootstrap('?__myucto_window=../window&__myucto_supplier=42', true)).toBeNull()
    expect(parseWindowFrameBootstrap('?__myucto_window=window-7&__myucto_supplier=0', true)).toBeNull()
    expect(parseWindowFrameBootstrap('?__myucto_window=window-7&__myucto_supplier=1.5', true)).toBeNull()
  })

  it('odmítne zprávu určenou jinému oknu', async () => {
    const { isWindowFrameParentMessage } = await import('@/workspace/frameProtocol')
    const message = {
      type: 'myucto-window-init',
      id: 'window-a',
      to: '/invoices',
      active: true,
      theme: 'light',
      locale: 'cs',
    }

    expect(isWindowFrameParentMessage(message, 'window-a')).toBe(true)
    expect(isWindowFrameParentMessage(message, 'window-b')).toBe(false)
    expect(isWindowFrameParentMessage({ ...message, globalShortcuts: ['alt+w', 5] }, 'window-a')).toBe(false)
  })
})
