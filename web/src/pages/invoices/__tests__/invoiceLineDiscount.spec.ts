import { describe, expect, it } from 'vitest'
import { legacyListPrice, lineDiscountPct, quotedListPrice } from '@/pages/invoices/invoiceLineDiscount'

describe('ceníková cena a sleva řádku faktury', () => {
  it('spočítá slevu stejně jako server (tři desetinná místa, půl nahoru)', () => {
    expect(lineDiscountPct(1000, 900)).toBe(10)
    expect(lineDiscountPct('300', '100')).toBe(66.667)
    expect(lineDiscountPct('800.00', 700)).toBe(12.5)
    expect(lineDiscountPct(500, 0)).toBe(100)
    expect(lineDiscountPct(1000, 1050)).toBe(-5)
  })

  it('bez ceníku, při shodě a u záporné ceny slevu nemá', () => {
    expect(lineDiscountPct(null, 900)).toBeNull()
    expect(lineDiscountPct(undefined, 900)).toBeNull()
    expect(lineDiscountPct(0, 900)).toBeNull()
    expect(lineDiscountPct(1000, 1000)).toBeNull()
    expect(lineDiscountPct(1000, -100)).toBeNull()
    expect(lineDiscountPct(1000, '')).toBeNull()
  })

  it('ceníková cena z nacenění a z karty bez nacenění', () => {
    expect(quotedListPrice({ list_unit_price: '800.00' })).toBe(800)
    expect(quotedListPrice({ list_unit_price: null })).toBeNull()
    expect(quotedListPrice({})).toBeNull()
    expect(legacyListPrice({ sale_price_without_vat: '1000.00' })).toBe(1000)
    expect(legacyListPrice({ sale_price_without_vat: null })).toBeNull()
  })
})
