<script setup lang="ts">
/**
 * Přehled firem pro účetní kancelář — cross-supplier dashboard (Fáze F,
 * audit 2026-07 P2/M). Agreguje přes user_suppliers membership (BE), zobrazuje
 * jen role accountant/admin/readonly (nav gate v AppLayout, route RBAC v BE).
 * Každá firma má vlastní kartu: hlavička, co je potřeba udělat, objem dat.
 */
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { portfolioApi, type PortfolioCompany, type PortfolioCheckSummary } from '@/api/portfolio'
import Modal from '@/components/ui/Modal.vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useSupplierSwitch } from '@/composables/useSupplierSwitch'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import PortfolioVolumeChips from './PortfolioVolumeChips.vue'
import SupplierUrgency from '@/components/supplier/SupplierUrgency.vue'
import { applyListing, DEFAULT_DIR, listingFromQuery, queryFromListing, type PortfolioListing } from './portfolioListing'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const supplierSwitch = useSupplierSwitch()
// Správa firem je v menu Systém jen pro superadmina a Admin Plus, tlačítko má stejnou viditelnost.
const canManageCompanies = computed(() => auth.isSuperadmin || auth.isAdminPlusRole)

const companies = ref<PortfolioCompany[]>([])
const loading = ref(true)
const error = ref('')
const generatedAt = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await portfolioApi.overview()
    companies.value = res.companies
    generatedAt.value = res.generated_at
    void loadChecks(companies.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Řazení a filtry žijí v URL, aby šel odkaz na konkrétní pohled sdílet.
const listing = reactive<PortfolioListing>(listingFromQuery(route.query))
const search = ref(listing.q)
const visible = computed(() => applyListing(companies.value, listing))
const hasActiveFilters = computed(() => !!(listing.q || listing.vat || listing.mode || listing.urgency))

function applyFilters() {
  void router.replace({ query: queryFromListing(listing) })
}

function onSortChange() {
  listing.dir = DEFAULT_DIR[listing.sort]
  applyFilters()
}

function toggleDir() {
  listing.dir = listing.dir === 'asc' ? 'desc' : 'asc'
  applyFilters()
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    listing.q = value.trim()
    applyFilters()
  }, 200)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

watch(() => route.query, (q) => {
  Object.assign(listing, listingFromQuery(q))
  if (listing.q !== search.value.trim()) search.value = listing.q
})

function clearFilters() {
  clearTimeout(searchTimer)
  search.value = ''
  Object.assign(listing, { q: '', vat: undefined, mode: undefined, urgency: undefined })
  applyFilters()
}

/** Přepne aktivní firmu (X-Supplier-Id) a naviguje na cílovou agendu — mirror SupplierSwitcher.pick(). */
function switchTo(supplierId: number, path: string) {
  if (supplierId !== supplierStore.currentSupplierId) {
    void supplierSwitch.switchTo(supplierId, path)
    return
  }
  router.push(path)
}

function deadlineClass(days: number): string {
  if (days < 0) return 'text-danger-600'
  if (days <= 2) return 'text-danger-500'
  if (days <= 7) return 'text-warning-600'
  return 'text-neutral-900'
}

function countClass(n: number): string {
  return n > 0 ? 'text-warning-600' : 'text-neutral-400'
}

/** Barevný pruh karty: co hoří nejvíc — termín, pak rozpracované doklady. */
function accentClass(c: PortfolioCompany): string {
  const days = c.next_deadline?.days
  if (days !== undefined && days <= 2) return 'bg-danger-500'
  const pending = c.unbooked_documents + c.unmatched_bank_transactions + c.purchase_drafts + (c.inbox_pending ?? 0)
  if ((days !== undefined && days <= 7) || pending > 0) return 'bg-warning-500'
  return 'bg-success-500'
}

function initials(name: string): string {
  return name.replace(/,?\s*(s\.\s*r\.\s*o\.|a\.\s*s\.|spol\..*)$/i, '').split(/\s+/).filter(Boolean)
    .slice(0, 2).map((w) => w[0]).join('').toUpperCase()
}

