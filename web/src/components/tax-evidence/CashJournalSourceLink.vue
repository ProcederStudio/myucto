<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { CashJournalRow } from '@/api/taxEvidence'
import { useAuthStore } from '@/stores/auth'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useToast } from '@/composables/useToast'
import { ICONS, btnIconSm } from '@/components/ui/buttonStyles'

const props = defineProps<{ row: CashJournalRow }>()
const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const navigation = useWorkspaceNavigation()
const toast = useToast()

const target = computed<RouteLocationRaw | null>(() => {
  const row = props.row
  if (row.source_type === 'bank' && row.bank_statement_id) {
    return auth.canRead('bank')
      ? { name: 'bank-detail', params: { id: row.bank_statement_id }, query: { tx: String(row.source_id), detail: '1' } }
      : null
  }
  if (row.source_type === 'cash') {
    return auth.canRead('cash')
      ? { name: 'accounting-cash', query: { document_id: String(row.source_id) } }
      : null
  }
  if (row.invoice_id) {
    return auth.canRead('invoices') ? { name: 'invoice-detail', params: { id: row.invoice_id } } : null
  }
  if (row.purchase_invoice_id) {
    return auth.canRead('purchase_invoices') ? { name: 'purchase-invoice-detail', params: { id: row.purchase_invoice_id } } : null
  }
  if (row.gopay_clearing_id) {
    return auth.canRead('bank') ? { name: 'gopay', query: { clearing: String(row.gopay_clearing_id) } } : null
  }
  return null
})

async function open(event: MouseEvent) {
  if (!target.value || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  try {
    await navigation.openBeside(target.value)
  } catch {
    toast.error(t('common.error'))
  }
}
</script>

<template>
  <a v-if="target" :href="router.resolve(target).href" :class="btnIconSm('primary')"
     :title="t('tax_evidence.cash_journal.open_source')"
     :aria-label="t('tax_evidence.cash_journal.open_source')"
     data-workspace-open="beside" data-test="cash-journal-source" @click="open">
    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" />
    </svg>
  </a>
</template>
