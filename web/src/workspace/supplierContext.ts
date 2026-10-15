export interface WindowSupplierScope {
  windowId: string
  supplierId: number
}

const STORAGE_KEY = 'myinvoice.current_supplier_id'
let windowScope: WindowSupplierScope | null = null

export function installWindowSupplierScope(scope: WindowSupplierScope): void {
  if (!Number.isSafeInteger(scope.supplierId) || scope.supplierId <= 0) {
    throw new RangeError('Window supplier id must be a positive integer.')
  }
  if (windowScope && (windowScope.windowId !== scope.windowId || windowScope.supplierId !== scope.supplierId)) {
    throw new Error('Window supplier scope is immutable.')
  }
  windowScope = { ...scope }
}

export function getWindowSupplierScope(): Readonly<WindowSupplierScope> | null {
  return windowScope
}

export function requestSupplierId(): string | null {
  if (windowScope) return String(windowScope.supplierId)
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return value && /^\d+$/.test(value) && Number(value) > 0 ? value : null
  } catch {
    return null
  }
}

export function requestSupplierIdNumber(): number {
  return Number(requestSupplierId() ?? 0)
}
