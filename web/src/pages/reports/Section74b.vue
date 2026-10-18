<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { reportsApi, type S74bPreview } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { useVatReportPeriod } from '@/composables/useVatReportPeriod'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()

const { year, month, ready, settingsError, initialize } = useVatReportPeriod('monthly')

const preview = ref<S74bPreview | null>(null)
const loading = ref(true)
const recording = ref(false)
const error = ref('')
const recordedMsg = ref('')

const canRecord = computed(() => auth.canWrite('reports.finalize'))
let previewSeq = 0
onBeforeUnmount(() => { ++previewSeq })

async function loadPreview() {
  if (!ready.value) {
    loading.value = true
    error.value = ''
    await initialize()
    return
  }
  const seq = ++previewSeq
  const requestedYear = year.value
  const requestedMonth = month.value
  const current = () => seq === previewSeq && ready.value && requestedYear === year.value && requestedMonth === month.value
  loading.value = true
  preview.value = null
  error.value = ''
  recordedMsg.value = ''
  try {
    const result = await reportsApi.s74bPreview(requestedYear, requestedMonth)
    if (current()) preview.value = result
  } catch (e) {
    if (current()) error.value = apiErrorMessage(e)
  } finally {
    if (seq === previewSeq) loading.value = false
  }
}

async function record() {
  if (!ready.value || loading.value || !canRecord.value) return
  if (!confirm(t('reports.s74b.record_confirm'))) return
  recording.value = true
  error.value = ''
  recordedMsg.value = ''
  try {
    const res = await reportsApi.s74bRecord(year.value, month.value)
    preview.value = { period: res.period, rows: res.rows, totals: res.totals }
    recordedMsg.value = t('reports.s74b.record_done', { count: res.recorded })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    recording.value = false
  }
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)
const yearOptions = useYearOptions('combined', year)

function fmtMoney(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (isNaN(d.getTime())) return ''
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function fmtPct(ratio: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
  }).format((Number(ratio) || 0) * 100) + ' %'
}

// „Dosud korigováno" = kumulativní cíl mínus pohyb tohoto období (co bylo zaevidováno dřív).
function alreadyCorrected(row: { target_reduction: number; delta: number }): number {
  return (Number(row.target_reduction) || 0) - (Number(row.delta) || 0)
}

const hasRows = computed(() => (preview.value?.rows.length ?? 0) > 0)

const actions = computed<ActionItem[]>(() => [
  {
    key: 'preview', label: t('reports.s74b.action_preview'), icon: 'chart',
    tier: 'primary', variant: 'primary',
    show: auth.canRead('reports'), disabled: loading.value || recording.value,
    loading: loading.value, run: loadPreview,
  },
  {
    key: 'record', label: t('reports.s74b.action_record'), icon: 'clipboardCheck',
    tier: 'secondary', variant: 'success',
    show: canRecord.value, disabled: loading.value || recording.value || !hasRows.value,
    loading: recording.value, title: t('reports.s74b.record_hint'), run: record,
  },
])

watch([ready, year, month], () => {
  if (ready.value) void loadPreview()
  else { ++previewSeq; preview.value = null; loading.value = !settingsError.value }
})
watch(settingsError, value => {
  if (value) { error.value = apiErrorMessage(value); loading.value = false }
})
onMounted(() => { void initialize() })
</script>

