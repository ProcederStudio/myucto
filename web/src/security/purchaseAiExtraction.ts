import type { SupplierBrief } from '@/api/auth'
import type { PermissionKey } from './permissions'

/** Firemní povolení se vztahuje na roli klienta, i na vlastní doméně. */
export function canUsePurchaseAiExtraction(
  auth: { isClientRole: boolean; canWrite: (permission: PermissionKey) => boolean },
  supplier: Pick<SupplierBrief, 'client_ai_extraction_available'> | null,
): boolean {
  return auth.canWrite('purchase_invoices.extract')
    && (!auth.isClientRole || (auth.canWrite('purchase_invoices') && auth.canWrite('purchase_invoices.create')
      && supplier?.client_ai_extraction_available === true))
}
