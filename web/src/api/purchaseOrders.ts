import { requestSupplierId } from '@/workspace/supplierContext'
import { api } from './client'

/**
 * Objednávky dodavatelům (Epic SKLAD, fáze 4) + odvozené skladové kvantity
 * (skladem/rezervováno/na cestě/u dodavatele). Money/qty pole jsou DECIMAL
 * uložené na backendu jako string (money-safe vzor) — v TS proto vždy `string`.
 * Vše pod `X-Supplier-Id` hlavičkou (auto přes api/client.ts interceptor).
 */

export type PurchaseOrderState =
  | 'draft' | 'sent' | 'confirmed' | 'partially_received' | 'received' | 'closed' | 'cancelled'

export interface PurchaseOrder {
  id: number
  supplier_id: number
  vendor_id: number
  order_number: string | null
  vendor_reference: string | null
  order_date: string
  expected_date: string | null
  warehouse_id: number
  currency_id: number
  exchange_rate: string | null
  state: PurchaseOrderState
  total_without_vat: string
  total_with_vat: string
  note: string | null
  internal_note: string | null
  sent_at: string | null
  confirmed_at: string | null
  closed_at: string | null
  close_reason: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  created_at: string
  updated_at: string
  vendor_name: string | null
  warehouse_code: string | null
  warehouse_name: string | null
  currency_code: string | null
  qty_ordered_total: string
  qty_received_total: string
  qty_remaining_total: string
}

export interface PurchaseOrderLine {
  id: number
  order_id: number
  line_no: number
  stock_item_id: number | null
  warehouse_id: number | null
  vendor_sku: string | null
  description: string
  unit: string
  qty_ordered: string
  qty_confirmed: string | null
  qty_cancelled: string
  unit_price: string
  vat_rate_id: number | null
  expected_date: string | null
  has_over_delivery: boolean
  note: string | null
  sku: string | null
  item_name: string | null
  warehouse_code: string | null
  qty_received: string
  qty_effective: string
  qty_remaining: string
}

export interface PurchaseOrderReceiptLink {
  id: number
  doc_type: string
  doc_number: string | null
  doc_date: string
  status: string
  description: string
}

export interface PurchaseOrderDetail extends PurchaseOrder {
  lines: PurchaseOrderLine[]
  /**
   * ⚠ NEPOUŽITO — vždy `[]`. Párování objednávka ↔ přijatá faktura (§ 5.2 plánu)
   * není hotové: tabulku `purchase_order_invoice_links` nikdo neplní (viz
   * `PurchaseOrderRepository::invoiceLinks()`). Až vznikne, dostane tvar.
   */
  invoice_links: unknown[]
  receipts: PurchaseOrderReceiptLink[]
}

export interface PurchaseOrderLinePayload {
  stock_item_id?: number | null
  warehouse_id?: number | null
  vendor_sku?: string | null
  description: string
  unit?: string
  qty_ordered: string | number
  unit_price?: string | number
  vat_rate_id?: number | null
  expected_date?: string | null
  note?: string | null
}

export interface PurchaseOrderPayload {
  vendor_id: number
  order_date: string
  expected_date?: string | null
  warehouse_id: number
  currency_id: number
  exchange_rate?: string | number | null
  vendor_reference?: string | null
  note?: string | null
  internal_note?: string | null
  lines: PurchaseOrderLinePayload[]
}

export interface PurchaseOrderListFilters {
  state?: PurchaseOrderState | PurchaseOrderState[]
  /** 1 = draft+sent+confirmed+partially_received (zkratka pro „otevřené"). */
  open?: boolean
  vendor_id?: number
  warehouse_id?: number
  stock_item_id?: number
  q?: string
  from?: string
  to?: string
  expected_to?: string
  limit?: number
  offset?: number
}

export interface PurchaseOrderListResponse {
  items: PurchaseOrder[]
  total: number
  limit: number
  offset: number
}

export interface PurchaseOrderConfirmPayload {
  expected_date?: string | null
  lines?: Array<{ id: number; qty_confirmed?: string | number | null; expected_date?: string | null }>
}

export interface PurchaseOrderReceiptProposalLine {
  purchase_order_line_id: number
  stock_item_id: number | null
  sku: string | null
  description: string
  unit: string
  qty_ordered: string
  qty_received: string
  remaining_qty: string
  unit_cost: string
  cost_is_estimate: boolean
}

