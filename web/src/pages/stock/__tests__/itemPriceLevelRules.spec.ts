import { describe, expect, it } from 'vitest'
import {
  ALL_CURRENCIES,
  cloneRuleSet,
  diffLevelRules,
  diffPriceLevelRules,
  invalidRule,
  ruleSetFrom,
  sameRuleValue,
  type LevelRuleSet,
} from '@/pages/stock/itemPriceLevelRules'

// Sleva pro produkt (bez měny) + pevné ceny ve všech měnách karty — dřív se sleva ztrácela.
const loadedRules = [
  { rule_type: 'discount_pct' as const, discount_pct: '10.000', fixed_price: null, currency_code: null },
  { rule_type: 'fixed' as const, discount_pct: null, fixed_price: '90.00', currency_code: 'CZK' },
  { rule_type: 'fixed' as const, discount_pct: null, fixed_price: '3.50', currency_code: 'EUR' },
]

describe('item price level rules: round-trip', () => {
  it('načte slevu pro produkt i pevné ceny všech měn', () => {
    expect(ruleSetFrom(loadedRules)).toEqual({
      [ALL_CURRENCIES]: { rule_type: 'discount_pct', value: '10.000' },
      CZK: { rule_type: 'fixed', value: '90.00' },
      EUR: { rule_type: 'fixed', value: '3.50' },
    })
  })

  it('uložení bez úprav nepošle nic', () => {
    const loaded = ruleSetFrom(loadedRules)
    expect(diffLevelRules(1, loaded, cloneRuleSet(loaded))).toEqual([])
    expect(diffPriceLevelRules({ 1: loaded, 2: {} }, { 1: cloneRuleSet(loaded), 2: {} })).toEqual([])
  })

  it('stejná hodnota v jiném zápisu není změna', () => {
    expect(sameRuleValue({ rule_type: 'discount_pct', value: '10.000' }, { rule_type: 'discount_pct', value: '10' })).toBe(true)
    expect(sameRuleValue({ rule_type: 'fixed', value: '3.50' }, { rule_type: 'fixed', value: '3,5' })).toBe(true)
    expect(sameRuleValue({ rule_type: 'fixed', value: '3.50' }, { rule_type: 'discount_pct', value: '3.50' })).toBe(false)
  })

  it('vrácení jedné měny na zděděnou odebere jen její pevnou cenu, sleva zůstane', () => {
    const loaded = ruleSetFrom(loadedRules)
    const edited = cloneRuleSet(loaded)
    delete edited.EUR
    expect(diffLevelRules(1, loaded, edited)).toEqual([{ price_level_id: 1, remove: true, currency_code: 'EUR' }])
  })

  it('zrušení slevy odebere jen pravidlo bez měny, pevné ceny zůstanou', () => {
    const loaded = ruleSetFrom(loadedRules)
    const edited = cloneRuleSet(loaded)
    delete edited[ALL_CURRENCIES]
    expect(diffLevelRules(1, loaded, edited)).toEqual([{ price_level_id: 1, remove: true, currency_code: null }])
  })

  it('odebrání nikdy není plošné: vždy nese měnu (i null)', () => {
    const loaded = ruleSetFrom(loadedRules)
    for (const entry of diffLevelRules(1, loaded, {})) {
      expect('remove' in entry && 'currency_code' in entry).toBe(true)
    }
    expect(diffLevelRules(1, loaded, {})).toHaveLength(3)
  })

  it('změna nebo nové pravidlo pošle jen upsert té měny', () => {
    const loaded = ruleSetFrom(loadedRules)
    const edited = cloneRuleSet(loaded)
    edited.CZK = { rule_type: 'fixed', value: '85' }
    edited.USD = { rule_type: 'fixed', value: '4,20' }
    edited[ALL_CURRENCIES] = { rule_type: 'discount_pct', value: '12,5' }
    expect(diffLevelRules(7, loaded, edited)).toEqual([
      { price_level_id: 7, rule_type: 'discount_pct', discount_pct: '12.5', fixed_price: null, currency_code: null },
      { price_level_id: 7, rule_type: 'fixed', fixed_price: '85', discount_pct: null, currency_code: 'CZK' },
      { price_level_id: 7, rule_type: 'fixed', fixed_price: '4.20', discount_pct: null, currency_code: 'USD' },
    ])
  })

  it('pravidlo měny se slevou (z editoru hladiny) přežije a posílá se s měnou', () => {
    const loaded = ruleSetFrom([{ rule_type: 'discount_pct', discount_pct: '5.000', fixed_price: null, currency_code: 'EUR' }])
    expect(loaded).toEqual({ EUR: { rule_type: 'discount_pct', value: '5.000' } })
    const edited: LevelRuleSet = { EUR: { rule_type: 'discount_pct', value: '6' } }
    expect(diffLevelRules(3, loaded, edited)).toEqual([
      { price_level_id: 3, rule_type: 'discount_pct', discount_pct: '6', fixed_price: null, currency_code: 'EUR' },
    ])
  })

  it('validace: prázdná, záporná, sleva nad 100 % a pevná cena bez měny', () => {
    expect(invalidRule('CZK', { rule_type: 'fixed', value: '' })).toBe(true)
    expect(invalidRule('CZK', { rule_type: 'fixed', value: '-1' })).toBe(true)
    expect(invalidRule('CZK', { rule_type: 'fixed', value: '0' })).toBe(false)
    expect(invalidRule(ALL_CURRENCIES, { rule_type: 'fixed', value: '10' })).toBe(true)
    expect(invalidRule(ALL_CURRENCIES, { rule_type: 'discount_pct', value: '100.5' })).toBe(true)
    expect(invalidRule(ALL_CURRENCIES, { rule_type: 'discount_pct', value: '100' })).toBe(false)
  })

  it('přirážka je záporná sleva do −999,999 %', () => {
    expect(invalidRule(ALL_CURRENCIES, { rule_type: 'discount_pct', value: '-45' })).toBe(false)
    expect(invalidRule('EUR', { rule_type: 'discount_pct', value: '-999.999' })).toBe(false)
    expect(invalidRule(ALL_CURRENCIES, { rule_type: 'discount_pct', value: '-1000' })).toBe(true)
  })

  it('typografické minus je totéž co pomlčka (výjimka se nepovažuje za změněnou)', () => {
    expect(sameRuleValue({ rule_type: 'discount_pct', value: '\u221245' }, { rule_type: 'discount_pct', value: '-45.000' })).toBe(true)
  })
})
