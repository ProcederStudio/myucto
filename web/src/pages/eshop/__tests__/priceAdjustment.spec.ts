import { describe, expect, it } from 'vitest'
import { formatAdjustment, invalidAdjustment, invalidSignedPct, joinAdjustment, splitAdjustment } from '@/pages/eshop/priceAdjustment'

const fmt = (n: number) => String(n).replace('.', ',')

describe('sleva a přirážka cenové hladiny', () => {
  it('uložená hodnota se znaménkem → druh a kladná hodnota pro formulář', () => {
    expect(splitAdjustment('-45.000')).toEqual({ kind: 'markup', value: '45.000' })
    expect(splitAdjustment('10.000')).toEqual({ kind: 'discount', value: '10.000' })
    expect(splitAdjustment('0.000')).toEqual({ kind: 'discount', value: '0.000' })
    expect(splitAdjustment(null)).toEqual({ kind: 'discount', value: '' })
    expect(splitAdjustment('−5')).toEqual({ kind: 'markup', value: '5' })
  })

  it('druh a hodnota z formuláře → hodnota pro API', () => {
    expect(joinAdjustment('markup', '45')).toBe('-45')
    expect(joinAdjustment('markup', ' 4,5 ')).toBe('-4.5')
    expect(joinAdjustment('discount', '10')).toBe('10')
    expect(joinAdjustment('markup', '0')).toBe('0')
    expect(joinAdjustment('markup', '')).toBe('')
  })

  it('rozsah: sleva 0–100 %, přirážka 0–999,999 %, nejvýš tři desetinná místa', () => {
    expect(invalidAdjustment('discount', '100')).toBe(false)
    expect(invalidAdjustment('discount', '100.5')).toBe(true)
    expect(invalidAdjustment('markup', '45')).toBe(false)
    expect(invalidAdjustment('markup', '999.999')).toBe(false)
    expect(invalidAdjustment('markup', '1000')).toBe(true)
    expect(invalidAdjustment('markup', '-5')).toBe(true)
    expect(invalidAdjustment('discount', '1.2345')).toBe(true)
    expect(invalidAdjustment('discount', '')).toBe(true)
    expect(invalidAdjustment('discount', 'abc')).toBe(true)
  })

  it('hodnota se znaménkem: −999,999 až 100', () => {
    expect(invalidSignedPct('-45')).toBe(false)
    expect(invalidSignedPct('−45')).toBe(false)
    expect(invalidSignedPct('-1000')).toBe(true)
    expect(invalidSignedPct('100.5')).toBe(true)
    expect(invalidSignedPct('')).toBe(true)
  })

  it('zobrazení: přirážka s plusem, sleva s minusem', () => {
    expect(formatAdjustment('-45.000', fmt)).toBe('+45 %')
    expect(formatAdjustment('10.000', fmt)).toBe('−10 %')
    expect(formatAdjustment('2.5', fmt)).toBe('−2,5 %')
    expect(formatAdjustment('0', fmt)).toBe('0 %')
    expect(formatAdjustment(null, fmt)).toBe('—')
  })
})
