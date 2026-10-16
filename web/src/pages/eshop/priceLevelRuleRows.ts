import type { PriceLevelMatchType, PriceLevelRule, PriceLevelRulePayload } from '@/api/eshop'
import { joinAdjustment, normalizePct, splitAdjustment } from '@/pages/eshop/priceAdjustment'

/** Typ pravidla ve formuláři: přirážka je v API `discount_pct` se záporným znaménkem. */
export type RuleKind = 'discount_pct' | 'markup_pct' | 'fixed'

export interface RuleRow {
  key: number
  match_type: PriceLevelMatchType
  match_id: number | null
  match_label: string | null
  rule_type: RuleKind
  /** Kladná hodnota slevy nebo přirážky v %. */
  discount_pct: string
  fixed_price: string
  /** Měna pevné ceny (výběr ve formuláři). */
  currency_code: string
  /**
   * Měna, na kterou je omezené procentní pravidlo (API to dovoluje, formulář ji jen
   * zobrazí); null = všechny měny. Bez ní by uložení sady pravidlo rozšířilo na všechny měny.
   */
  pct_currency: string | null
  priority: number
}

export function isFixedRow(row: RuleRow): boolean {
  return row.match_type === 'product' && row.rule_type === 'fixed'
}

export function ruleRowFrom(rule: PriceLevelRule, key: number): RuleRow {
  const fixed = rule.match_type === 'product' && rule.rule_type === 'fixed'
  const { kind, value } = splitAdjustment(rule.discount_pct)
  const currency = rule.currency_code ? rule.currency_code.toUpperCase() : null
  return {
    key,
    match_type: rule.match_type,
    match_id: rule.match_id,
    match_label: rule.match_label,
    rule_type: fixed ? 'fixed' : (kind === 'markup' ? 'markup_pct' : 'discount_pct'),
    discount_pct: fixed ? '' : value,
    fixed_price: rule.fixed_price ?? '',
    currency_code: currency ?? 'CZK',
    pct_currency: fixed ? null : currency,
    priority: rule.priority,
  }
}

export function rulePayload(row: RuleRow): PriceLevelRulePayload {
  const fixed = isFixedRow(row)
  return {
    match_type: row.match_type,
    match_id: row.match_id as number,
    rule_type: fixed ? 'fixed' : 'discount_pct',
    discount_pct: fixed ? null : joinAdjustment(row.rule_type === 'markup_pct' ? 'markup' : 'discount', row.discount_pct),
    fixed_price: fixed ? normalizePct(row.fixed_price) : null,
    currency_code: fixed ? row.currency_code.toUpperCase() : row.pct_currency,
    priority: Number(row.priority) || 0,
  }
}

/** Klíč pro kontrolu duplicity: cíl + měna (stejně jako unikátní index pravidel v DB). */
export function ruleDuplicateKey(row: RuleRow): string {
  const currency = isFixedRow(row) ? row.currency_code.toUpperCase() : (row.pct_currency ?? '')
  return `${row.match_type}|${row.match_id}|${currency}`
}
