import { describe, expect, it } from 'vitest'
import { ruleDuplicateKey, rulePayload, ruleRowFrom } from '@/pages/eshop/priceLevelRuleRows'
import type { PriceLevelRule } from '@/api/eshop'

function rule(over: Partial<PriceLevelRule>): PriceLevelRule {
  return {
    id: 1, match_type: 'category', match_id: 7, match_label: 'Díly', rule_type: 'discount_pct',
    discount_pct: '10.000', fixed_price: null, currency_code: null, priority: 0, ...over,
  } as PriceLevelRule
}

describe('pravidla cenové hladiny: řádek formuláře ↔ API', () => {
  it('přirážka se načte jako Přirážka v % s kladnou hodnotou a uloží se se znaménkem', () => {
    const row = ruleRowFrom(rule({ discount_pct: '-45.000' }), 1)
    expect([row.rule_type, row.discount_pct]).toEqual(['markup_pct', '45.000'])
    expect(rulePayload(row)).toMatchObject({ rule_type: 'discount_pct', discount_pct: '-45.000', fixed_price: null })
  })

  it('procentní pravidlo omezené na měnu si měnu ponechá', () => {
    const row = ruleRowFrom(rule({ discount_pct: '-45.000', currency_code: 'czk' }), 1)
    expect(rulePayload(row).currency_code).toBe('CZK')
    expect(rulePayload(ruleRowFrom(rule({}), 2)).currency_code).toBeNull()
  })

  it('dvě procentní pravidla téhož cíle v různých měnách nejsou duplicita', () => {
    const czk = ruleRowFrom(rule({ currency_code: 'CZK' }), 1)
    const eur = ruleRowFrom(rule({ currency_code: 'EUR' }), 2)
    const all = ruleRowFrom(rule({}), 3)
    expect(new Set([ruleDuplicateKey(czk), ruleDuplicateKey(eur), ruleDuplicateKey(all)]).size).toBe(3)
  })

  it('pevná cena produktu zůstane pevnou cenou v měně', () => {
    const row = ruleRowFrom(rule({ match_type: 'product', rule_type: 'fixed', discount_pct: null, fixed_price: '90.00', currency_code: 'EUR' }), 1)
    expect(row.rule_type).toBe('fixed')
    expect(rulePayload(row)).toMatchObject({ rule_type: 'fixed', fixed_price: '90.00', currency_code: 'EUR', discount_pct: null })
  })
})
