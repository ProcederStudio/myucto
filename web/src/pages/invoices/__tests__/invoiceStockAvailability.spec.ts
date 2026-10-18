import { describe, expect, it } from 'vitest'
import {
  groupInvoiceStockAvailability,
  invoiceStockAvailabilityKey,
  stockSearchBadge,
} from '@/pages/invoices/invoiceStockAvailability'

describe('invoice stock availability', () => {
  it('seskupí položky podle skutečného skladu řádku a doplní výchozí sklad', () => {
    expect(groupInvoiceStockAvailability([
      { stock_item_id: 10, warehouse_id: 2 },
      { stock_item_id: 10, warehouse_id: 3 },
      { stock_item_id: 11, warehouse_id: null },
      { stock_item_id: 11, warehouse_id: null },
      { stock_item_id: null, warehouse_id: 2 },
    ], 5)).toEqual([
      { warehouseId: 2, itemIds: [10] },
      { warehouseId: 3, itemIds: [10] },
      { warehouseId: 5, itemIds: [11] },
    ])
  })

  it('oddělí klíče stejné karty v různých skladech', () => {
    expect(invoiceStockAvailabilityKey(10, 2)).toBe('2:10')
    expect(invoiceStockAvailabilityKey(10, 3)).toBe('3:10')
    expect(invoiceStockAvailabilityKey(10, null)).toBe('all:10')
  })

  it('stav v nabídce karet: množství v základní jednotce, nula a minus jako varování', () => {
    const fmt = (n: number) => String(n).replace('.', ',')
    expect(stockSearchBadge({ unit: 'ks', is_stocked: true, qty: '12.000' }, fmt)).toEqual({ qty: '12', unit: 'ks', tone: 'neutral' })
    expect(stockSearchBadge({ unit: 'kg', is_stocked: true, qty: '2.500' }, fmt)).toEqual({ qty: '2,5', unit: 'kg', tone: 'neutral' })
    expect(stockSearchBadge({ unit: 'ks', is_stocked: true, qty: '0.000' }, fmt)).toEqual({ qty: '0', unit: 'ks', tone: 'warning' })
    expect(stockSearchBadge({ unit: 'ks', is_stocked: true, qty: '-1.000' }, fmt)).toEqual({ qty: '-1', unit: 'ks', tone: 'warning' })
  })

  it('neskladová karta a starší server bez stavu badge nemají', () => {
    const fmt = (n: number) => String(n)
    expect(stockSearchBadge({ unit: 'h', is_stocked: false, qty: null }, fmt)).toBeNull()
    expect(stockSearchBadge({ unit: 'ks' }, fmt)).toBeNull()
  })
})