export interface PurchaseOrderReceiptProposal {
  order: {
    id: number
    order_number: string | null
    vendor_name: string | null
    warehouse_id: number
    currency_code: string | null
  }
  lines: PurchaseOrderReceiptProposalLine[]
  cost_is_estimate: boolean
}

export interface PurchaseOrderReceiptPayload {
  warehouse_id: number
  doc_date: string
  description?: string
  allow_over_delivery?: boolean
  lines: Array<{
    purchase_order_line_id: number
    qty: string | number
    unit_cost?: string | number
  }>
}

// ── Odvozené kvantity ("skladem / rezervováno / na cestě / u dodavatele") ──

export interface StockQuantityWarehouseRow {
  warehouse_id: number
  warehouse_code: string
  warehouse_name: string
  on_hand: string
  in_transit: string
}

export interface StockQuantityInTransitOrder {
  order_id: number
  order_number: string | null
  state: PurchaseOrderState
  vendor_name: string | null
  qty: string
  expected_date: string | null
}

export interface StockQuantityVendorOffer {
  client_id: number
  vendor_name: string
  purchase_price: string | null
  currency_code: string
  stock_qty: string | null
  delivery_days: number | null
  availability_state: string
}

export interface StockQuantityRow {
  stock_item_id: number
  sku: string
  name: string
  unit: string
  on_hand: string
  reserved: string
  sellable: string
  in_transit: string
  at_vendor: string
  available_to_promise: string
  earliest_expected_date: string | null
  min_qty: string | null
  warehouses: StockQuantityWarehouseRow[]
  in_transit_orders: StockQuantityInTransitOrder[]
  vendor_offers: StockQuantityVendorOffer[]
}

export interface StockQuantitiesResponse {
  items: StockQuantityRow[]
}

export interface StockInTransitOrderRef {
  order_id: number
  order_number: string | null
  state: PurchaseOrderState
  vendor_name: string | null
  qty: string
  expected_date: string | null
}

export interface StockInTransitRow {
  stock_item_id: number
  sku: string
  name: string
  warehouse_id: number
  warehouse_code: string
  qty_in_transit: string
  earliest_expected_date: string | null
  orders: StockInTransitOrderRef[]
}

export interface StockInTransitResponse {
  items: StockInTransitRow[]
}

export interface StockReservationInvoiceRef {
  invoice_id: number
  invoice_number: string | null
  client_name: string
  qty: string
  issue_date: string
  due_date: string
}

export interface StockReservationRow {
  stock_item_id: number
  sku: string
  name: string
  qty_reserved: string
  invoices: StockReservationInvoiceRef[]
}

export interface StockReservationsResponse {
  items: StockReservationRow[]
}

export interface StockReplenishmentPreferredVendor {
  client_id: number
  vendor_name: string
  purchase_price: string | null
  currency_code: string
  min_order_qty: string | null
  package_qty: string | null
  vendor_sku: string | null
}

export interface StockReplenishmentRow {
  stock_item_id: number
  sku: string
  name: string
  unit: string
  warehouse_id: number | null
  on_hand: string
  reserved: string
  in_transit: string
  sellable: string
  min_qty: string | null
  /** Čistá potřeba před zaokrouhlením na balení / minimum odběru. */
  shortfall: string
  suggested_qty: string
  preferred_vendor: StockReplenishmentPreferredVendor | null
}

export interface StockReplenishmentResponse {
  items: StockReplenishmentRow[]
  total: number
  limit: number
  offset: number
}

export interface PurchaseOrderBulkLine {
  stock_item_id: number
  qty: number | string
  vendor_id?: number
  warehouse_id?: number
  unit_price?: number | string
}

export interface PurchaseOrderBulkPayload {
  items: PurchaseOrderBulkLine[]
  warehouse_id?: number
  order_date?: string
  expected_date?: string | null
  currency_id?: number
  note?: string | null
}

export interface PurchaseOrderBulkSkipped {
  stock_item_id: number
  sku?: string
  reason: 'item_not_found' | 'invalid_qty' | 'no_vendor'
}

export interface PurchaseOrderBulkResponse {
  orders: PurchaseOrderDetail[]
  created: number
  skipped: PurchaseOrderBulkSkipped[]
}