/**
 * „K doúčtování" sčítá tři různé entity (FV / PF / bankovní pohyby), takže napevno
 * zadrátovaný proklik na `/invoices?booked=0` končil na prázdném seznamu, kdykoliv
 * číslo tvořily jen banka nebo přijaté faktury. Cíl proto bere BE rozpad — první
 * neprázdný typ; jednotlivé typy jsou prolinkované vedle čísla.
 */
function unbookedLink(c: PortfolioCompany): string {
  return c.unbooked_breakdown?.[0]?.link ?? '/invoices?booked=0'
}

// ── Měsíční kontrola per firma ──────────────────────────────────────────────
// Dotahuje se AŽ PO přehledu a po firmách: kontroly jsou o řád dražší než zbytek
// karty a tabulka by na ně čekala celá. Souběh je omezený, ať padesát firem
// nepošle padesát dotazů naráz.
const CHECK_CONCURRENCY = 6

type CheckState =
  | { status: 'loading' }
  | { status: 'error' }
  | { status: 'done'; summary: PortfolioCheckSummary | null }

const checks = ref<Record<number, CheckState>>({})
const openCheck = ref<PortfolioCompany | null>(null)

const openCheckSummary = computed<PortfolioCheckSummary | null>(() => {
  const c = openCheck.value
  if (!c) return null
  const state = checks.value[c.supplier_id]
  return state?.status === 'done' ? state.summary : null
})

// Nové načtení nebo odchod ze stránky zastaví frontu předchozího běhu. Jinak by
// se k jejím dotazům přidaly další a server by počítal tytéž firmy vícekrát.
let checksRun = 0
onBeforeUnmount(() => { checksRun++ })

async function loadChecks(list: PortfolioCompany[]) {
  const run = ++checksRun
  const queue = list.filter(c => c.accounting_mode === 'double_entry')
  checks.value = Object.fromEntries(queue.map(c => [c.supplier_id, { status: 'loading' } as CheckState]))
  let next = 0
  const worker = async () => {
    while (next < queue.length && run === checksRun) {
      const c = queue[next++]
      try {
        const summary = await portfolioApi.monthlyCheck(c.supplier_id)
        if (run !== checksRun) return
        checks.value = { ...checks.value, [c.supplier_id]: { status: 'done', summary } }
      } catch {
        if (run !== checksRun) return
        checks.value = { ...checks.value, [c.supplier_id]: { status: 'error' } }
      }
    }
  }
  await Promise.all(Array.from({ length: Math.min(CHECK_CONCURRENCY, queue.length) }, worker))
}

function checkState(c: PortfolioCompany): CheckState | undefined {
  return checks.value[c.supplier_id]
}

/** Hotový souhrn firmy, nebo null (načítá se, spadlo, daňová evidence). */
function checkSummaryOf(c: PortfolioCompany): PortfolioCheckSummary | null {
  const s = checks.value[c.supplier_id]
  return s?.status === 'done' ? s.summary : null
}

/** Pruh kontroly: červený u chyb, jantarový u varování, zelený když nic. */
function checkBandClass(s: PortfolioCheckSummary): string {
  if (s.errors > 0) return 'border-danger-500/30 bg-danger-50 text-danger-600'
  if (s.warnings > 0) return 'border-warning-500/30 bg-warning-50 text-warning-600'
  return 'border-success-500/30 bg-success-50 text-success-600'
}

/** Kolik nálezů se vejde do pruhu, než se z něj stane zeď textu. */
const BAND_FINDING_LIMIT = 6

function visibleFindings(s: PortfolioCheckSummary) {
  return s.findings.slice(0, BAND_FINDING_LIMIT)
}

function hiddenFindingCount(s: PortfolioCheckSummary): number {
  return Math.max(0, s.findings.length - BAND_FINDING_LIMIT)
}

