<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { reportsApi, type OssPreview } from '@/api/reports'
import {
  ossFilingApi,
  type OssArchivedSubmission,
  type OssEvidence,
  type OssReconciliation,
} from '@/api/ossFiling'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { useVatReportPeriod } from '@/composables/useVatReportPeriod'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const TABS = ['nahled', 'archiv', 'rekonciliace', 'evidence'] as const
type TabKey = typeof TABS[number]
function normalizeTab(v: unknown): TabKey {
  return TABS.includes(v as TabKey) ? (v as TabKey) : 'nahled'
}
const activeTab = ref<TabKey>(normalizeTab(route.query.tab))
watch(() => route.query.tab, v => { activeTab.value = normalizeTab(v) })
function switchTab(tab: TabKey) {
  router.replace({ query: { ...route.query, tab } })
}

const { year, month, ready, settingsError, initialize } = useVatReportPeriod('oss')
const quarter = computed({ get: () => Math.ceil(month.value / 3), set: (value: number) => { month.value = value * 3 } })
const preview = ref<OssPreview | null>(null)
const archive = ref<OssArchivedSubmission[]>([])
const reconciliation = ref<OssReconciliation | null>(null)
const evidence = ref<OssEvidence | null>(null)
const loading = ref(true)
const error = ref('')

const yearOptions = useYearOptions('invoices', year)
const quarterOptions = [1, 2, 3, 4]
let previewSeq = 0
let tabSeq = 0
onBeforeUnmount(() => { ++previewSeq; ++tabSeq })

async function loadPreview() {
  if (!ready.value) return
  const seq = ++previewSeq
  const requestedYear = year.value
  const requestedQuarter = quarter.value
  loading.value = true
  error.value = ''
  preview.value = null
  try {
    const result = await reportsApi.ossPreview(requestedYear, requestedQuarter)
    if (seq === previewSeq && ready.value) preview.value = result
  } catch (e) {
    if (seq === previewSeq && ready.value) error.value = apiErrorMessage(e)
  } finally {
    if (seq === previewSeq) loading.value = false
  }
}

// Archiv, rekonciliace a evidence se načítají až při otevření svého tabu — rekonciliace
// staví celý náhled období znovu, což je u čtvrtletí s tisíci řádky citelné, a na
// hlavní obrazovce ji nikdo nepotřebuje.
const tabLoading = ref(false)
const tabError = ref('')

async function loadTab(tab: TabKey) {
  if (!ready.value || tab === 'nahled') return
  const seq = ++tabSeq
  const requestedYear = year.value
  const requestedQuarter = quarter.value
  tabLoading.value = true
  tabError.value = ''
  try {
    if (tab === 'archiv') {
      const result = await ossFilingApi.archive()
      if (seq === tabSeq && ready.value) archive.value = result.submissions
    }
    if (tab === 'rekonciliace') {
      const result = await ossFilingApi.reconciliation(requestedYear, requestedQuarter)
      if (seq === tabSeq && ready.value) reconciliation.value = result
    }
    if (tab === 'evidence') {
      const result = await ossFilingApi.evidence(requestedYear, requestedQuarter)
      if (seq === tabSeq && ready.value) evidence.value = result
    }
  } catch (e) {
    if (seq === tabSeq && ready.value) tabError.value = apiErrorMessage(e)
  } finally {
    if (seq === tabSeq) tabLoading.value = false
  }
}

function downloadXml() {
  if (!ready.value || loading.value || !preview.value) return
  window.open(reportsApi.ossDownloadUrl(year.value, quarter.value), '_blank')
}

function exportEvidence(format: 'csv' | 'json') {
  if (!ready.value || tabLoading.value || !evidence.value) return
  window.open(ossFilingApi.evidenceExportUrl(year.value, quarter.value, format), '_blank')
}

