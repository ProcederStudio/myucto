import { describe, expect, it } from 'vitest'
import { canUsePurchaseAiExtraction } from '../purchaseAiExtraction'
import type { PermissionKey } from '../permissions'

const auth = (client = true, denied?: PermissionKey) => ({
  isClientRole: client,
  canWrite: (key: PermissionKey) => key !== denied,
})
describe('dostupnost AI importu v navigaci', () => {
  it('klient potřebuje dostupnou firemní AI a všechna práva pro vytvoření i kontrolu', () => {
    expect(canUsePurchaseAiExtraction(auth(), null)).toBe(false)
    expect(canUsePurchaseAiExtraction(auth(), { client_ai_extraction_available: false })).toBe(false)
    expect(canUsePurchaseAiExtraction(auth(), { client_ai_extraction_available: true })).toBe(true)
    for (const key of ['purchase_invoices', 'purchase_invoices.create', 'purchase_invoices.extract'] as const) {
      expect(canUsePurchaseAiExtraction(auth(true, key), { client_ai_extraction_available: true })).toBe(false)
    }
  })
  it('účetní na klientské doméně nepotřebuje firemní povolení pro klienty', () => {
    expect(canUsePurchaseAiExtraction(auth(false), null)).toBe(true)
    expect(canUsePurchaseAiExtraction(auth(false, 'purchase_invoices.extract'), null)).toBe(false)
  })
})