/** „3 chyby · 7 varování", nebo „Kontrola v pořádku", když nic nesvítí. */
function checkSummaryLabel(s: PortfolioCheckSummary): string {
  if (s.errors === 0 && s.warnings === 0) return t('portfolio.check_ok')
  const parts: string[] = []
  if (s.errors > 0) parts.push(t('portfolio.check_errors', { n: s.errors }))
  if (s.warnings > 0) parts.push(t('portfolio.check_warnings', { n: s.warnings }))
  return parts.join(' · ')
}

/** Popisek kontroly bere tytéž překlady jako stránka měsíční kontroly. */
function checkLabel(key: string): string {
  const k = `accounting.closing.checks.${key}`
  const label = t(k)
  return label === k ? key : label
}

function openMonthlyCheck(c: PortfolioCompany) {
  const s = checks.value[c.supplier_id]
  const summary = s?.status === 'done' ? s.summary : null
  const query = summary
    ? `?period_id=${summary.period.id}&date_from=${summary.range_from}&date_to=${summary.range_to}`
    : ''
  switchTo(c.supplier_id, `/accounting/monthly-check${query}`)
}

function periodBadgeClass(status: string): string {
  if (status === 'open') return 'bg-success-50 text-success-600 ring-success-500/20'
  if (status === 'closing') return 'bg-warning-50 text-warning-600 ring-warning-500/20'
  return 'bg-neutral-100 text-neutral-500 ring-neutral-300/40'
}
</script>

