import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { InternalAxiosRequestConfig } from 'axios'

const originalParent = Object.getOwnPropertyDescriptor(window, 'parent')

async function installScope(supplierId = 11) {
  const context = await import('@/workspace/supplierContext')
  context.installWindowSupplierScope({ windowId: 'window-a', supplierId })
  return context
}

function successfulAdapter(seen: InternalAxiosRequestConfig[]) {
  return async (config: InternalAxiosRequestConfig) => {
    seen.push(config)
    return { data: {}, status: 200, statusText: 'OK', headers: {}, config }
  }
}

describe('window supplier API isolation', () => {
  beforeEach(() => {
    vi.resetModules()
    localStorage.clear()
  })

  afterEach(() => {
    vi.restoreAllMocks()
    if (originalParent) Object.defineProperty(window, 'parent', originalParent)
  })

  it('přepíše firmu na skutečných GET i POST požadavcích a odmítne explicitní cizí hlavičku', async () => {
    localStorage.setItem('myinvoice.current_supplier_id', '22')
    await installScope(11)
    const { api } = await import('@/api/client')
    const seen: InternalAxiosRequestConfig[] = []
    api.defaults.adapter = successfulAdapter(seen)

    localStorage.setItem('myinvoice.current_supplier_id', '33')
    await api.get('/isolation-read')
    await api.post('/isolation-write', { value: 1 }, { headers: { 'X-Supplier-Id': '999' } })

    expect(seen.map(config => config.headers.get('X-Supplier-Id'))).toEqual(['11', '11'])
    expect(seen.map(config => config.url)).toEqual(['/isolation-read', '/isolation-write'])
  })

  it('vytváří přímé URL stále pro připnutou firmu', async () => {
    localStorage.setItem('myinvoice.current_supplier_id', '22')
    await installScope(11)
    const { invoicesApi } = await import('@/api/invoices')

    localStorage.setItem('myinvoice.current_supplier_id', '33')

    expect(invoicesApi.pdfUrl(7)).toBe('/api/invoices/7/pdf?supplier_id=11')
    expect(invoicesApi.pdfUrl(7, true)).toBe('/api/invoices/7/pdf?download=1&supplier_id=11')
  })

  it('při odebrání přístupu informuje parent a nemaže globální firmu ani nereflešuje frame', async () => {
    localStorage.setItem('myinvoice.current_supplier_id', '22')
    await installScope(11)
    const postMessage = vi.fn()
    Object.defineProperty(window, 'parent', {
      configurable: true,
      value: { postMessage },
    })
    const reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)
    const { api } = await import('@/api/client')
    api.defaults.adapter = async (config) => {
      throw {
        config,
        response: {
          status: 403,
          statusText: 'Forbidden',
          headers: {},
          config,
          data: { error: { code: 'forbidden_supplier' } },
        },
      }
    }

    await expect(api.get('/revoked')).rejects.toBeTruthy()

    expect(postMessage).toHaveBeenCalledWith({
      type: 'myucto-window-error',
      id: 'window-a',
      code: 'access-revoked',
    }, window.location.origin)
    expect(localStorage.getItem('myinvoice.current_supplier_id')).toBe('22')
    expect(reload).not.toHaveBeenCalled()
  })
})
