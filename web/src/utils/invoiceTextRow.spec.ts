import { describe, expect, it } from 'vitest'
import { amountItems, blankTextItem, isTextItem, textItemPayload } from './invoiceTextRow'

describe('invoiceTextRow', () => {
  it('pozná textový řádek jen podle druhu', () => {
    expect(isTextItem({ item_kind: 'text' })).toBe(true)
    expect(isTextItem({ item_kind: 'standard' })).toBe(false)
    expect(isTextItem({ item_kind: 'discount' })).toBe(false)
    expect(isTextItem({})).toBe(false)
    expect(isTextItem(null)).toBe(false)
  })

  it('řádky s částkou vynechají textové a drží pořadí', () => {
    const items = [{ description: 'A' }, { description: 'Nadpis', item_kind: 'text' }, { description: 'B', item_kind: 'standard' }]
    expect(amountItems(items).map(i => i.description)).toEqual(['A', 'B'])
  })

  it('nový textový řádek je nulový a bez skladu i OSS', () => {
    const row = blankTextItem(3, 7)
    expect(row).toMatchObject({ item_kind: 'text', description: '', quantity: 0, unit_price_without_vat: 0,
      vat_rate_id: 7, order_index: 3, stock_item_id: null, oss_applicable: false })
  })

  it('posílá jen druh, text a pořadí', () => {
    expect(textItemPayload({ description: 'Oddíl B' }, 2)).toEqual({ item_kind: 'text', description: 'Oddíl B', order_index: 2 })
  })
})
