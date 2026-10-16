<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  eshopApi,
  type Category,
  type EshopCurrency,
  type Manufacturer,
  type PriceLevel,
  type PriceLevelMatchType,
  type PriceLevelPayload,
} from '@/api/eshop'
import { stockApi } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatNumber } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { formatAdjustment, invalidAdjustment, joinAdjustment, splitAdjustment, type AdjustmentKind } from '@/pages/eshop/priceAdjustment'
import { isFixedRow, ruleDuplicateKey, rulePayload, ruleRowFrom, type RuleKind, type RuleRow } from '@/pages/eshop/priceLevelRuleRows'

/**
 * Cenové hladiny odběratelů (Bronze / Silver / Gold). Odběratel bez hladiny je
 * v hladině „Default" a platí pro něj standardní ceny; ta se v DB neeviduje.
 * Cenu počítá backend (EffectivePriceResolver), tady se jen spravuje číselník
 * a pravidla hladin.
 */
const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const levels = ref<PriceLevel[]>([])
const loading = ref(false)
const canWrite = computed(() => auth.canWrite('eshop.write'))

async function load() {
  loading.value = true
  try {
    levels.value = await eshopApi.listPriceLevels()
  } catch (e: any) {
    toast.error(mapError(e))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function errorCode(e: any): string {
  const code = String(e?.response?.data?.error?.code ?? '')
  return code.startsWith('eshop.error.') ? code.slice('eshop.error.'.length) : code
}

function mapError(e: any): string {
  const code = errorCode(e)
  if (code) {
    const key = `eshop.error.${code}`
    const localized = t(key)
    if (localized !== key) return localized
  }
  return e?.response?.data?.error?.message || t('common.error')
}

function normalizeDecimal(value: string | number | null | undefined): string {
  return String(value ?? '').trim().replace(/\s+/g, '').replace(',', '.')
}

/** Výchozí hodnota hladiny v tabulce: „−10 %“ sleva, „+45 %“ přirážka. */
function formatPct(value: string | number | null | undefined): string {
  return formatAdjustment(value, n => formatNumber(n, { maximumFractionDigits: 3 }))
}

// ── Modal: založit / upravit hladinu ─────────────────────────────────────
interface LevelForm {
  code: string
  name: string
  /** Kladná hodnota; znaménko nese `default_kind`. */
  default_discount_pct: string
  default_kind: AdjustmentKind
  is_active: boolean
  display_order: number
}
const modalOpen = ref(false)
const editing = ref<PriceLevel | null>(null)
const saving = ref(false)
const error = ref('')
const form = ref<LevelForm>({ code: '', name: '', default_discount_pct: '0', default_kind: 'discount', is_active: true, display_order: 0 })
const takenCodes = computed(() => levels.value.filter(l => l.id !== editing.value?.id).map(l => l.code))

function openCreate() {
  editing.value = null
  form.value = { code: '', name: '', default_discount_pct: '0', default_kind: 'discount', is_active: true, display_order: (levels.value.length + 1) * 10 }
  error.value = ''
  modalOpen.value = true
}

/** Uložená hladina → formulář: znaménko výchozí hodnoty se rozloží na druh a kladné číslo. */
function formFromLevel(level: PriceLevel): LevelForm {
  const { kind, value } = splitAdjustment(level.default_discount_pct)
  return {
    code: level.code,
    name: level.name,
    default_discount_pct: value,
    default_kind: kind,
    is_active: level.is_active,
    display_order: level.display_order,
  }
}

function openEdit(level: PriceLevel) {
  editing.value = level
  form.value = formFromLevel(level)
  error.value = ''
  modalOpen.value = true
}

function payloadOf(value: LevelForm): PriceLevelPayload {
  return {
    code: value.code.trim(),
    name: value.name.trim(),
    default_discount_pct: joinAdjustment(value.default_kind, value.default_discount_pct) || '0',
    is_active: value.is_active,
    display_order: Number(value.display_order) || 0,
  }
}

async function save() {
  error.value = ''
  if (!form.value.code.trim() || !form.value.name.trim()) {
    error.value = t('eshop.price_levels.field_name') + ' / ' + t('eshop.price_levels.field_code')
    return
  }
  if (invalidAdjustment(form.value.default_kind, normalizeDecimal(form.value.default_discount_pct) || '0')) {
    error.value = t('eshop.price_levels.default_discount_invalid')
    return
  }
  saving.value = true
  try {
    if (editing.value) {
      await eshopApi.updatePriceLevel(editing.value.id, payloadOf(form.value))
    } else {
      await eshopApi.createPriceLevel(payloadOf(form.value))
    }
    toast.success(t('common.saved'))
    modalOpen.value = false
    await load()
  } catch (e: any) {
    error.value = mapError(e)
  } finally {
    saving.value = false
  }
}

/** Přiřazenou hladinu nejde smazat, místo toho nabídneme deaktivaci (odběratelé pak mají standardní ceny). */
async function deactivate(level: PriceLevel) {
  try {
    await eshopApi.updatePriceLevel(level.id, payloadOf({ ...formFromLevel(level), is_active: false }))
    toast.success(t('eshop.price_levels.deactivated'))
    await load()
  } catch (e: any) {
    toast.error(mapError(e))
  }
}

function offerDeactivation(level: PriceLevel) {
  if (level.is_active && confirm(t('eshop.price_levels.in_use_deactivate_confirm', { name: level.name, count: level.client_count }))) {
    void deactivate(level)
  } else if (!level.is_active) {
    toast.warning(t('eshop.price_levels.in_use_hint'))
  }
}

async function remove(level: PriceLevel) {
  if (level.client_count > 0) {
    offerDeactivation(level)
    return
  }
  if (!confirm(t('eshop.price_levels.delete_confirm', { name: level.name }))) return
  try {
    await eshopApi.deletePriceLevel(level.id)
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    if (errorCode(e) === 'price_level_in_use') offerDeactivation(level)
    else toast.error(mapError(e))
  }
}

// ── Pravidla hladiny ─────────────────────────────────────────────────────
type SelectOption = { value: number; label: string; secondary?: string }

const MATCH_TYPES: PriceLevelMatchType[] = ['category', 'manufacturer', 'product']
/** Pevná cena jen u zboží (CHECK chk_splr_value). */
function ruleKinds(rule: RuleRow): RuleKind[] {
  return rule.match_type === 'product' ? ['discount_pct', 'markup_pct', 'fixed'] : ['discount_pct', 'markup_pct']
}

const rulesLevel = ref<PriceLevel | null>(null)
const rules = ref<RuleRow[]>([])
const rulesLoading = ref(false)
const rulesLoadFailed = ref(false)
const rulesSaving = ref(false)
const rulesError = ref('')
const categories = ref<Category[]>([])
const manufacturers = ref<Manufacturer[]>([])
const currencies = ref<EshopCurrency[]>([])
const productOptions = ref<SelectOption[]>([])
const productLoading = ref(false)
let referencesLoaded = false
let ruleKey = 0
let productSearchSeq = 0

const currencyCodes = computed(() => {
  const codes = currencies.value.filter(c => !c.archived).map(c => c.code.toUpperCase())
  if (!codes.includes('CZK')) codes.unshift('CZK')
  return codes
})
function currencyChoices(current: string): string[] {
  const code = current.toUpperCase()
  return code && !currencyCodes.value.includes(code) ? [...currencyCodes.value, code] : currencyCodes.value
}

async function loadReferences() {
  if (referencesLoaded) return
  const [cats, mans, curs] = await Promise.all([
    eshopApi.listCategories().catch(() => [] as Category[]),
    eshopApi.listManufacturers().catch(() => [] as Manufacturer[]),
    eshopApi.listCurrencies().catch(() => [] as EshopCurrency[]),
  ])
  categories.value = cats
  manufacturers.value = mans
  currencies.value = curs
  referencesLoaded = true
}

async function openRules(level: PriceLevel) {
  rulesLevel.value = level
  rules.value = []
  rulesError.value = ''
  rulesLoadFailed.value = false
  rulesLoading.value = true
  try {
    const [loaded] = await Promise.all([eshopApi.getPriceLevelRules(level.id), loadReferences()])
    if (rulesLevel.value?.id !== level.id) return
    rules.value = loaded.map(r => ruleRowFrom(r, ++ruleKey))
  } catch (e: any) {
    rulesLoadFailed.value = true
    rulesError.value = mapError(e)
  } finally {
    rulesLoading.value = false
  }
}

function closeRules() {
  if (rulesSaving.value) return
  rulesLevel.value = null
}

function addRule() {
  rules.value.push({
    key: ++ruleKey,
    match_type: 'category',
    match_id: null,
    match_label: null,
    rule_type: 'discount_pct',
    discount_pct: '',
    fixed_price: '',
    currency_code: 'CZK',
    pct_currency: null,
    priority: 0,
  })
}

function removeRule(idx: number) {
  rules.value.splice(idx, 1)
}

/** Pevná cena existuje jen u zboží; změna typu shody zruší i vybraný cíl. */
function onMatchTypeChange(rule: RuleRow) {
  rule.match_id = null
  rule.match_label = null
  if (rule.match_type !== 'product' && rule.rule_type === 'fixed') rule.rule_type = 'discount_pct'
}

async function searchProducts(q: string) {
  const seq = ++productSearchSeq
  productLoading.value = true
  try {
    const rows = await stockApi.searchItems(q, 30)
    if (seq !== productSearchSeq) return
    productOptions.value = rows.map(r => ({ value: r.id, label: `${r.sku} - ${r.name}`, secondary: r.unit }))
  } catch {
    if (seq === productSearchSeq) productOptions.value = []
  } finally {
    if (seq === productSearchSeq) productLoading.value = false
  }
}

function onProductPick(rule: RuleRow, id: number | null) {
  rule.match_id = id
  rule.match_label = id === null ? null : (productOptions.value.find(o => o.value === id)?.label ?? rule.match_label)
}

function productSelected(rule: RuleRow): SelectOption | null {
  return rule.match_id ? { value: rule.match_id, label: rule.match_label ?? `#${rule.match_id}` } : null
}

function categoryLabel(c: Category): string {
  return c.path || c.name
}

/** Kategorie / výrobci k výběru: aktivní + právě vybraný (i archivovaný, ať z pravidla nezmizí). */
function targetChoices(rule: RuleRow): SelectOption[] {
  const list = rule.match_type === 'category'
    ? categories.value.map(c => ({ value: c.id, label: categoryLabel(c), archived: c.archived }))
    : manufacturers.value.map(m => ({ value: m.id, label: m.name, archived: m.archived }))
  const out: SelectOption[] = list.filter(o => !o.archived || o.value === rule.match_id).map(o => ({ value: o.value, label: o.label }))
  if (rule.match_id !== null && !out.some(o => o.value === rule.match_id)) {
    out.push({ value: rule.match_id, label: rule.match_label ?? `#${rule.match_id}` })
  }
  return out
}

function targetLabel(rule: RuleRow): string {
  if (rule.match_type === 'product') return rule.match_label ?? `#${rule.match_id}`
  return targetChoices(rule).find(o => o.value === rule.match_id)?.label ?? `#${rule.match_id}`
}

const isFixed = isFixedRow

function validateRules(): string | null {
  const seen = new Set<string>()
  for (const r of rules.value) {
    if (!r.match_id) return t('eshop.price_levels.target_required')
    const fixed = isFixed(r)
    const raw = normalizeDecimal(fixed ? r.fixed_price : r.discount_pct)
    const value = Number(raw)
    const invalidValue = fixed
      ? raw === '' || !Number.isFinite(value) || value < 0
      : invalidAdjustment(r.rule_type === 'markup_pct' ? 'markup' : 'discount', raw)
    if (invalidValue || !Number.isInteger(Number(r.priority))) {
      return t('eshop.price_levels.value_invalid')
    }
    if (fixed && !r.currency_code) return t('eshop.price_levels.currency_required')
    const key = ruleDuplicateKey(r)
    if (seen.has(key)) return t('eshop.price_levels.duplicate', { target: targetLabel(r) })
    seen.add(key)
  }
  return null
}

async function saveRules() {
  const level = rulesLevel.value
  if (!level || rulesLoadFailed.value) return
  rulesError.value = validateRules() ?? ''
  if (rulesError.value) return
  rulesSaving.value = true
  try {
    await eshopApi.replacePriceLevelRules(level.id, rules.value.map(rulePayload))
    toast.success(t('common.saved'))
    rulesLevel.value = null
    await load()
  } catch (e: any) {
    rulesError.value = mapError(e)
  } finally {
    rulesSaving.value = false
  }
}

const FIELD = 'w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface'
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('eshop.price_levels.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.price_levels.subtitle') }}</p>
      </div>
      <button v-if="canWrite" type="button" data-test="add-price-level" @click="openCreate" :class="btnFilled('primary')" class="whitespace-nowrap">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('eshop.price_levels.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="levels.length === 0" boxed icon="tag"
      :title="t('eshop.price_levels.empty_title')"
      :message="t('eshop.price_levels.empty_hint')"
      :cta="canWrite ? t('eshop.price_levels.new') : undefined"
      @action="openCreate" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.price_levels.col_name') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('eshop.price_levels.col_code') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('eshop.price_levels.col_default_discount') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('eshop.price_levels.col_clients') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('eshop.price_levels.col_rules') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('eshop.price_levels.col_active') }}</th>
              <th class="px-3 py-2 w-40"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="level in levels" :key="level.id" data-test="price-level" :class="{ 'opacity-50': !level.is_active }" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-medium">{{ level.name }}</td>
              <td class="px-3 py-2 font-mono text-neutral-600">{{ level.code }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatPct(level.default_discount_pct) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ level.client_count }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ level.rule_count }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="level.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ level.is_active ? t('common.yes') : t('common.no') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <div class="inline-flex flex-wrap items-center justify-end gap-1">
                  <button type="button" data-test="price-level-rules" @click="openRules(level)" :class="btnOutlineSm('primary')" class="whitespace-nowrap">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
                    {{ t('eshop.price_levels.rules') }}
                  </button>
                  <template v-if="canWrite">
                    <button type="button" @click="openEdit(level)" :title="t('common.edit')" class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">
                      <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                    </button>
                    <button type="button" @click="remove(level)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                      <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                    </button>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ─────────── Založit / upravit hladinu ─────────── -->
    <Modal v-if="modalOpen" :title="editing ? t('eshop.price_levels.edit') : t('eshop.price_levels.new')" widthClass="max-w-md" @close="modalOpen = false">
      <div class="space-y-3">
        <CodeNameFields
          v-model:code="form.code"
          v-model:name="form.name"
          :code-label="t('eshop.price_levels.field_code')"
          :name-label="t('eshop.price_levels.field_name')"
          :editing="!!editing"
          code-mode="code"
          :code-maxlength="50"
          :name-maxlength="100"
          :taken-codes="takenCodes"
          :code-hint="t('eshop.price_levels.code_hint')"
          name-testid="price-level-name"
          code-testid="price-level-code"
        />
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.price_levels.field_default_discount') }}</label>
          <div class="flex items-center gap-2">
            <select v-model="form.default_kind" data-test="price-level-default-kind" :aria-label="t('eshop.price_levels.adjustment_kind')"
              class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="discount">{{ t('eshop.price_levels.adjustment_discount') }}</option>
              <option value="markup">{{ t('eshop.price_levels.adjustment_markup') }}</option>
            </select>
            <input v-model="form.default_discount_pct" type="text" inputmode="decimal" class="w-32 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
            <span class="text-sm text-neutral-500">%</span>
          </div>
          <span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.price_levels.default_discount_hint') }}</span>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.price_levels.field_display_order') }}</label>
          <input v-model.number="form.display_order" type="number" step="1" class="w-32 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
        </div>
        <label class="inline-flex items-center gap-2 text-sm cursor-pointer pt-1">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('eshop.price_levels.field_active') }}
        </label>
        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
          <button type="button" @click="modalOpen = false" :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="save" :disabled="saving" :class="btnFilled('primary')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>
    </Modal>

    <!-- ─────────── Pravidla hladiny (jedno společné Uložit) ─────────── -->
    <Modal v-if="rulesLevel" :title="t('eshop.price_levels.rules_title', { name: rulesLevel.name })" widthClass="max-w-4xl" @close="closeRules">
      <div class="space-y-4">
        <p class="text-sm text-neutral-500">{{ t('eshop.price_levels.rules_subtitle') }}</p>

        <div v-if="rulesLoading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
        <p v-else-if="rulesLoadFailed" class="text-sm text-warning-600">{{ t('eshop.price_levels.rules_load_failed') }}</p>
        <template v-else>
          <EmptyState v-if="rules.length === 0" dense accent="neutral" icon="tag"
            :title="t('eshop.price_levels.rules_empty')" :message="t('eshop.price_levels.rules_empty_hint')" />

          <div v-else class="space-y-3">
            <div v-for="(rule, idx) in rules" :key="rule.key" data-test="price-level-rule" class="border border-neutral-200 rounded-md p-3">
              <div class="flex items-start gap-3">
                <div class="flex-1 min-w-0 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.price_levels.match_type') }}</label>
                    <select v-model="rule.match_type" @change="onMatchTypeChange(rule)" :disabled="!canWrite" :class="FIELD">
                      <option v-for="m in MATCH_TYPES" :key="m" :value="m">{{ t('eshop.price_levels.match_' + m) }}</option>
                    </select>
                  </div>
                  <div class="sm:col-span-2 lg:col-span-2 min-w-0">
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.price_levels.target') }} *</label>
                    <SearchableSelect v-if="rule.match_type === 'product'"
                      :model-value="rule.match_id"
                      remote teleport
                      :options="productOptions"
                      :loading="productLoading"
                      :selected-option="productSelected(rule)"
                      :disabled="!canWrite"
                      :placeholder="t('eshop.price_levels.search_product')"
                      :no-results-label="t('common.no_results')"
                      @search="searchProducts"
                      @update:model-value="(v) => onProductPick(rule, v as number | null)" />
                    <select v-else v-model="rule.match_id" :disabled="!canWrite" :class="FIELD">
                      <option :value="null" disabled>{{ rule.match_type === 'category' ? t('eshop.price_levels.select_category') : t('eshop.price_levels.select_manufacturer') }}</option>
                      <option v-for="o in targetChoices(rule)" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.price_levels.rule_type') }}</label>
                    <select v-model="rule.rule_type" :disabled="!canWrite" :class="FIELD">
                      <option v-for="rt in ruleKinds(rule)" :key="rt" :value="rt">{{ t('eshop.price_levels.rule_' + rt) }}</option>
                    </select>
                  </div>
                  <div v-if="isFixed(rule)" class="grid grid-cols-[minmax(0,1fr)_5.5rem] gap-2">
                    <div>
                      <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.price_levels.value_fixed') }}</label>
                      <input v-model="rule.fixed_price" type="text" inputmode="decimal" :disabled="!canWrite" :class="FIELD" class="font-mono text-right" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.price_levels.currency') }}</label>
                      <select v-model="rule.currency_code" :disabled="!canWrite" :class="FIELD" class="font-mono">
                        <option v-for="code in currencyChoices(rule.currency_code)" :key="code" :value="code">{{ code }}</option>
                      </select>
                    </div>
                  </div>
                  <div v-else>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t(rule.rule_type === 'markup_pct' ? 'eshop.price_levels.value_markup_pct' : 'eshop.price_levels.value_pct') }}</label>
                    <input v-model="rule.discount_pct" type="text" inputmode="decimal" :disabled="!canWrite" :class="FIELD" class="font-mono text-right" />
                    <span v-if="rule.pct_currency" class="mt-1 block text-xs text-neutral-500">{{ t('eshop.price_levels.only_currency', { currency: rule.pct_currency }) }}</span>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.price_levels.priority') }}</label>
                    <input v-model.number="rule.priority" type="number" step="1" :disabled="!canWrite" :class="FIELD" class="font-mono text-right" />
                  </div>
                </div>
                <button v-if="canWrite" type="button" @click="removeRule(idx)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1 pt-6">
                  <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </div>
            </div>
          </div>

          <button v-if="canWrite" type="button" data-test="add-price-level-rule" @click="addRule" :class="btnOutline('primary')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('eshop.price_levels.add_rule') }}
          </button>
        </template>

        <div v-if="rulesError && !rulesLoadFailed" class="text-sm text-danger-500" role="alert">{{ rulesError }}</div>
        <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
          <button type="button" @click="closeRules" :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ canWrite ? t('common.cancel') : t('common.close') }}
          </button>
          <button v-if="canWrite" type="button" data-test="save-price-level-rules" @click="saveRules" :disabled="rulesSaving || rulesLoading || rulesLoadFailed" :class="btnFilled('primary')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ rulesSaving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
