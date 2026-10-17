/**
 * Položka výčtu nedostatku zásob (409 `insufficient_stock`). Množství jsou
 * v základní jednotce karty (`unit`). `warehouse_*` a `unit` posílá server
 * od verze s oknem nedostatku; starší odpověď je nemá.
 */
export interface InsufficientStockItem {
  stock_item_id: number
  sku: string | null
  name: string | null
  requested: string
  available: string
  warehouse_id?: number | null
  warehouse_name?: string | null
  unit?: string | null
}

/**
 * Výčet chybějících položek z chyby API, jinak null. Vystavení faktury vrací
 * `stock.error.insufficient_stock` s `items`, skladové doklady i kód bez prefixu
 * a starší tvar s `details`.
 */
export function insufficientStockItems(e: unknown): InsufficientStockItem[] | null {
  const error = (e as { response?: { data?: { error?: Record<string, unknown> } } } | null)?.response?.data?.error
  const code = error?.code
  if (code !== 'insufficient_stock' && code !== 'stock.error.insufficient_stock') return null
  const items = (error?.items ?? error?.details) as unknown
  return Array.isArray(items) && items.length > 0 ? items as InsufficientStockItem[] : null
}
