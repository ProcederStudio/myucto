import type { StockItemPriceLevelPayload } from '@/api/stock'
import { invalidSignedPct, normalizePct } from '@/pages/eshop/priceAdjustment'

/**
 * Výjimky skladové karty v cenových hladinách (pravidla match_type='product').
 *
 * Model per hladina: klíč je kód měny, prázdný klíč = pravidlo bez měny, tedy
 * „Sleva pro produkt" platná ve všech měnách hladiny. Každé pravidlo načtené
 * z DB musí přežít uložení beze změny; odeslat se smí jen to, co uživatel
 * skutečně změnil nebo odebral (žádné plošné „smaž vše a pošli znovu").
 */

export type LevelRuleType = 'discount_pct' | 'fixed'

export interface LevelRuleValue {
  rule_type: LevelRuleType
  /** Sleva v % nebo pevná cena, jak ji uživatel zapsal. */
  value: string
}

/** Výjimky karty v jedné hladině podle měny ('' = pro všechny měny). */
export type LevelRuleSet = Record<string, LevelRuleValue>

/** Klíč pravidla bez měny (sleva pro produkt ve všech měnách hladiny). */
export const ALL_CURRENCIES = ''

export interface ProductRuleLike {
  rule_type: LevelRuleType
  discount_pct: string | null
  fixed_price: string | null
  currency_code: string | null
}

export function normalizeRuleValue(value: string | number | null | undefined): string {
  return normalizePct(value)
}

/** Načtená pravidla → sada podle měny. Duplicitní klíč (nemá nastat) zachová první pravidlo. */
export function ruleSetFrom(rules: ProductRuleLike[]): LevelRuleSet {
  const set: LevelRuleSet = {}
  for (const rule of rules) {
    const key = (rule.currency_code ?? ALL_CURRENCIES).trim().toUpperCase()
    if (key in set) continue
    set[key] = {
      rule_type: rule.rule_type,
      value: String((rule.rule_type === 'fixed' ? rule.fixed_price : rule.discount_pct) ?? ''),
    }
  }
  return set
}

export function cloneRuleSet(set: LevelRuleSet): LevelRuleSet {
  const out: LevelRuleSet = {}
  for (const [key, rule] of Object.entries(set)) out[key] = { ...rule }
  return out
}

/** Stejné pravidlo? Stejný typ a stejná hodnota (10 = 10.000 = „10,0"). */
export function sameRuleValue(a: LevelRuleValue, b: LevelRuleValue): boolean {
  if (a.rule_type !== b.rule_type) return false
  const x = normalizeRuleValue(a.value)
  const y = normalizeRuleValue(b.value)
  if (x !== '' && y !== '' && Number.isFinite(Number(x)) && Number.isFinite(Number(y))) return Number(x) === Number(y)
  return x === y
}

/**
 * Rozdíl jedné hladiny: `remove` jen pro pravidla, která uživatel zrušil, upsert
 * jen pro nová nebo změněná. Nezměněná hladina → prázdné pole, nic se neposílá.
 * Odebrání vždy nese `currency_code` (i null), jinak by backend smazal všechna
 * pravidla karty v hladině.
 */
export function diffLevelRules(levelId: number, loaded: LevelRuleSet, edited: LevelRuleSet): StockItemPriceLevelPayload[] {
  const out: StockItemPriceLevelPayload[] = []
  for (const key of Object.keys(loaded).sort()) {
    if (!(key in edited)) out.push({ price_level_id: levelId, remove: true, currency_code: key === ALL_CURRENCIES ? null : key })
  }
  for (const key of Object.keys(edited).sort()) {
    const rule = edited[key]!
    const before = loaded[key]
    if (before && sameRuleValue(before, rule)) continue
    const value = normalizeRuleValue(rule.value)
    out.push(rule.rule_type === 'fixed'
      ? { price_level_id: levelId, rule_type: 'fixed', fixed_price: value, discount_pct: null, currency_code: key }
      : { price_level_id: levelId, rule_type: 'discount_pct', discount_pct: value, fixed_price: null, currency_code: key === ALL_CURRENCIES ? null : key })
  }
  return out
}

/** Rozdíl všech hladin karty; hladiny, na které uživatel nesáhl, se nepošlou vůbec. */
export function diffPriceLevelRules(
  loaded: Record<number, LevelRuleSet>,
  edited: Record<number, LevelRuleSet>,
): StockItemPriceLevelPayload[] {
  const ids = [...new Set([...Object.keys(loaded), ...Object.keys(edited)].map(Number))].sort((a, b) => a - b)
  return ids.flatMap(id => diffLevelRules(id, loaded[id] ?? {}, edited[id] ?? {}))
}

/**
 * Neplatná hodnota pravidla: prázdná, sleva nad 100 % nebo přirážka (záporná sleva)
 * nad 999,999 %, záporná pevná cena, pevná cena bez měny.
 */
export function invalidRule(key: string, rule: LevelRuleValue): boolean {
  if (rule.rule_type === 'discount_pct') return invalidSignedPct(rule.value)
  const raw = normalizeRuleValue(rule.value)
  const value = Number(raw)
  if (raw === '' || !Number.isFinite(value) || value < 0) return true
  return key === ALL_CURRENCIES
}