<template>
  <div class="max-w-full">
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.s74b.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.s74b.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-model.number="month" :disabled="!ready || recording" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select v-model.number="year" :disabled="!ready || recording" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <!-- Vysvětlivka dry-run vs. zaevidování -->
    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('reports.s74b.explainer_title') }}</p>
      <p>{{ t('reports.s74b.explainer_body') }}</p>
    </div>

    <div v-if="recordedMsg" class="bg-success-50 border border-success-200 text-success-700 rounded-md p-3 text-sm mb-4">
      {{ recordedMsg }}
    </div>
    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="preview" class="space-y-4">
      <!-- Období -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 flex items-center justify-between flex-wrap gap-2">
        <div>
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('reports.s74b.period_label') }}
          </div>
          <div class="text-lg font-semibold font-mono">
            {{ preview.period.month.toString().padStart(2, '0') }}/{{ preview.period.year }}
          </div>
        </div>
        <div class="text-xs text-neutral-500">
          {{ t('reports.s74b.period_end_label') }}: <span class="font-mono">{{ fmtDate(preview.period.period_end) }}</span>
        </div>
      </div>

      <!-- Prázdný stav -->
      <EmptyState v-if="!hasRows" boxed accent="neutral" icon="coin" :title="t('reports.s74b.no_data')" />

      <template v-else>
        <!-- Tabulka řádků -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-xs">
              <thead class="bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-2 py-2 text-left font-medium">{{ t('reports.s74b.col.vendor') }}</th>
                  <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.s74b.col.doc_number') }}</th>
                  <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.s74b.col.tax_date') }}</th>
                  <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.s74b.col.due_date') }}</th>
                  <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s74b.col.total_with_vat') }}</th>
                  <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s74b.col.claimed_deduction_vat') }}</th>
                  <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s74b.col.unpaid_ratio') }}</th>
                  <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s74b.col.target_reduction') }}</th>
                  <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s74b.col.already_corrected') }}</th>
                  <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.s74b.col.delta') }}</th>
                  <th class="px-2 py-2 text-center font-medium whitespace-nowrap">{{ t('reports.s74b.col.movement') }}</th>
                  <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.s74b.col.state') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in preview.rows" :key="row.purchase_invoice_id"
                  :class="row.aged ? '' : 'text-neutral-500'">
                  <td class="px-2 py-1.5">
                    <div>{{ row.vendor_name }}</div>
                    <div v-if="row.vendor_dic" class="font-mono text-[10px] text-neutral-400">{{ row.vendor_dic }}</div>
                  </td>
                  <td class="px-2 py-1.5 font-mono whitespace-nowrap">
                    <RouterLink :to="{ name: 'purchase-invoice-detail', params: { id: row.purchase_invoice_id } }"
                      class="text-primary-600 hover:underline">
                      {{ row.vendor_invoice_number || ('#' + row.purchase_invoice_id) }}
                    </RouterLink>
                  </td>
                  <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(row.tax_date) }}</td>
                  <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(row.due_date) }}</td>
                  <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.total_with_vat) }}</td>
                  <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.claimed_deduction_vat) }}</td>
                  <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtPct(row.unpaid_ratio) }}</td>
                  <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.target_reduction) }}</td>
                  <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap text-neutral-500">{{ fmtMoney(alreadyCorrected(row)) }}</td>
                  <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap font-semibold"
                    :class="row.delta < 0 ? 'text-danger-600' : (row.delta > 0 ? 'text-success-700' : '')">
                    {{ fmtMoney(row.delta) }}
                  </td>
                  <td class="px-2 py-1.5 text-center whitespace-nowrap">
                    <span v-if="row.movement === 'reduction'"
                      class="inline-block bg-danger-100 text-danger-700 text-[10px] font-bold px-1.5 py-px rounded">
                      {{ t('reports.s74b.movement.reduction') }}
                    </span>
                    <span v-else-if="row.movement === 'restoration'"
                      class="inline-block bg-success-100 text-success-700 text-[10px] font-bold px-1.5 py-px rounded">
                      {{ t('reports.s74b.movement.restoration') }}
                    </span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td class="px-2 py-1.5 whitespace-nowrap">
                    <span class="text-neutral-500">{{ row.state }}</span>
                    <span v-if="row.kh_zdph_44"
                      class="ml-1 inline-block bg-warning-100 text-warning-700 text-[10px] font-bold px-1 py-px rounded"
                      :title="t('reports.s74b.kh_zdph_44_hint')">§44</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Souhrn -->
        <div class="grid gap-4 md:grid-cols-3">
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <div class="text-[11px] uppercase tracking-wide text-neutral-400 font-medium">{{ t('reports.s74b.totals.reduction') }}</div>
            <div class="text-lg font-bold font-mono text-danger-600">{{ fmtMoney(preview.totals.reduction) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <div class="text-[11px] uppercase tracking-wide text-neutral-400 font-medium">{{ t('reports.s74b.totals.restoration') }}</div>
            <div class="text-lg font-bold font-mono text-success-700">{{ fmtMoney(preview.totals.restoration) }}</div>
          </div>
          <div class="bg-surface border rounded-lg shadow-sm p-4"
            :class="preview.totals.net_delta < 0 ? 'border-danger-200 bg-danger-50' : 'border-primary-200 bg-primary-50'">
            <div class="text-[11px] uppercase tracking-wide font-medium"
              :class="preview.totals.net_delta < 0 ? 'text-danger-700' : 'text-primary-700'">
              {{ t('reports.s74b.totals.net_delta') }}
            </div>
            <div class="text-lg font-bold font-mono"
              :class="preview.totals.net_delta < 0 ? 'text-danger-700' : 'text-primary-700'">
              {{ fmtMoney(preview.totals.net_delta) }}
            </div>
          </div>
        </div>

        <p class="text-xs text-neutral-400 italic">{{ t('reports.s74b.dphdp3_note') }}</p>
      </template>
    </div>
  </div>
</template>
