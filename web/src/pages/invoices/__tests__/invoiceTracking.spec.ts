import { describe, expect, it } from 'vitest'
import {
  anyExceedsAvailable,
  exceedsAvailable,
  freeTextAllocation,
  isReturnRow,
  preselectFromSearch,
  selectionState,
  setLotQuantity,
  takenByOtherRows,
  toggleSerial,
  trackingChoices,
  trackingKey,
  type TrackingUnit,
} from '@/pages/invoices/invoiceTracking'

const unit = (over: Partial<TrackingUnit>): TrackingUnit => ({ serial_number: null, lot_code: null, expires_on: null, quantity: '1.000', ...over })
const sel = (over: Partial<TrackingUnit>) => ({ ...unit(over) })

describe('výběr sériových čísel a šarží na faktuře', () => {
  it('nabídka ze zdroje, odečte kusy vybrané na jiných řádcích', () => {
    const choices = trackingChoices(
      [unit({ lot_code: 'L1', expires_on: '2099-12-31', quantity: '5.000' }), unit({ serial_number: 'VIN-A' })],
      [],
      [],
      new Map([['lot:L1|2099-12-31', 2], ['serial:VIN-A', 1]]),
    )
    expect(choices.map(c => [c.key, c.available, c.takenElsewhere])).toEqual([
      ['lot:L1|2099-12-31', 3, 2],
      ['serial:VIN-A', 0, 1],
    ])
  })

  it('vybraný i dřív vybraný kus (odškrtnutý) zůstane v nabídce, i když ve zdroji není', () => {
    const choices = trackingChoices([unit({ serial_number: 'VIN-A' })], [sel({ serial_number: 'VIN-B' })], [unit({ serial_number: 'VIN-C' })], new Map())
    expect(choices.map(c => [c.serial_number, c.available])).toEqual([['VIN-A', 1], ['VIN-B', 0], ['VIN-C', 0]])
  })

  it('kusy vybrané na jiných řádcích téže karty a skladu', () => {
    const rows = [
      { stock_item_id: 7, warehouse: 1, tracking_allocations: [sel({ serial_number: 'VIN-A' })] },
      { stock_item_id: 7, warehouse: 1, tracking_allocations: [sel({ lot_code: 'L1', quantity: '2' })] },
      { stock_item_id: 7, warehouse: 2, tracking_allocations: [sel({ serial_number: 'VIN-B' })] },
      { stock_item_id: 8, warehouse: 1, tracking_allocations: [sel({ serial_number: 'VIN-C' })] },
    ]
    expect([...takenByOtherRows(rows, 1, 7, 1)]).toEqual([['serial:VIN-A', 1]])
  })

  it('sériové číslo se zaškrtnutím přidá a odebere', () => {
    const choice = { key: 'serial:VIN-A', serial_number: 'VIN-A', lot_code: null, expires_on: null, available: 1, takenElsewhere: 0 }
    const one = toggleSerial([], choice)
    expect(one).toEqual([{ quantity: '1.000', serial_number: 'VIN-A', lot_code: null, expires_on: null }])
    expect(toggleSerial(one, choice)).toEqual([])
  })

  it('množství šarže: čárka i tečka, nejvýš tři desetinná místa, neplatné vrátí null', () => {
    const choice = { key: 'lot:L1|', serial_number: null, lot_code: 'L1', expires_on: null, available: 5, takenElsewhere: 0 }
    expect(setLotQuantity([], choice, '2,5')).toEqual([{ quantity: '2.5', serial_number: null, lot_code: 'L1', expires_on: null }])
    expect(setLotQuantity([{ quantity: '2', serial_number: null, lot_code: 'L1', expires_on: null }], choice, '0')).toEqual([])
    expect(setLotQuantity([], choice, '')).toEqual([])
    for (const bad of ['.5', '1.2345', '1e2', '5.', 'abc', '1.000,5']) expect(setLotQuantity([], choice, bad)).toBeNull()
  })

  it('stav výběru proti množství řádku v základní jednotce (dobropis záporně)', () => {
    const one = [sel({ serial_number: 'VIN-A' })]
    expect(selectionState([], 2)).toBe('none')
    expect(selectionState(one, 2)).toBe('incomplete')
    expect(selectionState(one, -1)).toBe('complete')
    expect(selectionState([...one, sel({ serial_number: 'VIN-B' })], 1)).toBe('over')
  })

  it('překročení dostupného množství', () => {
    const lot = { key: 'lot:L2|', serial_number: null, lot_code: 'L2', expires_on: null, available: 5, takenElsewhere: 0 }
    const picked = [sel({ lot_code: 'L2', quantity: '8' })]
    expect(exceedsAvailable(picked, lot)).toBe(true)
    expect(anyExceedsAvailable(picked, [lot])).toBe(true)
    expect(anyExceedsAvailable(picked, [{ ...lot, available: 8 }])).toBe(false)
  })

  it('vratka: dobropis nebo záporný řádek', () => {
    expect(isReturnRow('credit_note', 1)).toBe(true)
    expect(isReturnRow('invoice', -1)).toBe(true)
    expect(isReturnRow('invoice', 1)).toBe(false)
  })

  it('ruční zadání kusu u vratky bez původní faktury', () => {
    expect(freeTextAllocation('serial', ' VIN-Z ', '')).toEqual({ quantity: '1.000', serial_number: 'VIN-Z', lot_code: null, expires_on: null })
    expect(freeTextAllocation('lot', 'L9', '3,5')).toEqual({ quantity: '3.5', serial_number: null, lot_code: 'L9', expires_on: null })
    expect(freeTextAllocation('lot', 'L9', 'x')).toBeNull()
    expect(freeTextAllocation('serial', '  ', '')).toBeNull()
  })

  it('karta nalezená podle celého sériového čísla (VIN) si ho vybere, část čísla ne', () => {
    expect(preselectFromSearch('serial', { kind: 'serial', value: 'VIN-Z' }, 'vin-z')).toEqual([sel({ serial_number: 'VIN-Z' })])
    expect(preselectFromSearch('serial', { kind: 'serial', value: 'VIN-Z123' }, 'VIN-Z')).toEqual([])
    expect(preselectFromSearch('serial', { kind: 'attribute', value: 'VIN-Z' }, 'VIN-Z')).toEqual([])
    expect(preselectFromSearch('lot', { kind: 'lot', value: 'L1' }, 'L1')).toEqual([])
  })

  it('klíč kusu jako backend', () => {
    expect(trackingKey({ serial_number: 'A' })).toBe('serial:A')
    expect(trackingKey({ lot_code: 'L', expires_on: '2099-01-01' })).toBe('lot:L|2099-01-01')
  })
})