function fmtMoney(v: number | null | undefined, currency?: string): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    style: 'currency',
    currency: currency || preview.value?.summary.return_currency || 'EUR',
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function fmtDateTime(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return String(iso)
  return d.toLocaleString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function periodLabel(s: OssArchivedSubmission): string {
  return s.period_quarter != null ? `Q${s.period_quarter} ${s.period_year}` : String(s.period_year)
}

const hasRows = computed(() => (preview.value?.summary.row_count ?? 0) > 0)

/**
 * Proklik z varování „řádky čekají na ruční posouzení" do seznamu faktur.
 *
 * Rozsah je `oss` — tady jde právě o řádky, které se do OSS ZAŘADILY a mají otazník
 * nad zemí nebo typem sazby. Tuzemská větev téhož příznaku do OSS podání nevstupuje
 * a hlásí ji přiznání k DPH, ne tahle obrazovka.
 *
 * `year: 'all'` s rozsahem dat schválně: seznam by jinak nasadil výchozí rok a čtvrtletí
 * z jiného roku by vypadalo prázdné.
 */
const manualReviewCount = computed(() => preview.value?.summary.manual_review_count ?? 0)
const manualReviewListLink = computed(() => ({
  path: '/invoices',
  query: {
    oss_review: 'oss',
    year: 'all',
    from: preview.value?.period.start ?? '',
    to: preview.value?.period.end ?? '',
  },
}))

/**
 * Kurz, kterým se období přepočetlo do měny podání. Účetní ho kontroluje proti tabulce
 * ECB, takže potřebuje obojí: ROZHODNÝ DEN (nemusí to být konec kvartálu — když ECB pro
 * poslední den nezveřejnila, použil se nejbližší následující) a KURZ PRO KAŽDOU MĚNU
 * dokladů v období. Bez toho by čísla v přiznání nešlo dohledat.
 */
const returnRateRows = computed(() =>
  Object.entries(preview.value?.summary.return_rates ?? {})
    .map(([currency, rate]) => ({ currency, rate }))
    .sort((a, b) => a.currency.localeCompare(b.currency)),
)
const hasReturnRates = computed(() =>
  Boolean(preview.value?.summary.return_rate_date) || returnRateRows.value.length > 0,
)
/** Kurzový den se posunul od konce období = ECB pro poslední den nezveřejnila. */
const rateDateShifted = computed(() => {
  const d = preview.value?.summary.return_rate_date
  return Boolean(d) && d !== preview.value?.period.end
})

/** Kurz je poměr, ne částka — Intl.NumberFormat se stylem currency by z něj udělal cenu. */
function fmtRate(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 6,
  }).format(v)
}

const reconciliationTone = computed(() => {
  const r = reconciliation.value
  if (!r || !r.has_filing || !r.snapshot_available) return 'neutral'
  return r.in_sync ? 'ok' : 'danger'
})

const changeTone: Record<string, string> = {
  added: 'text-primary-700',
  removed: 'text-danger-600',
  changed: 'text-warning-700',
}

