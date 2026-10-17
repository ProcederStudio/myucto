/**
 * Textový řádek mezi položkami vydaného dokladu (`item_kind: 'text'`, migrace 2034).
 *
 * Nese jen popis. Server ho ukládá vynulovaný (množství, ceny, sklad, OSS, klasifikace
 * DPH, účet) a sazbu bere z první položky dokladu; do součtů, rekapitulace DPH ani
 * zaúčtování nevstupuje. Editor ho proto do živého náhledu součtů nepočítá a posílá
 * jen jeho text.
 */
export type InvoiceItemKind = 'standard' | 'discount' | 'text'

export function isTextItem(item: { item_kind?: string | null } | null | undefined): boolean {
  return item?.item_kind === 'text'
}

/** Řádky s částkou (vše kromě textových), v původním pořadí. */
export function amountItems<T extends { item_kind?: string | null }>(items: readonly T[]): T[] {
  return items.filter(item => !isTextItem(item))
}

/** Nový prázdný textový řádek editoru. */
export function blankTextItem(orderIndex: number, vatRateId: number) {
  return {
    item_kind: 'text' as const,
    description: '',
    quantity: 0,
    duration_minutes: null,
    unit: '',
    unit_price_without_vat: 0,
    vat_rate_id: vatRateId,
    order_index: orderIndex,
    stock_item_id: null,
    warehouse_id: null,
    small_asset_id: null,
    asset_id: null,
    revenue_account_code: null,
    oss_applicable: false,
    oss_consumer_country: null,
    oss_rate_type: null,
    oss_supply_type: null,
  }
}

/** Payload textového řádku pro uložení dokladu: jen druh, text a pořadí. */
export function textItemPayload(item: { description?: string | null }, orderIndex: number) {
  return {
    item_kind: 'text' as const,
    description: item.description ?? '',
    order_index: orderIndex,
  }
}
