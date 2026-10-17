<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { FxDetailRow } from '@/api/closing'
import { formatMoney } from '@/composables/useFormat'
defineProps<{ rows: FxDetailRow[] }>()
const { t } = useI18n()
function increment(row: FxDetailRow): number { return Number(row.incremental_signed ?? (row.doc_type === 'invoice' ? row.diff : -row.diff)) }
</script>
<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
        <tr>
          <th class="px-3 py-2 text-left font-medium">{{ t('accounting.closing.fx.col_document') }}</th>
          <th class="px-3 py-2 text-left font-medium w-20">{{ t('accounting.closing.fx.col_currency') }}</th>
          <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.closing.fx.col_remaining') }}</th>
          <th class="px-3 py-2 text-right font-medium w-28">{{ t('accounting.closing.fx.col_doc_rate') }}</th>
          <th class="px-3 py-2 text-right font-medium w-28">{{ t('accounting.closing.fx.col_cnb_rate') }}</th>
          <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.closing.fx.col_diff') }}</th>
          <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.closing.fx.col_carrying') }}</th>
          <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.closing.fx.col_increment') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-neutral-100">
        <tr v-for="(d, i) in rows" :key="i">
          <td class="px-3 py-1.5">
            {{ t(`accounting.closing.fx.doc_type.${d.doc_type}`) }} {{ d.varsymbol || `#${d.doc_id}` }}
          </td>
          <td class="px-3 py-1.5">{{ d.currency_code }}</td>
          <td class="px-3 py-1.5 text-right font-mono">{{ formatMoney(d.remaining_foreign, d.currency_code) }}</td>
          <td class="px-3 py-1.5 text-right font-mono">{{ d.fx_rate }}</td>
          <td class="px-3 py-1.5 text-right font-mono">{{ d.rate_cnb }}</td>
          <td class="px-3 py-1.5 text-right font-mono" data-fx-difference>
            {{ formatMoney(d.diff) }}
          </td>
          <td class="px-3 py-1.5 text-right font-mono" data-fx-carrying>{{ formatMoney(Number(d.carrying_before ?? 0)) }}</td>
          <td class="px-3 py-1.5 text-right font-mono" data-fx-increment :class="increment(d) > 0 ? 'text-success-600' : increment(d) < 0 ? 'text-danger-500' : ''">{{ formatMoney(increment(d)) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