watch([ready, year, quarter], () => {
  if (!ready.value) {
    ++previewSeq
    ++tabSeq
    preview.value = null
    archive.value = []
    reconciliation.value = null
    evidence.value = null
    loading.value = !settingsError.value
    tabLoading.value = false
    return
  }
  ++tabSeq
  loadPreview()
  // Období se změnilo → stará odpověď za jiné čtvrtletí by lhala. Zahodit, ne přepočítat:
  // přepočet by proběhl i pro tab, na který se uživatel nedívá.
  reconciliation.value = null
  evidence.value = null
  loadTab(activeTab.value)
})
watch(activeTab, tab => {
  if (tab === 'archiv' && archive.value.length === 0) loadTab(tab)
  if (tab === 'rekonciliace' && reconciliation.value === null) loadTab(tab)
  if (tab === 'evidence' && evidence.value === null) loadTab(tab)
})
onMounted(() => {
  void initialize()
})
watch(settingsError, value => {
  if (value) { error.value = apiErrorMessage(value); loading.value = false }
})
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.oss.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.oss.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-model.number="quarter" :disabled="!ready" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="q in quarterOptions" :key="q" :value="q">Q{{ q }}</option>
        </select>
        <select v-model.number="year" :disabled="!ready" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button type="button" @click="downloadXml" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5 whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.oss.download_xml') }}
        </button>
      </div>
    </div>

    <!-- Obecné EPO tuhle písemnost odmítne s tím, že uživatel musí být přihlášený
         v aplikaci MOSS/OSS. Stažení XML je proto celá cesta, kterou umíme nabídnout. -->
    <div class="mb-4 rounded-lg border border-primary-500/25 bg-primary-50 p-3 text-sm text-primary-900">
      {{ t('reports.oss.filing_channel_hint') }}
    </div>

    <div v-if="settingsError" class="mb-4 flex flex-wrap items-center gap-3 rounded-md border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600">
      <span>{{ apiErrorMessage(settingsError) }}</span>
      <button type="button" :class="btnOutline('neutral')" @click="initialize()">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ t('common.retry') }}
      </button>
    </div>

    <div class="flex gap-1 border-b border-neutral-200 overflow-x-auto overflow-y-hidden mb-4">
      <button v-for="tk in TABS" :key="tk" type="button" @click="switchTab(tk)"
        class="px-4 py-2 text-sm border-b-2 -mb-px whitespace-nowrap cursor-pointer"
        :class="activeTab === tk ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-500 hover:text-neutral-700'">
        {{ t('reports.oss.tab_' + tk) }}
      </button>
    </div>

    <!-- ── Tab: Náhled ─────────────────────────────────────────────────────── -->
    <section v-show="activeTab === 'nahled'">
      <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
        {{ t('common.loading') }}...
      </div>
      <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
        {{ error }}
      </div>

      <div v-else-if="preview" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.period') }}</div>
            <div class="text-lg font-semibold font-mono mt-1">{{ preview.period.label }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ fmtDate(preview.period.start) }} - {{ fmtDate(preview.period.end) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.total_base') }}</div>
            <div class="text-lg font-semibold font-mono mt-1">{{ fmtMoney(preview.summary.total_base) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.current_vat') }}</div>
            <div class="text-lg font-semibold font-mono mt-1">{{ fmtMoney(preview.summary.total_vat) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.total_corrections') }}</div>
            <div class="text-lg font-semibold font-mono mt-1">{{ fmtMoney(preview.summary.total_corrections) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.total_payable') }}</div>
            <div class="text-lg font-semibold font-mono mt-1">{{ fmtMoney(preview.summary.total_payable) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.deadline') }}</div>
            <div class="text-lg font-semibold font-mono mt-1">{{ fmtDate(preview.period.submission_deadline) }}</div>
          </div>
        </div>

        <!--
          Kurz podání — bez rozhodného dne a kurzů nejde žádné číslo výše dohledat
          v tabulce ECB, a přesně to účetní před odesláním dělá.
        -->
        <div v-if="hasReturnRates" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="flex items-baseline justify-between gap-3 flex-wrap">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">
              {{ t('reports.oss.return_rate_title', { currency: preview.summary.return_currency }) }}
            </div>
            <div class="text-sm font-mono">
              {{ fmtDate(preview.summary.return_rate_date) || '-' }}
            </div>
          </div>
          <div v-if="returnRateRows.length" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm font-mono">
            <span v-for="r in returnRateRows" :key="r.currency">
              {{ fmtRate(r.rate) }} {{ r.currency }} / 1 {{ preview.summary.return_currency }}
            </span>
          </div>
          <p v-if="rateDateShifted" class="mt-2 text-xs text-warning-700">
            {{ t('reports.oss.return_rate_shifted', { end: fmtDate(preview.period.end) }) }}
          </p>
          <p class="mt-2 text-xs text-neutral-500">{{ t('reports.oss.return_rate_note') }}</p>
        </div>

        <div v-if="preview.threshold && preview.threshold.threshold_eur > 0"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="flex items-baseline justify-between gap-3 mb-2">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">
              {{ t('reports.oss.threshold_title', { year: preview.threshold.year }) }}
            </div>
            <div class="text-sm font-mono">
              {{ fmtMoney(preview.threshold.total_eur) }} / {{ fmtMoney(preview.threshold.threshold_eur) }} EUR
            </div>
          </div>
          <div class="h-2 w-full rounded-full bg-neutral-200 overflow-hidden">
            <div class="h-full rounded-full transition-all"
              :class="preview.threshold.exceeded ? 'bg-danger-500' : (preview.threshold.near_threshold ? 'bg-warning-500' : 'bg-primary-500')"
              :style="{ width: Math.min(100, preview.threshold.pct) + '%' }" />
          </div>
          <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-neutral-500">
            <span>{{ preview.threshold.pct }} %</span>
            <span v-for="c in preview.threshold.by_country" :key="c.country" class="font-mono">
              {{ c.country }} {{ fmtMoney(c.amount_eur) }}
            </span>
          </div>
          <p class="mt-2 text-xs text-neutral-500">{{ t('reports.oss.threshold_note') }}</p>
        </div>

        <div v-if="preview.warnings.length" class="bg-warning-50 border border-warning-500/40 text-warning-700 rounded-md p-3 text-sm">
          <div class="font-semibold mb-1">{{ t('reports.oss.warnings') }}</div>
          <ul class="list-disc pl-5 space-y-1">
            <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
          </ul>
          <!--
            Varování o řádcích k posouzení je jediné, se kterým se dá něco udělat jinde —
            proklik vede přesně na tu množinu v seznamu faktur. Bez něj by uživatel četl,
            že něco k posouzení je, a musel to hledat ručně.
          -->
          <RouterLink v-if="manualReviewCount > 0" :to="manualReviewListLink"
            class="mt-2 inline-flex items-center gap-1 font-medium underline underline-offset-2 hover:text-warning-700/80">
            {{ t('reports.oss.manual_review_link', { n: manualReviewCount }) }}
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </RouterLink>
        </div>

        <EmptyState v-if="!hasRows" boxed accent="neutral" icon="doc" :title="t('reports.oss.no_data')" />

        <div v-for="country in preview.countries" :key="country.country" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50 flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-neutral-800">{{ country.country }}</h3>
            <div class="text-sm font-mono">
              {{ fmtMoney(country.base) }} / {{ fmtMoney(country.vat) }}
            </div>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-xs">
              <thead class="bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.rate') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.rate_type') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.total_base') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.total_vat') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.rows') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="r in country.rates" :key="`${country.country}-${r.rate}-${r.rate_type}`">
                  <td class="px-3 py-2 font-mono">{{ r.rate.toFixed(2) }} %</td>
                  <td class="px-3 py-2">{{ r.rate_type || '-' }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ fmtMoney(r.base) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ fmtMoney(r.vat) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ r.count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <details class="border-t border-neutral-200">
            <summary class="cursor-pointer px-5 py-3 text-sm text-primary-600">{{ t('reports.oss.detail_rows') }}</summary>
            <div class="overflow-x-auto">
              <table class="w-full text-xs">
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in country.rows" :key="row.item_id">
                    <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(row.tax_date) }}</td>
                    <td class="px-3 py-2 font-mono whitespace-nowrap">#{{ row.doc_number || row.invoice_id }}</td>
                    <td class="px-3 py-2">{{ row.client_name }}</td>
                    <td class="px-3 py-2">{{ row.description }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.base_return) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat_return) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
        </div>

        <section v-if="preview.corrections.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
            <h2 class="text-sm font-semibold text-neutral-800">{{ t('reports.oss.corrections') }}</h2>
          </header>
          <div v-for="correction in preview.corrections" :key="`${correction.period}-${correction.state_consumption}`"
            class="border-b border-neutral-200 last:border-b-0">
            <div class="px-5 py-3 grid grid-cols-3 gap-3 text-sm">
              <div class="font-mono">Q{{ correction.quarter }} {{ correction.year }}</div>
              <div>{{ correction.state_consumption }}</div>
              <div class="font-mono text-right">{{ fmtMoney(correction.correction) }}</div>
            </div>
            <details class="border-t border-neutral-100">
              <summary class="cursor-pointer px-5 py-2 text-sm text-primary-600">
                {{ t('reports.oss.detail_rows') }} ({{ correction.count }})
              </summary>
              <div class="overflow-x-auto">
                <table class="w-full text-xs">
                  <tbody class="divide-y divide-neutral-100">
                    <tr v-for="row in correction.rows" :key="row.item_id">
                      <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(row.tax_date) }}</td>
                      <td class="px-3 py-2 font-mono whitespace-nowrap">#{{ row.doc_number || row.invoice_id }}</td>
                      <td class="px-3 py-2">{{ row.client_name }}</td>
                      <td class="px-3 py-2">{{ row.description }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat_return) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </details>
          </div>
        </section>
      </div>
    </section>

    <div v-if="activeTab !== 'nahled' && tabLoading"
      class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}...
    </div>
    <div v-else-if="activeTab !== 'nahled' && tabError"
      class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ tabError }}
    </div>

    <!-- ── Tab: Archiv podání ──────────────────────────────────────────────── -->
    <section v-show="activeTab === 'archiv' && !tabLoading && !tabError" class="space-y-4">
      <div class="bg-neutral-50 border border-neutral-200 rounded-md p-3 text-xs text-neutral-500">
        {{ t('reports.oss.archive_note') }}
      </div>
      <EmptyState v-if="archive.length === 0" boxed accent="neutral" icon="doc"
        :title="t('reports.oss.archive_empty')" />
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.period') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.archive_generated_at') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.archive_status') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.archive_submitted') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.archive_validation') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.archive_sha') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.archive_size') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="s in archive" :key="s.id">
            <tr>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ periodLabel(s) }}</td>
              <td class="px-3 py-2 whitespace-nowrap">{{ fmtDateTime(s.generated_at) }}</td>
              <td class="px-3 py-2">
                <span class="px-1.5 py-0.5 rounded text-[11px]"
                  :class="['submitted', 'accepted'].includes(s.status)
                    ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-600'">
                  {{ t('reports.oss.archive_status_' + s.status) }}
                </span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <template v-if="s.submitted_at">
                  {{ fmtDateTime(s.submitted_at) }}
                  <span v-if="s.submission_ref" class="block font-mono text-[11px] text-neutral-500"
                    :title="t('reports.oss.archive_submission_ref')">{{ s.submission_ref }}</span>
                </template>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2">
                <span :class="s.validation_status === 'failed' ? 'text-danger-600' : 'text-neutral-500'">
                  {{ t('reports.oss.archive_validation_' + s.validation_status) }}
                </span>
                <span v-if="s.validation_errors?.length" class="ml-1 text-danger-600">
                  ({{ s.validation_errors.length }})
                </span>
              </td>
              <td class="px-3 py-2 font-mono text-[11px] text-neutral-500">{{ s.xml_sha256.slice(0, 16) }}…</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ s.xml_size_bytes }} B</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <a :href="ossFilingApi.archivedXmlUrl(s.id)" target="_blank"
                  class="text-primary-600 hover:text-primary-700">{{ t('reports.oss.archive_download') }}</a>
              </td>
            </tr>
            <!-- Samotné „Neplatné" je slepá ulička — bez důvodů uživatel neví, co opravit.
                 Poznámka visí na témž záznamu, takže má smysl ji ukázat na stejném místě. -->
            <tr v-if="s.validation_errors?.length || s.notes" class="border-t-0!">
              <td></td>
              <td colspan="7" class="px-3 pb-2 space-y-1">
                <div v-if="s.validation_errors?.length"
                  class="rounded border border-danger-500/40 bg-danger-50 p-2">
                  <p class="font-medium text-danger-700">{{ t('reports.oss.archive_validation_errors') }}</p>
                  <ul class="mt-0.5 list-disc pl-4 text-danger-700">
                    <li v-for="(err, i) in s.validation_errors" :key="i" class="break-words">{{ err }}</li>
                  </ul>
                </div>
                <div v-if="s.notes" class="rounded border border-neutral-200 bg-neutral-50 p-2 text-neutral-600">
                  <span class="font-medium">{{ t('reports.oss.archive_notes') }}:</span>
                  <span class="ml-1 break-words whitespace-pre-wrap">{{ s.notes }}</span>
                </div>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ── Tab: Rekonciliace ───────────────────────────────────────────────── -->
    <section v-show="activeTab === 'rekonciliace' && !tabLoading && !tabError" class="space-y-4">
      <div class="bg-neutral-50 border border-neutral-200 rounded-md p-3 text-xs text-neutral-500">
        {{ t('reports.oss.reconciliation_note') }}
      </div>

      <EmptyState v-if="reconciliation && !reconciliation.has_filing" boxed accent="neutral" icon="doc"
        :title="t('reports.oss.reconciliation_no_filing')" />

      <template v-else-if="reconciliation">
        <div class="rounded-md p-3 text-sm border"
          :class="{
            'bg-success-50 border-success-500/40 text-success-700': reconciliationTone === 'ok',
            'bg-danger-50 border-danger-500/40 text-danger-600': reconciliationTone === 'danger',
            'bg-warning-50 border-warning-500/40 text-warning-700': reconciliationTone === 'neutral',
          }">
          <strong v-if="!reconciliation.snapshot_available">{{ t('reports.oss.reconciliation_no_snapshot') }}</strong>
          <strong v-else-if="reconciliation.in_sync">{{ t('reports.oss.reconciliation_in_sync') }}</strong>
          <strong v-else>{{ t('reports.oss.reconciliation_out_of_sync') }}</strong>
        </div>

        <div v-if="reconciliation.basis && !reconciliation.basis.is_proven_filing"
          class="bg-warning-50 border border-warning-500/40 text-warning-700 rounded-md p-3 text-sm">
          {{ t('reports.oss.reconciliation_not_proven') }}
        </div>

        <div v-if="reconciliation.basis" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 text-sm space-y-1">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-2">
            {{ t('reports.oss.reconciliation_basis') }}
          </div>
          <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <div><span class="text-neutral-500">{{ t('reports.oss.archive_generated_at') }}:</span>
              <span class="font-mono ml-1">{{ fmtDateTime(reconciliation.basis.generated_at) }}</span></div>
            <div><span class="text-neutral-500">{{ t('reports.oss.archive_status') }}:</span>
              <span class="ml-1">{{ t('reports.oss.archive_status_' + reconciliation.basis.status) }}</span></div>
            <div v-if="reconciliation.basis.submission_ref">
              <span class="text-neutral-500">{{ t('reports.oss.reconciliation_ref') }}:</span>
              <span class="font-mono ml-1">{{ reconciliation.basis.submission_ref }}</span></div>
            <div><span class="text-neutral-500">{{ t('reports.oss.archive_sha') }}:</span>
              <span class="font-mono ml-1 text-[11px]">{{ reconciliation.basis.xml_sha256.slice(0, 16) }}…</span></div>
          </div>
        </div>

        <div v-if="reconciliation.differences.totals.length"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
            <h3 class="text-sm font-semibold text-neutral-800">{{ t('reports.oss.reconciliation_totals') }}</h3>
          </header>
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.reconciliation_item') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.reconciliation_filed') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.reconciliation_current') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.reconciliation_delta') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="d in reconciliation.differences.totals" :key="d.key">
                <td class="px-3 py-2">{{ t('reports.oss.reconciliation_total_' + d.key) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtMoney(d.filed) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtMoney(d.current) }}</td>
                <td class="px-3 py-2 text-right font-mono font-semibold"
                  :class="d.delta < 0 ? 'text-danger-600' : 'text-warning-700'">{{ fmtMoney(d.delta) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="reconciliation.differences.documents.length"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
            <h3 class="text-sm font-semibold text-neutral-800">{{ t('reports.oss.reconciliation_documents') }}</h3>
          </header>
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.reconciliation_change') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.document') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.country') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.tax_date') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.total_base') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.total_vat') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.reconciliation_changed_at') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="d in reconciliation.differences.documents" :key="`${d.change}-${d.item_id}`">
                <td class="px-3 py-2 font-medium" :class="changeTone[d.change]">
                  {{ t('reports.oss.reconciliation_change_' + d.change) }}
                </td>
                <td class="px-3 py-2 font-mono whitespace-nowrap">
                  <router-link :to="`/invoices/${d.invoice_id}`" class="text-primary-600 hover:text-primary-700">
                    {{ d.doc_number || '#' + d.invoice_id }}
                  </router-link>
                </td>
                <td class="px-3 py-2 font-mono">{{ d.country }}</td>
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(d.tax_date) }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(d.base) }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(d.vat) }}</td>
                <td class="px-3 py-2 whitespace-nowrap text-neutral-500">{{ fmtDateTime(d.updated_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="reconciliation.differences.rows.length || reconciliation.differences.corrections.length"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
            <h3 class="text-sm font-semibold text-neutral-800">{{ t('reports.oss.reconciliation_rows') }}</h3>
          </header>
          <table class="w-full text-xs">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="d in [...reconciliation.differences.rows, ...reconciliation.differences.corrections]"
                :key="d.change + d.key">
                <td class="px-3 py-2 font-medium" :class="changeTone[d.change]">
                  {{ t('reports.oss.reconciliation_change_' + d.change) }}
                </td>
                <td class="px-3 py-2 font-mono">{{ d.key }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <!-- ── Tab: Evidence § 110f ────────────────────────────────────────────── -->
    <section v-show="activeTab === 'evidence' && !tabLoading && !tabError" class="space-y-4">
      <div class="bg-neutral-50 border border-neutral-200 rounded-md p-3 text-xs text-neutral-500 space-y-1">
        <p>{{ t('reports.oss.evidence_note', { years: evidence?.retention_years ?? 10 }) }}</p>
        <p class="font-mono">{{ evidence?.legal_basis }}</p>
      </div>

      <div v-if="evidence" class="flex gap-2 flex-wrap">
        <button type="button" @click="exportEvidence('csv')" :disabled="evidence.records.length === 0"
          class="cursor-pointer h-9 px-4 border border-neutral-300 hover:bg-neutral-50 disabled:opacity-50 text-sm rounded-md inline-flex items-center gap-1.5 whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.oss.evidence_export_csv') }}
        </button>
        <button type="button" @click="exportEvidence('json')" :disabled="evidence.records.length === 0"
          class="cursor-pointer h-9 px-4 border border-neutral-300 hover:bg-neutral-50 disabled:opacity-50 text-sm rounded-md inline-flex items-center gap-1.5 whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.oss.evidence_export_json') }}
        </button>
      </div>

      <div v-if="evidence && Object.keys(evidence.unsupported).length"
        class="bg-warning-50 border border-warning-500/40 text-warning-700 rounded-md p-3 text-sm">
        <div class="font-semibold mb-1">{{ t('reports.oss.evidence_unsupported') }}</div>
        <ul class="list-disc pl-5 space-y-1">
          <li v-for="(reason, code) in evidence.unsupported" :key="code">
            <span class="font-mono">{{ code }}</span> - {{ reason }}
          </li>
        </ul>
      </div>

      <EmptyState v-if="evidence && evidence.records.length === 0" boxed accent="neutral" icon="doc"
        :title="t('reports.oss.evidence_empty')" />

      <div v-else-if="evidence" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">#</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.country') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.evidence_supply') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.tax_date') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.total_base') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.rate') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.total_vat') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.evidence_customer') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.evidence_retain_until') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in evidence.records" :key="r.id">
              <td class="px-3 py-2 font-mono">{{ r.seq }}</td>
              <td class="px-3 py-2 font-mono">{{ r.consumption_country }}</td>
              <td class="px-3 py-2">
                {{ r.supply_description }}
                <span v-if="r.adjusted_period" class="ml-1 px-1.5 py-0.5 rounded bg-warning-50 text-warning-700 text-[11px] font-mono">
                  {{ t('reports.oss.evidence_adjustment', { period: r.adjusted_period }) }}
                </span>
              </td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(r.supply_date) }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                {{ fmtMoney(Number(r.taxable_amount_return), r.return_currency) }}
                <div class="text-[11px] text-neutral-400">
                  {{ fmtMoney(Number(r.taxable_amount), r.taxable_currency) }}
                </div>
              </td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                {{ Number(r.vat_rate).toFixed(2) }} %
                <div class="text-[11px] text-neutral-400">{{ r.vat_rate_type || '-' }}</div>
              </td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                {{ fmtMoney(Number(r.vat_amount_return), r.return_currency) }}
              </td>
              <td class="px-3 py-2">{{ r.customer_name || '-' }}</td>
              <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(r.retain_until) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
