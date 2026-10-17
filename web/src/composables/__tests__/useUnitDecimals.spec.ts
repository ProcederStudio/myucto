import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
import { decimalPlaces, unchangedRows, useUnitDecimals } from '../useUnitDecimals'

describe('decimalPlaces', () => {
  it.each([
    [3, 0],
    [1.5, 1],
    [0.1 + 0.2, 1],
    [-1.25, 2],
    ['1.500', 1],
    ['2,75', 2],
    [' 4.0 ', 0],
    ['1.2345', 4],
    ['', null],
    ['abc', null],
    [null, null],
    [undefined, null],
  ])('%j má %j míst', (value, expected) => {
    expect(decimalPlaces(value)).toBe(expected)
  })
})

describe('useUnitDecimals', () => {
  const units = ref([
    { code: 'ks', quantity_decimals: 0 },
    { code: 'kg', quantity_decimals: 2 },
    { code: 'm', quantity_decimals: null },
    { code: 'h' },
  ])

  it('páruje jednotku bez ohledu na velikost písmen a mezery', () => {
    const { decimalsFor } = useUnitDecimals(units)
    expect(decimalsFor('KS')).toBe(0)
    expect(decimalsFor(' kg ')).toBe(2)
  })

  it('jednotka bez nastavení nebo mimo číselník nemá omezení', () => {
    const { decimalsFor, tooPrecise } = useUnitDecimals(units)
    expect(decimalsFor('m')).toBeNull()
    expect(decimalsFor('h')).toBeNull()
    expect(decimalsFor('neznámá')).toBeNull()
    expect(tooPrecise('1.2345', 'm')).toBe(false)
    expect(tooPrecise(0.125, 'neznámá')).toBe(false)
  })

  it('hlásí přesnější vstup, nic nezaokrouhluje', () => {
    const { tooPrecise } = useUnitDecimals(units)
    expect(tooPrecise(1.5, 'ks')).toBe(true)
    expect(tooPrecise('2', 'ks')).toBe(false)
    expect(tooPrecise('1.25', 'kg')).toBe(false)
    expect(tooPrecise('1.255', 'KG')).toBe(true)
    expect(tooPrecise('', 'ks')).toBe(false)
  })

  it('krok číselného pole podle jednotky, bez nastavení jako dosud 0.001', () => {
    const { stepFor } = useUnitDecimals(units)
    expect(stepFor('ks')).toBe('1')
    expect(stepFor('kg')).toBe('0.01')
    expect(stepFor('m')).toBe('0.001')
    expect(stepFor(null)).toBe('0.001')
  })

  it('kalkulačka v poli množství: bez nastavení 2 místa jako dosud, s nastavením přesnost sloupce', () => {
    const { mathDecimalsFor } = useUnitDecimals(units)
    expect(mathDecimalsFor('m')).toBe(2)
    expect(mathDecimalsFor('ks')).toBe(3)
    expect(mathDecimalsFor('kg')).toBe(3)
  })

  it('reaguje na změnu číselníku', () => {
    const list = ref<{ code: string; quantity_decimals?: number | null }[]>([])
    const { decimalsFor } = useUnitDecimals(list)
    expect(decimalsFor('ks')).toBeNull()
    list.value = [{ code: 'ks', quantity_decimals: 0 }]
    expect(decimalsFor('ks')).toBe(0)
  })
})

// Stejné párování jako UnitPrecision::unchangedIndexes() na serveru: řádek uložený
// dřív, než jednotka dostala nastavení, editor neblokuje, pokud se nezměnil.
describe('unchangedRows', () => {
  it('páruje podle jednotky a množství, každý uložený řádek jednou', () => {
    const stored = [{ quantity: '2.125', unit: 'kg' }, { quantity: '1.500', unit: 'ks' }]
    const a = { quantity: 2.125, unit: 'KG' }
    const b = { quantity: '2.125', unit: 'kg' }
    const c = { quantity: '1.25', unit: 'ks' }
    const d = { quantity: '1,5' } // bez jednotky = ks
    const set = unchangedRows([a, b, c, d], stored)
    expect(set.has(a)).toBe(true)
    expect(set.has(b)).toBe(false)
    expect(set.has(c)).toBe(false)
    expect(set.has(d)).toBe(true)
  })

  it('bez uložených řádků nic', () => {
    expect(unchangedRows([{ quantity: 1, unit: 'ks' }], []).size).toBe(0)
  })
})
