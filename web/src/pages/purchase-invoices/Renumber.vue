<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { purchaseInvoicesApi, type PurchaseRenumberPlan } from '@/api/purchaseInvoices'
import { apiErrorMessage } from '@/api/errors'
import { formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, disabledTitle } from '@/components/ui/buttonStyles'

/**
 * Přečíslování přijatých dokladů jednoho měsíce podle data vystavení. Náhled se
 * načte po výběru měsíce, samotné přečíslování až po potvrzení. Pravidla (co se
 * přečísluje, co zůstane, kdy je měsíc zamčený) drží backend
 * (PurchaseInvoiceRenumberService), stránka je jen zobrazuje.
 */
const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const now = new Date()
const period = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`)
const plan = ref<PurchaseRenumberPlan | null>(null)
const loading = ref(false)
const running = ref(false)
const keptOpen = ref(false)

const moves = computed(() => (plan.value?.changes ?? []).filter(c => c.changed))
const canRun = computed(() => !!plan.value && moves.value.length > 0 && plan.value.blocked.length === 0 && !auth.isClientRole)

let seq = 0
async function load(): Promise<void> {
  if (!/^\d{4}-\d{2}$/.test(period.value)) return
  const mine = ++seq
  loading.value = true
  try {
    const result = await purchaseInvoicesApi.renumberPreview(period.value)
    if (mine === seq) plan.value = result
  } catch (e) {
    if (mine === seq) {
      plan.value = null
      toast.error(apiErrorMessage(e))
    }
  } finally {
    if (mine === seq) loading.value = false
  }
}

async function run(): Promise<void> {
  if (!canRun.value || running.value) return
  if (!confirm(t('purchase_invoice.renumber.confirm', { count: moves.value.length, period: period.value }))) return
  running.value = true
  try {
    const result = await purchaseInvoicesApi.renumber(period.value)
    toast.success(t('purchase_invoice.renumber.done', { count: result.renumbered }))
    plan.value = await purchaseInvoicesApi.renumberPreview(period.value)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    running.value = false
  }
}

watch(period, () => { void load() }, { immediate: true })
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.renumber.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5 max-w-3xl">{{ t('purchase_invoice.renumber.intro') }}</p>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-4 space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1" for="renumber-period">{{ t('purchase_invoice.renumber.period') }}</label>
          <input id="renumber-period" v-model="period" type="month" data-test="renumber-period"
            class="h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <button type="button" :class="btnFilled('warning')" class="whitespace-nowrap" data-test="renumber-run"
          :disabled="!canRun || running" :title="disabledTitle(auth.isClientRole, t('purchase_invoice.extraction_review.readonly_client'))"
          @click="run">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('purchase_invoice.renumber.run', { count: moves.length }) }}
        </button>
      </div>
      <p class="text-xs text-neutral-500">{{ t('purchase_invoice.renumber.tax_note') }}</p>
    </div>

    <div v-if="loading && !plan" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <template v-else-if="plan">
      <div v-if="plan.blocked.length" class="mb-4 p-3 rounded-md border border-danger-500/40 bg-danger-50 text-sm text-danger-700" data-test="renumber-blocked">
        <p class="font-medium">{{ t('purchase_invoice.renumber.blocked_title') }}</p>
        <p class="mt-1">{{ t('purchase_invoice.renumber.blocked_hint') }}</p>
        <ul class="mt-1 space-y-0.5">
          <li v-for="b in plan.blocked" :key="b.id">
            <RouterLink :to="`/purchase-invoices/${b.id}`" class="font-mono hover:underline">{{ b.varsymbol }}</RouterLink>
            · {{ formatDate(b.issue_date) }} · {{ t(`purchase_invoice.renumber.reason.${b.reason}`) }}
          </li>
        </ul>
      </div>

      <p v-if="!plan.changes.length" class="text-sm text-neutral-500 mb-4">{{ t('purchase_invoice.renumber.nothing') }}</p>
      <p v-else-if="!moves.length" class="text-sm text-success-600 mb-4">{{ t('purchase_invoice.renumber.in_order') }}</p>
      <p v-else class="text-sm text-neutral-700 mb-2">{{ t('purchase_invoice.renumber.changes_count', { count: moves.length }) }}</p>

      <div v-if="plan.changes.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <table class="hidden md:table w-full text-sm" data-test="renumber-table">
          <thead class="bg-neutral-50 text-neutral-500 text-xs">
            <tr>
              <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_date') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_vendor') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_vendor_number') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_kind') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_old') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_new') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in plan.changes" :key="c.id" class="border-t border-neutral-100" :class="c.changed ? '' : 'text-neutral-400'">
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(c.issue_date) }}</td>
              <td class="px-3 py-2">{{ c.vendor_name }}</td>
              <td class="px-3 py-2 font-mono">{{ c.vendor_invoice_number }}</td>
              <td class="px-3 py-2">{{ t(`purchase_invoice.document_kind.${c.document_kind}`) }}</td>
              <td class="px-3 py-2 font-mono">
                <RouterLink :to="`/purchase-invoices/${c.id}`" class="hover:underline">{{ c.varsymbol }}</RouterLink>
              </td>
              <td class="px-3 py-2 font-mono" :class="c.changed ? 'font-semibold text-warning-700' : ''">
                {{ c.changed ? c.new_varsymbol : t('purchase_invoice.renumber.unchanged') }}
              </td>
            </tr>
          </tbody>
        </table>
        <ul class="md:hidden divide-y divide-neutral-100">
          <li v-for="c in plan.changes" :key="c.id" class="p-3 text-sm" :class="c.changed ? '' : 'text-neutral-400'">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <span class="font-medium">{{ c.vendor_name }}</span>
              <span class="text-xs">{{ formatDate(c.issue_date) }}</span>
            </div>
            <div class="text-xs text-neutral-500">{{ t(`purchase_invoice.document_kind.${c.document_kind}`) }} · {{ c.vendor_invoice_number }}</div>
            <div class="mt-1 font-mono text-xs">
              {{ c.varsymbol }} → <span :class="c.changed ? 'font-semibold text-warning-700' : ''">{{ c.changed ? c.new_varsymbol : t('purchase_invoice.renumber.unchanged') }}</span>
            </div>
          </li>
        </ul>
      </div>

      <div v-if="plan.kept.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden" data-test="renumber-kept">
        <button type="button" class="w-full flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-left hover:bg-neutral-50"
          :aria-expanded="keptOpen" @click="keptOpen = !keptOpen">
          <span>
            <span class="block text-sm font-medium text-neutral-700">{{ t('purchase_invoice.renumber.kept_title', { count: plan.kept.length }) }}</span>
            <span class="block text-xs text-neutral-500">{{ t('purchase_invoice.renumber.kept_hint') }}</span>
          </span>
          <svg class="w-4 h-4 text-neutral-400 transition-transform shrink-0" :class="keptOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
        </button>
        <template v-if="keptOpen">
          <table class="hidden md:table w-full text-sm border-t border-neutral-200">
            <thead class="bg-neutral-50 text-neutral-500 text-xs">
              <tr>
                <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_date') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_vendor') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_vendor_number') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_kind') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('purchase_invoice.renumber.col_old') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="k in plan.kept" :key="k.id" class="border-t border-neutral-100 text-neutral-600">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(k.issue_date) }}</td>
                <td class="px-3 py-2">{{ k.vendor_name }}</td>
                <td class="px-3 py-2 font-mono">{{ k.vendor_invoice_number }}</td>
                <td class="px-3 py-2">{{ t(`purchase_invoice.document_kind.${k.document_kind}`) }}</td>
                <td class="px-3 py-2 font-mono">
                  <RouterLink :to="`/purchase-invoices/${k.id}`" class="hover:underline">{{ k.varsymbol }}</RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
          <ul class="md:hidden divide-y divide-neutral-100 border-t border-neutral-200">
            <li v-for="k in plan.kept" :key="k.id" class="p-3 text-sm text-neutral-600">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="font-medium">{{ k.vendor_name }}</span>
                <span class="text-xs">{{ formatDate(k.issue_date) }}</span>
              </div>
              <RouterLink :to="`/purchase-invoices/${k.id}`" class="font-mono text-xs hover:underline">{{ k.varsymbol }}</RouterLink>
            </li>
          </ul>
        </template>
      </div>
    </template>
  </div>
</template>
