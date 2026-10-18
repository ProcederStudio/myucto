import type { BankTransaction } from '@/api/bank'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useToast } from '@/composables/useToast'

export function useBankJournalNavigation() {
  const auth = useAuthStore()
  const supplier = useSupplierStore()
  const router = useRouter()
  const navigation = useWorkspaceNavigation()
  const toast = useToast()
  const { t } = useI18n()

  function cashTarget(tx: BankTransaction): RouteLocationRaw | null {
    if (auth.isClientRole || !auth.canRead('tax_evidence')
      || supplier.currentSupplier?.accounting_mode !== 'tax_evidence') return null
    const year = tx.posted_at.match(/^(\d{4})-/)?.[1]
    if (!year || Number(year) < 1900 || Number(year) > 9999
      || !Number.isSafeInteger(tx.id) || tx.id <= 0) return null
    return { name: 'tax-evidence-cash-journal', query: { year, source_type: 'bank', source_id: String(tx.id) } }
  }

  function entryTarget(entryId: number | undefined): RouteLocationRaw | null {
    if (auth.isClientRole || !auth.hasCommercialFeatures || !auth.canRead('accounting')
      || supplier.currentSupplier?.accounting_mode !== 'double_entry'
      || entryId === undefined || !Number.isSafeInteger(entryId) || entryId <= 0) return null
    return { name: 'accounting-journal', query: { entry_id: String(entryId) } }
  }

  async function open(event: MouseEvent, to: RouteLocationRaw | null) {
    if (!to || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    try {
      await navigation.openBeside(to)
    } catch {
      toast.error(t('common.error'))
    }
  }

  return { cashTarget, entryTarget, open, href: (to: RouteLocationRaw) => router.resolve(to).href }
}
