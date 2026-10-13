<script setup lang="ts">
/**
 * Dashboard widget "Daňový kalendář" (Fáze F, audit 2026-07 P2/S). Kombinuje
 * DPH/KH/SH termíny, zálohy (E9) a roční termín DPFO/DPPO se stavem "podáno"
 * (tax_submissions). Self-gating: nic nevykreslí bez položek.
 */
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { crmApi, type TaxCalendarItem } from '@/api/crm'
import { formatMoney } from '@/composables/useFormat'

const { t } = useI18n()
const items = ref<TaxCalendarItem[]>([])

onMounted(async () => {
  try {
    const res = await crmApi.taxCalendar(60)
    items.value = res.items.slice(0, 8)
  } catch { /* best-effort widget */ }
})

function daysClass(days: number): string {
  if (days < 0) return 'text-danger-600 font-semibold'
  if (days <= 2) return 'text-danger-500'
  if (days <= 7) return 'text-warning-600'
  return 'text-neutral-500'
}

function statusLabel(item: TaxCalendarItem): string {
  if (item.type === 'tax_advance') {
    return item.status === 'paid' ? t('crm.tax_calendar.paid') : t('crm.tax_calendar.due')
  }
  return item.submitted ? t('crm.tax_calendar.submitted') : t('crm.tax_calendar.not_submitted')
}

function statusClass(item: TaxCalendarItem): string {
  if (item.type === 'tax_advance') {
    return item.status === 'paid' ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'
  }
  return item.submitted ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'
}
</script>

<template>
  <div v-if="items.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
    <!-- `to-white` byla natvrdo zapsaná barva: v dark módu z ní byl doslova bílý
         pruh přes celou hlavičku. Gradient musí končit v tokenu plochy. -->
    <header class="px-5 py-3 border-b border-neutral-200 bg-gradient-to-r from-primary-50 to-surface rounded-t-lg">
      <h3 class="flex items-center gap-2 text-[13px] font-semibold uppercase tracking-[0.12em] text-primary-700"><span aria-hidden="true">📅</span>{{ t('crm.tax_calendar.title') }}</h3>
    </header>
    <ul class="divide-y divide-neutral-100">
      <li v-for="(item, idx) in items" :key="idx" class="px-5 py-3">
        <RouterLink :to="item.link" class="flex items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="text-sm font-medium text-neutral-700">{{ item.title }}</div>
            <div class="text-xs mt-0.5" :class="daysClass(item.days)">
              {{ item.deadline }}
              <span v-if="item.amount !== undefined" class="ml-1 text-neutral-500">· {{ formatMoney(item.amount, 'CZK') }}</span>
            </div>
            <div v-if="item.payment && item.payment.payable > 0" class="text-xs mt-0.5 text-neutral-600">
              {{ t('crm.tax_calendar.pay_to', { amount: formatMoney(item.payment.payable, 'CZK'), account: item.payment.account ?? '—' }) }}
              <span v-if="item.payment.variable_symbol" class="text-neutral-500">· {{ t('crm.tax_calendar.vs', { vs: item.payment.variable_symbol }) }}</span>
            </div>
            <div v-else-if="item.payment && item.payment.refund > 0" class="text-xs mt-0.5 text-neutral-600">
              {{ t('crm.tax_calendar.refund', { amount: formatMoney(item.payment.refund, 'CZK') }) }}
            </div>
          </div>
          <div class="shrink-0 flex flex-col items-end gap-1">
            <span class="px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap" :class="statusClass(item)">
              {{ statusLabel(item) }}
            </span>
            <span
              v-if="item.payment && item.payment.payable > 0"
              class="px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap"
              :class="item.payment.paid ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-700'"
            >{{ item.payment.paid ? t('crm.tax_calendar.paid') : t('crm.tax_calendar.to_pay') }}</span>
          </div>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>