<template>
  <div class="max-w-7xl">
    <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('portfolio.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('portfolio.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <button type="button" @click="load" :class="btnOutline('neutral')" class="whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('common.refresh') }}
        </button>
        <router-link v-if="canManageCompanies" to="/admin/suppliers" :class="btnFilled('primary')" class="whitespace-nowrap" data-testid="portfolio-companies-link">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.factory" /></svg>
          {{ t('nav.suppliers') }}
        </router-link>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>
    <EmptyState v-else-if="companies.length === 0" boxed icon="chart" :title="t('portfolio.empty')" />

    <template v-else>
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <input v-model="search" type="search" :placeholder="t('portfolio.search_placeholder')" data-testid="portfolio-search"
          class="flex-1 min-w-[12rem] h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
        <select v-model="listing.sort" @change="onSortChange" :title="t('common.sort_by')" data-testid="portfolio-sort"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option value="urgency">{{ t('supplier.listing.sort_urgency') }}</option>
          <option value="name">{{ t('supplier.listing.sort_name') }}</option>
          <option value="deadline">{{ t('portfolio.sort_deadline') }}</option>
          <option value="unbooked">{{ t('portfolio.sort_unbooked') }}</option>
          <option value="bank">{{ t('portfolio.sort_bank') }}</option>
          <option value="inbox">{{ t('portfolio.sort_inbox') }}</option>
          <option value="overdue">{{ t('supplier.listing.sort_overdue') }}</option>
          <option value="last_invoice">{{ t('supplier.listing.sort_last_invoice') }}</option>
          <option value="last_activity">{{ t('supplier.listing.sort_last_activity') }}</option>
        </select>
        <button type="button" @click="toggleDir" :class="btnOutline('neutral')" class="whitespace-nowrap" data-testid="portfolio-dir">
          <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': listing.dir === 'asc' }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
          {{ listing.dir === 'asc' ? t('supplier.listing.dir_asc') : t('supplier.listing.dir_desc') }}
        </button>
        <select v-model="listing.vat" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="portfolio-vat">
          <option :value="undefined">{{ t('supplier.listing.vat_all') }}</option>
          <option value="payer">{{ t('supplier.listing.vat_payer') }}</option>
          <option value="non_payer">{{ t('supplier.listing.vat_non_payer') }}</option>
        </select>
        <select v-model="listing.mode" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="portfolio-mode">
          <option :value="undefined">{{ t('supplier.listing.mode_all') }}</option>
          <option value="double_entry">{{ t('supplier.listing.mode_double_entry') }}</option>
          <option value="tax_evidence">{{ t('supplier.listing.mode_tax_evidence') }}</option>
        </select>
        <select v-model="listing.urgency" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-testid="portfolio-urgency">
          <option :value="undefined">{{ t('supplier.listing.urgency_all') }}</option>
          <option value="with">{{ t('supplier.listing.urgency_with') }}</option>
          <option value="without">{{ t('supplier.listing.urgency_without') }}</option>
        </select>
      </div>
      <p class="text-xs text-neutral-500 mb-2">{{ t('supplier.listing.count', { n: visible.length }) }}</p>

      <EmptyState v-if="!visible.length && hasActiveFilters" boxed variant="filtered"
        :cta="t('common.empty_state.clear_filters')" @action="clearFilters" />

      <div class="space-y-5">
        <article v-for="c in visible" :key="c.supplier_id" data-testid="company-card"
          class="relative bg-surface border border-neutral-200 rounded-xl shadow-sm overflow-hidden transition-shadow hover:shadow-md">
          <span class="absolute inset-y-0 left-0 w-1.5" :class="accentClass(c)" aria-hidden="true"></span>

          <!-- Hlavička firmy -->
          <header class="flex items-center gap-4 flex-wrap pl-6 pr-5 pt-5 pb-4">
            <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-primary-50 text-primary-700 text-base font-semibold shrink-0">
              {{ initials(c.company_name) }}
            </div>
            <div class="min-w-0 flex-1">
              <button type="button" class="cursor-pointer text-lg font-semibold text-neutral-900 hover:text-primary-600 text-left leading-tight" @click="switchTo(c.supplier_id, '/')">
                {{ c.company_name }}
              </button>
              <div class="flex items-center gap-2 flex-wrap mt-1 text-xs">
                <span v-if="c.ic" class="text-neutral-500 font-mono">{{ t('common.ic') }} {{ c.ic }}</span>
                <span v-if="c.period_status" class="inline-flex items-center px-2 py-0.5 rounded-full font-medium ring-1 ring-inset whitespace-nowrap" :class="periodBadgeClass(c.period_status.status)">
                  {{ c.period_status.fiscal_year }} · {{ t('portfolio.period_status_' + c.period_status.status) }}
                </span>
                <SupplierUrgency v-if="c.urgency" :urgency="c.urgency" />
                <span v-if="checkState(c)?.status === 'loading'" class="text-neutral-400">{{ t('portfolio.check_loading') }}</span>
                <span v-else-if="checkState(c)?.status === 'error'" class="text-neutral-400">{{ t('portfolio.check_failed') }}</span>
              </div>
            </div>
            <button type="button" class="cursor-pointer inline-flex items-center gap-1.5 px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg whitespace-nowrap shadow-sm"
              @click="switchTo(c.supplier_id, '/')">
              {{ t('portfolio.open_company') }} →
            </button>
          </header>

          <!-- Účetní kontrola: pruh přes celou šířku karty. Pilulka „4 varování"
               v hlavičce neřekla, CO nesedí — a právě to účetní z rozcestníku
               potřebuje vědět dřív, než firmu vůbec otevře. -->
          <div v-if="checkSummaryOf(c)" class="pl-6 pr-5 pb-4">
            <div class="rounded-lg border px-3 py-2.5" :class="checkBandClass(checkSummaryOf(c)!)">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-[11px] uppercase tracking-wide font-semibold">
                  {{ t('portfolio.check_band_title') }} · {{ checkSummaryLabel(checkSummaryOf(c)!) }}
                </span>
                <button type="button" data-testid="check-badge"
                  class="cursor-pointer text-xs font-medium hover:underline whitespace-nowrap" @click="openCheck = c">
                  {{ t('portfolio.check_detail') }} →
                </button>
              </div>
              <div v-if="checkSummaryOf(c)!.findings.length" class="flex flex-wrap gap-1.5 mt-2">
                <button v-for="f in visibleFindings(checkSummaryOf(c)!)" :key="f.key" type="button"
                  class="cursor-pointer inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-surface border border-neutral-200 hover:border-primary-300 text-xs text-neutral-700 text-left"
                  :title="checkLabel(f.key)" @click="openMonthlyCheck(c)">
                  <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="f.severity === 'error' ? 'bg-danger-500' : 'bg-warning-500'" aria-hidden="true"></span>
                  <span>{{ checkLabel(f.key) }}</span>
                  <span class="font-semibold tabular-nums">{{ f.count }}</span>
                </button>
                <button v-if="hiddenFindingCount(checkSummaryOf(c)!) > 0" type="button"
                  class="cursor-pointer inline-flex items-center px-2 py-1 rounded-md text-xs text-neutral-500 hover:text-primary-600"
                  @click="openCheck = c">
                  {{ t('portfolio.check_more', { n: hiddenFindingCount(checkSummaryOf(c)!) }) }}
                </button>
              </div>
            </div>
          </div>

          <!-- Co je potřeba udělat -->
          <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 pl-6 pr-5 pb-5">
            <div class="col-span-2 lg:col-span-1 rounded-lg bg-neutral-50 border border-neutral-100 px-3 py-2.5">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500">{{ t('portfolio.col_deadline') }}</div>
              <button v-if="c.next_deadline" type="button" class="cursor-pointer text-left mt-0.5 font-semibold"
                :class="deadlineClass(c.next_deadline.days)" @click="switchTo(c.supplier_id, '/reports/dph')">
                {{ c.next_deadline.label }} · {{ c.next_deadline.date }}
                <span class="block text-xs font-normal">{{ c.next_deadline.days < 0 ? t('portfolio.overdue_days', { n: Math.abs(c.next_deadline.days) }) : t('portfolio.days_left', { n: c.next_deadline.days }) }}</span>
              </button>
              <div v-else class="mt-0.5 text-sm text-neutral-400">{{ t('portfolio.no_deadline') }}</div>
            </div>
            <div class="rounded-lg bg-neutral-50 border border-neutral-100 px-3 py-2.5" data-testid="unbooked">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500">{{ t('portfolio.col_unbooked') }}</div>
              <button type="button" class="cursor-pointer text-2xl font-semibold tabular-nums leading-tight" :class="countClass(c.unbooked_documents)" @click="switchTo(c.supplier_id, unbookedLink(c))">
                {{ c.unbooked_documents }}
              </button>
              <div v-if="(c.unbooked_breakdown?.length ?? 0) > 1" class="flex flex-wrap gap-1 mt-1">
                <button v-for="b in c.unbooked_breakdown" :key="b.key" type="button"
                  class="cursor-pointer px-1.5 rounded-full bg-surface border border-neutral-200 hover:bg-primary-50 text-xs text-neutral-600 hover:text-primary-700 whitespace-nowrap"
                  @click="switchTo(c.supplier_id, b.link)">
                  {{ t('crm.action_items.breakdown_' + b.key) }} <span class="font-semibold">{{ b.count }}</span>
                </button>
              </div>
            </div>
            <button type="button" class="cursor-pointer text-left rounded-lg bg-neutral-50 border border-neutral-100 hover:border-primary-200 px-3 py-2.5" @click="switchTo(c.supplier_id, '/bank')">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500">{{ t('portfolio.col_bank_unmatched') }}</div>
              <div class="text-2xl font-semibold tabular-nums leading-tight" :class="countClass(c.unmatched_bank_transactions)">{{ c.unmatched_bank_transactions }}</div>
            </button>
            <button type="button" class="cursor-pointer text-left rounded-lg bg-neutral-50 border border-neutral-100 hover:border-primary-200 px-3 py-2.5" @click="switchTo(c.supplier_id, '/purchase-invoices?status=draft')">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500">{{ t('portfolio.col_purchase_drafts') }}</div>
              <div class="text-2xl font-semibold tabular-nums leading-tight" :class="countClass(c.purchase_drafts)">{{ c.purchase_drafts }}</div>
            </button>
            <button type="button" data-testid="inbox-pending" class="cursor-pointer text-left rounded-lg bg-neutral-50 border border-neutral-100 hover:border-primary-200 px-3 py-2.5" @click="switchTo(c.supplier_id, '/purchase-invoices/incoming')">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500">{{ t('portfolio.col_inbox_pending') }}</div>
              <div class="text-2xl font-semibold tabular-nums leading-tight" :class="countClass(c.inbox_pending ?? 0)">{{ c.inbox_pending ?? 0 }}</div>
            </button>
            <div class="col-span-2 lg:col-span-1 rounded-lg bg-neutral-50 border border-neutral-100 px-3 py-2.5">
              <div class="text-[11px] uppercase tracking-wide text-neutral-500">{{ t('portfolio.col_last_bank_import') }}</div>
              <div class="mt-0.5 text-sm text-neutral-700">{{ c.last_bank_import_at ? new Date(c.last_bank_import_at).toLocaleString() : '—' }}</div>
            </div>
          </div>

          <!-- Objem dat -->
          <div class="border-t border-neutral-100 bg-neutral-50/60 pl-6 pr-5 py-4" data-testid="volume">
            <div class="text-[11px] uppercase tracking-wide text-neutral-500 mb-2">{{ t('portfolio.volume_title') }}</div>
            <PortfolioVolumeChips :company="c" @open="(path) => switchTo(c.supplier_id, path)" />
          </div>
        </article>
      </div>

      <p class="text-xs text-neutral-400 mt-4">{{ t('portfolio.generated_at') }}: {{ new Date(generatedAt).toLocaleString() }}</p>
    </template>

    <!-- Co v měsíční kontrole té firmy nesedí. Jen klíče a počty — na nálezy vede
         proklik do měsíční kontroly, kde je celý kontext i opravy. -->
    <Modal v-if="openCheck" :title="t('portfolio.check_modal_title', { company: openCheck.company_name })"
      width-class="max-w-3xl" @close="openCheck = null">
      <template v-if="openCheckSummary">
        <p class="text-xs text-neutral-500 mb-3">
          {{ openCheckSummary.period.fiscal_year }} · {{ openCheckSummary.range_from }} – {{ openCheckSummary.range_to }}
        </p>
        <p v-if="openCheckSummary.findings.length === 0" class="text-sm text-success-600">
          {{ t('portfolio.check_ok_long') }}
        </p>
        <ul v-else class="divide-y divide-neutral-100 text-sm">
          <!-- Počet ve vlastním sloupci: zkrácený popisek jinak končí těsně u čísla
               a na první pohled to vypadá jako jeden údaj. -->
          <li v-for="f in openCheckSummary.findings" :key="f.key" class="flex items-center py-2">
            <span class="flex items-center gap-2 min-w-0 flex-1">
              <span class="w-2 h-2 rounded-full shrink-0" :class="f.severity === 'error' ? 'bg-danger-500' : 'bg-warning-500'" aria-hidden="true"></span>
              <span>{{ checkLabel(f.key) }}</span>
            </span>
            <span class="font-mono tabular-nums text-neutral-600 shrink-0 w-12 pl-4 text-right">{{ f.count }}</span>
          </li>
        </ul>
        <p class="text-xs text-neutral-400 mt-3">{{ t('portfolio.check_subset_hint') }}</p>
      </template>
      <template #footer>
        <button type="button" :class="btnOutline('neutral')" @click="openCheck = null">{{ t('common.close') }}</button>
        <button type="button" :class="btnFilled('primary')" @click="openMonthlyCheck(openCheck!)">
          {{ t('portfolio.check_open') }} →
        </button>
      </template>
    </Modal>
  </div>
</template>
