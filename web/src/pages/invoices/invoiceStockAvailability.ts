export interface InvoiceStockAvailabilityRow {
  stock_item_id?: number | null
  warehouse_id?: number | null
}

export interface InvoiceStockAvailabilityGroup {
  warehouseId: number | null
  itemIds: number[]
}

export function invoiceStockAvailabilityKey(stockItemId: number, warehouseId: number | null): string {
  return `${warehouseId ?? 'all'}:${stockItemId}`
}

export function groupInvoiceStockAvailability(
  rows: InvoiceStockAvailabilityRow[],
  defaultWarehouseId: number | null,
): InvoiceStockAvailabilityGroup[] {
  const groups = new Map<number | null, Set<number>>()
  for (const row of rows) {
    if (!row.stock_item_id) continue
    const warehouseId = row.warehouse_id ?? defaultWarehouseId
    const itemIds = groups.get(warehouseId) ?? new Set<number>()
    itemIds.add(row.stock_item_id)
    groups.set(warehouseId, itemIds)
  }
  return [...groups.entries()].map(([warehouseId, itemIds]) => ({
    warehouseId,
    itemIds: [...itemIds],
  }))
}

export interface StockSearchBadge {
  qty: string
  unit: string
  /** neutral = skladem; warning = nula nebo minus (karta se nevydá). */
  tone: 'neutral' | 'warning'
}

/**
 * Stav skladu v nabídce karet (GET /stock/items/search s `warehouse_id` řádku).
 * `qty` je v základní jednotce karty, stejně jako dostupnost řádku po výběru.
 * Neskladová karta (`is_stocked` false) a odpověď bez `qty` badge nemají.
 */
export function stockSearchBadge(
  result: { unit: string; qty?: string | null; is_stocked?: boolean },
  formatQty: (qty: number) => string,
): StockSearchBadge | null {
  if (result.is_stocked === false || result.qty == null) return null
  const qty = Number(result.qty)
  if (!Number.isFinite(qty)) return null
  return { qty: formatQty(qty), unit: result.unit, tone: qty > 0 ? 'neutral' : 'warning' }
}
