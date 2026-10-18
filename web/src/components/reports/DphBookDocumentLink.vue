<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { DphBookRow } from '@/api/reports'
import { useAuthStore } from '@/stores/auth'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useToast } from '@/composables/useToast'
import { cashDocumentLink } from '@/utils/journalSourceLink'

const props = defineProps<{ row: DphBookRow }>()
const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const navigation = useWorkspaceNavigation()
const toast = useToast()

const target = computed<RouteLocationRaw | null>(() => {
  const row = props.row
  if (!Number.isSafeInteger(row.invoice_id) || row.invoice_id <= 0) return null
  // § 79 používá ID korekce, nikoli ID faktury.
  if (row.direction === 'correction') return null
  if (row.document_kind === 'cash') {
    return auth.canRead('cash') ? cashDocumentLink(row.invoice_id) : null
  }
  if (row.direction === 'issued') {
    return auth.canRead('invoices') ? { name: 'invoice-detail', params: { id: row.invoice_id } } : null
  }
  if (row.direction === 'received') {
    return auth.canRead('purchase_invoices') ? { name: 'purchase-invoice-detail', params: { id: row.invoice_id } } : null
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
  <a v-if="target" :href="router.resolve(target).href" :title="t('common.detail')"
    class="font-mono text-primary-600 hover:underline" data-workspace-open="beside" @click="open">
    {{ row.direction === 'issued' ? 'VF' : 'PF' }} {{ row.doc_number }}
  </a>
  <span v-else class="font-mono">{{ row.direction === 'issued' ? 'VF' : 'PF' }} {{ row.doc_number }}</span>
</template>
