<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { FxSettlementWarning } from '@/api/closing'

defineProps<{ warnings: FxSettlementWarning[] }>()
const { t } = useI18n()
</script>

<template>
  <ul v-if="warnings.length" role="status" class="space-y-2 rounded-md border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800">
    <li v-for="warning in warnings" :key="`${warning.doc_type}:${warning.doc_id}:${warning.code}`">
      <RouterLink :to="{ name: warning.doc_type === 'invoice' ? 'invoice-detail' : 'purchase-invoice-detail', params: { id: warning.doc_id } }" class="font-medium underline">
        {{ t(`accounting.closing.fx.doc_type.${warning.doc_type}`) }} #{{ warning.doc_id }}
      </RouterLink>
      {{ t(`checks.issue.${warning.code}`) }}
    </li>
  </ul>
</template>
