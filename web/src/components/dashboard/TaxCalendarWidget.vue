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
import { formatDate, formatMoney } from '@/composables/useFormat'

const { t, locale } = useI18n()
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

function periodLabel(item: TaxCalendarItem): string | null {
  if (!item.period) return null
  const { year, month, quarter } = item.period
  if (month !== null) {
    const monthName = new Intl.DateTimeFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
      month: 'long',
      timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, 1)))
    return t('crm.tax_calendar.period_month', { month: monthName, year })
  }
  if (quarter !== null) return t('crm.tax_calendar.period_quarter', { quarter, year })
  return t('crm.tax_calendar.period_year', { year })
}

function deadlineLabel(item: TaxCalendarItem): string {
  if (item.type === 'tax_advance') return t('crm.tax_calendar.due_date')
  if (item.type === 'vat_registration_deadline') return t('crm.tax_calendar.deadline')
  return t('crm.tax_calendar.filing_deadline')
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
            <div class="flex flex-wrap items-baseline gap-x-1 text-sm font-medium text-neutral-700">
              <span>{{ item.title }}</span>
              <span v-if="periodLabel(item)" class="text-neutral-500">· {{ periodLabel(item) }}</span>
            </div>
            <div class="text-xs mt-0.5" :class="daysClass(item.days)">
              {{ deadlineLabel(item) }}: {{ formatDate(item.deadline) }}
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
