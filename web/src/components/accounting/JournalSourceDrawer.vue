<script setup lang="ts">
import { requestSupplierId } from '@/workspace/supplierContext'
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { accountingApi, type JournalSourceSummary, type SourceAction, type SourceFieldFormat } from '@/api/accounting'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'
import type { PermissionKey } from '@/security/permissions'
import Drawer from '@/components/ui/Drawer.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import SourceBlockRenderer from '@/components/accounting/SourceBlockRenderer.vue'
import JournalRelatedPanel from '@/components/accounting/JournalRelatedPanel.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'

/**
 * Náhled zdrojového dokladu účetního zápisu.
 *
 * READ-ONLY a NAVIGAČNÍ: ukazuje shrnutí + bloky (položky, DPH, úhrady…) a
 * nabízí prokliky. Mutace (vystavení, vyřazení majetku, párování banky) sem
 * ZÁMĚRNĚ nepatří — ty žijí na detailu dokladu, kde je k nim celý kontext.
 *
 * Deník používá /journal/{id}/source; seznam přijatých faktur používá
 * /purchase-invoices/{id}/preview s ověřením vlastnictví dokladu.
 */
const props = defineProps<{ entryId?: number; purchaseInvoiceId?: number }>()
const emit = defineEmits<{
  (e: 'close'): void
  /** Odskok na zápis v deníku — drawer visí NAD deníkem, takže to musí řešit stránka. */
  (e: 'focus-entry', entryId: number): void
}>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const loading = ref(false)
const summary = ref<JournalSourceSummary | null>(null)

/**
 * Zobrazený zápis — NENÍ to vždy `props.entryId`. Z panelu „Souvisí" jde přepnout
 * na protějšek (účtování úhrady ↔ účtování dokladu), aniž by účetní ztratil pozici
 * v deníku; zpět se vrací šipkou v hlavičce. Historie je jednoúrovňová záměrně:
 * graf vazeb je oboustranný, takže hlubší zanořování jen krouží mezi dvěma zápisy.
 */
const currentId = ref(props.entryId ?? 0)
const rootId = ref(props.entryId ?? 0)
const relatedEntryId = computed(() => currentId.value || summary.value?.entry_id || 0)

async function load(id: number) {
  if (id <= 0 && !props.purchaseInvoiceId) return
  loading.value = true
  summary.value = null
  try {
    summary.value = id > 0 ? await accountingApi.getJournalSource(id)
      : await purchaseInvoicesApi.preview(props.purchaseInvoiceId!)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    emit('close')
  } finally {
    loading.value = false
  }
}

// Drawer se mountuje s v-if, ale entryId se může změnit klikem na jiný řádek.
watch(() => [props.entryId, props.purchaseInvoiceId], () => {
  rootId.value = props.entryId ?? 0
  if (currentId.value !== rootId.value) currentId.value = rootId.value
  else void load(rootId.value)
}, { immediate: true })

watch(currentId, id => { void load(id) })

const sourceTypeLabel = computed(() => {
  const type = summary.value?.source_type
  if (!type) return ''
  const key = `accounting.journal.source.${type}`
  const v = t(key)
  return v === key ? type : v
})

const title = computed(() => {
  if (!summary.value) return t('common.loading')
  const num = summary.value.title
  return num ? `${sourceTypeLabel.value} ${num}` : sourceTypeLabel.value
})

const statusClass = computed(() => {
  const variant = summary.value?.status?.variant
  switch (variant) {
    case 'success': return 'bg-success-100 text-success-700'
    case 'danger': return 'bg-danger-100 text-danger-700'
    case 'warning': return 'bg-warning-100 text-warning-700'
    case 'primary': return 'bg-primary-100 text-primary-700'
    default: return 'bg-neutral-100 text-neutral-600'
  }
})

const statusLabel = computed(() => {
  const key = summary.value?.status?.key
  if (!key) return ''
  const i18nKey = `accounting.journal.source_drawer.status.${key}`
  const v = t(i18nKey)
  return v === i18nKey ? key : v
})

const unavailableText = computed(() => {
  const reason = summary.value?.unavailable_reason || 'no_source'
  const key = `accounting.journal.source_drawer.unavailable.${reason}`
  const v = t(key)
  return v === key ? t('accounting.journal.source_drawer.unavailable.no_source') : v
})

/**
 * Backend posílá jen `key` + `permission` + cíl; ActionItem[] pro sdílený
 * ActionBar si skládá FE (závazná UI konvence projektu).
 */