function toParams<T extends object>(f: T = {} as T): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(f)) {
    if (v === undefined || v === null || v === '') continue
    if (Array.isArray(v)) {
      if (v.length === 0) continue
      out[k] = v.join(',')
      continue
    }
    out[k] = typeof v === 'boolean' ? (v ? 1 : 0) : (v as string | number)
  }
  return out
}

function downloadUrl(path: string): string {
  const sid = requestSupplierId()
  const params = new URLSearchParams()
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
  const qs = params.toString()
  return `/api${path}${qs ? (path.includes('?') ? '&' : '?') + qs : ''}`
}

export const purchaseOrdersApi = {
  list: (filters: PurchaseOrderListFilters = {}) =>
    api.get<PurchaseOrderListResponse>('/stock/purchase-orders', { params: toParams(filters) }).then(r => r.data),
  get: (id: number) => api.get<PurchaseOrderDetail>(`/stock/purchase-orders/${id}`).then(r => r.data),
  create: (payload: PurchaseOrderPayload) =>
    api.post<PurchaseOrderDetail>('/stock/purchase-orders', payload).then(r => r.data),
  update: (id: number, payload: PurchaseOrderPayload) =>
    api.put<PurchaseOrderDetail>(`/stock/purchase-orders/${id}`, payload).then(r => r.data),
  delete: (id: number) => api.delete<{ deleted: true }>(`/stock/purchase-orders/${id}`).then(r => r.data),
  send: (id: number) => api.post<PurchaseOrderDetail>(`/stock/purchase-orders/${id}/send`).then(r => r.data),
  confirm: (id: number, payload: PurchaseOrderConfirmPayload = {}) =>
    api.post<PurchaseOrderDetail>(`/stock/purchase-orders/${id}/confirm`, payload).then(r => r.data),
  cancel: (id: number, reason: string) =>
    api.post<PurchaseOrderDetail>(`/stock/purchase-orders/${id}/cancel`, { reason }).then(r => r.data),
  close: (id: number, reason: string) =>
    api.post<PurchaseOrderDetail>(`/stock/purchase-orders/${id}/close`, { reason }).then(r => r.data),
  reopen: (id: number) => api.post<PurchaseOrderDetail>(`/stock/purchase-orders/${id}/reopen`).then(r => r.data),
  pdfUrl: (id: number) => downloadUrl(`/stock/purchase-orders/${id}/pdf`),
  receiptPropose: (id: number) =>
    api.get<PurchaseOrderReceiptProposal>(`/stock/purchase-orders/${id}/receipt`).then(r => r.data),
  receiptCreate: (id: number, payload: PurchaseOrderReceiptPayload) =>
    api.post<{ id: number; doc_type: string }>(`/stock/purchase-orders/${id}/receipt`, payload).then(r => r.data),

  // ── Odvozené kvantity ──────────────────────────────────────────────────
  quantities: (itemIds: number[], warehouseId?: number) =>
    api.get<StockQuantitiesResponse>('/stock/quantities', {
      params: toParams({ item_ids: itemIds.length ? itemIds.join(',') : undefined, warehouse_id: warehouseId }),
    }).then(r => r.data),
  inTransit: (itemIds: number[] = [], warehouseId?: number) =>
    api.get<StockInTransitResponse>('/stock/in-transit', {
      params: toParams({ item_ids: itemIds.length ? itemIds.join(',') : undefined, warehouse_id: warehouseId }),
    }).then(r => r.data),
  reservations: (itemIds: number[] = [], warehouseId?: number) =>
    api.get<StockReservationsResponse>('/stock/reservations', {
      params: toParams({ item_ids: itemIds.length ? itemIds.join(',') : undefined, warehouse_id: warehouseId }),
    }).then(r => r.data),
  replenishment: (
    filters: { warehouse_id?: number; below_min?: boolean; coefficient?: number; limit?: number; offset?: number } = {},
  ) =>
    api.get<StockReplenishmentResponse>('/stock/replenishment', { params: toParams(filters) }).then(r => r.data),
  /** Zaškrtnuté karty → JEDNA objednávka NA DODAVATELE (seskupení dělá backend). */
  bulkCreate: (payload: PurchaseOrderBulkPayload) =>
    api.post<PurchaseOrderBulkResponse>('/stock/purchase-orders/bulk', payload).then(r => r.data),
}
