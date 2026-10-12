import type { ExpenseKind } from '@/api/purchaseInvoices'

/**
 * Samovyměření ze zahraničí rozlišuje zboží a službu: EU 23 (ř. 3) ↔ 24e (ř. 5),
 * 3. země 25 (ř. 7) ↔ 24 (ř. 12). Povahu plnění určuje druh nákladu stejně jako
 * backend (PurchaseInvoiceRepository::supplyIsGoods) — dlouhodobý majetek povahu
 * neurčuje, kód zůstane. Jiné kódy (tuzemsko, § 92a) se nemění.
 */
const GOODS_TO_SERVICE: Record<string, string> = { '23': '24e', '25': '24' }
const SERVICE_TO_GOODS: Record<string, string> = { '24e': '23', '24': '25' }

export function supplyIsGoods(kind: ExpenseKind | null | undefined): boolean | null {
  if (kind === 'material' || kind === 'small_asset') return true
  if (kind === 'service' || kind === 'small_intangible') return false
  return null
}

export function reverseChargeCodeForKind(code: string | null | undefined, kind: ExpenseKind | null | undefined): string | null {
  const current = code ?? null
  if (current === null) return null
  const goods = supplyIsGoods(kind)
  if (goods === true) return SERVICE_TO_GOODS[current] ?? current
  if (goods === false) return GOODS_TO_SERVICE[current] ?? current
  return current
}