const actions = computed<ActionItem[]>(() => {
  const list = summary.value?.actions ?? []
  return list
    .filter((a: SourceAction) => auth.canRead(a.permission as PermissionKey))
    .map((a: SourceAction, i): ActionItem => ({
      key: a.key,
      label: t(`accounting.journal.source_drawer.action.${a.key}`),
      icon: a.key === 'open_pdf' || a.key === 'open_original' ? 'download' : 'doc',
      tier: i === 0 ? 'primary' : 'secondary',
      variant: i === 0 ? 'primary' : 'neutral',
      ...(a.href ? { href: withSupplierScope(a.href) } : {}),
      ...(a.route ? { to: { name: a.route.name, params: a.route.params, query: a.route.query } } : {}),
    }))
})

/**
 * PDF dokladu se otevírá v nové záložce, kde axios hlavičku X-Supplier-Id nepošle.
 * Aktivní firma proto jede v query paramu (middleware ho čte jako fallback), jinak by
 * server u dokladu jiné firmy vzal výchozí firmu uživatele a vrátil not_found.
 */
function withSupplierScope(href: string): string {
  if (!href.startsWith('/api/')) return href
  const sid = requestSupplierId()
  if (!sid || !/^\d+$/.test(sid)) return href
  return `${href}${href.includes('?') ? '&' : '?'}supplier_id=${sid}`
}

function label(key: string): string {
  const v = t(key)
  return v === key ? key.split('.').pop() || key : v
}

function fmt(value: unknown, format: SourceFieldFormat, key?: string): string {
  if (value === null || value === undefined || value === '') return '—'
  if (summary.value?.source_type === 'other_item' && key === 'side') {
    return t(`other_items.side.${String(value)}`)
  }
  if (summary.value?.source_type === 'other_item' && key === 'kind') {
    const side = summary.value.fields.find(field => field.key === 'side')?.value
    return t(`other_items.kind_by_side.${String(side)}.${String(value)}`)
  }
  const currency = summary.value?.currency || 'CZK'
  switch (format) {
    case 'currency': return formatMoney(Number(value), currency)
    case 'date': return formatDate(String(value))
    case 'percent': return `${Number(value)} %`
    case 'number': return new Intl.NumberFormat().format(Number(value))
    case 'bool': return value ? t('common.yes') : t('common.no')
    case 'doc_ref': return `#${value}`
    default: return String(value)
  }
}
</script>

<template>
  <Drawer :title="title" :subtitle="summary?.subtitle ?? null" width-class="max-w-3xl" @close="emit('close')">
    <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <template v-else-if="summary">
      <button v-if="currentId !== rootId" type="button" @click="currentId = rootId"
              class="cursor-pointer mb-3 inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-700">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        {{ t('accounting.journal.related.back') }}
      </button>

      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 pb-4">
        <span v-if="summary.status" class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass">
          {{ statusLabel }}
        </span>
        <span v-else></span>
        <ActionBar v-if="actions.length" :actions="actions" />
      </div>

      <!-- Uzávěrkové typy se syntetickým source_id, provision, manual… -->
      <p v-if="!summary.available" class="mt-4 rounded-md bg-neutral-50 px-3 py-2 text-sm text-neutral-600">
        {{ unavailableText }}
      </p>

      <template v-else>
        <dl v-if="summary.fields.length" class="mt-5 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
          <div v-for="f in summary.fields" :key="f.key"
               class="flex items-baseline justify-between gap-3 border-b border-neutral-200 py-2 text-sm">
            <dt class="shrink-0 text-neutral-500">{{ label(f.label_key) }}</dt>
            <dd class="min-w-0 font-medium"
                :class="[
                  f.key === 'note' ? 'whitespace-pre-wrap break-words text-left' : 'truncate text-right',
                  f.format === 'currency' || f.format === 'number' ? 'font-mono' : '',
                ]">
              {{ fmt(f.value, f.format, f.key) }}
            </dd>
          </div>
        </dl>

        <SourceBlockRenderer
          v-for="b in summary.blocks"
          :key="b.key"
          :block="b"
          :fallback-currency="summary.currency"
        />
        <LinkedDocumentsPanel
          v-if="summary.source_type === 'other_item' && summary.source_id && auth.canRead('other_items') && auth.canRead('documents')"
          :key="summary.source_id"
          entity-type="other_item" :entity-id="summary.source_id" readonly collapsible
          :title="t('accounting.journal.source_drawer.source_documents')"
        />
      </template>

      <!--
        Panel je MIMO v-if na `available`: i u zápisu bez náhledu dokladu (např.
        zápočet) dává smysl ukázat, co s ním souvisí — a v draweru navíc umí
        přepnout náhled na protějšek.
      -->
      <JournalRelatedPanel v-if="relatedEntryId && auth.canRead('accounting')" class="mt-6" :entry-id="relatedEntryId" show-preview
                           @preview="id => currentId = id"
                           @focus-entry="id => emit('focus-entry', id)" />
    </template>
  </Drawer>
</template>
