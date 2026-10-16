<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { InvoiceItemTrackingAllocation } from '@/api/invoices'
import { formatDate, formatNumber } from '@/composables/useFormat'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'
import {
  anyExceedsAvailable,
  exceedsAvailable,
  freeTextAllocation,
  isSelected,
  selectedQuantity,
  selectedTotal,
  selectionState,
  setLotQuantity,
  toggleSerial,
  trackingKey,
  type TrackingChoice,
  type TrackingMode,
} from './invoiceTracking'

/**
 * Výběr sériových čísel nebo šarží u řádku faktury se sledovanou kartou.
 * `kind`: prodej (kusy skladem) nebo vratka (kusy, které vydala původní faktura; bez ní
 * ruční zadání). Koncept smí mít výběr neúplný; vystavení ho vyžaduje v plném množství
 * řádku (v základní jednotce). Vystavená faktura výběr jen ukazuje.
 */
const props = defineProps<{
  mode: Exclude<TrackingMode, 'none'>
  kind: 'sale' | 'return'
  choices: TrackingChoice[]
  modelValue: InvoiceItemTrackingAllocation[]
  /** Množství řádku v základní jednotce karty (dobropis záporné). */
  requiredBase: number
  baseUnit: string
  loading?: boolean
  error?: boolean
  readonly?: boolean
  /** Vratka bez původní faktury: kus se zadá ručně. */
  allowFreeText?: boolean
  /** Doklad, u kterého vystavení výběr vyžaduje (faktura, dobropis). */
  requiredOnIssue?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [value: InvoiceItemTrackingAllocation[]]; retry: [] }>()
const { t } = useI18n()
const groupId = useId()

const state = computed(() => selectionState(props.modelValue, props.requiredBase))
const exceeds = computed(() => anyExceedsAvailable(props.modelValue, props.choices))
const summary = computed(() => t('invoice.tracking.selected', {
  selected: formatNumber(selectedTotal(props.modelValue), { maximumFractionDigits: 3 }),
  required: formatNumber(Math.abs(props.requiredBase), { maximumFractionDigits: 3 }),
  unit: props.baseUnit,
}))
const stateClass = computed(() => exceeds.value
  ? 'bg-danger-50 text-danger-600'
  : {
      none: 'bg-warning-50 text-warning-700',
      incomplete: 'bg-warning-50 text-warning-700',
      over: 'bg-danger-50 text-danger-600',
      complete: 'bg-success-50 text-success-600',
    }[state.value])

function lotLabel(c: { lot_code: string | null; expires_on: string | null }): string {
  return c.expires_on ? t('invoice.tracking.lot_with_expiry', { lot: c.lot_code, expires: formatDate(c.expires_on) }) : (c.lot_code ?? '')
}
function availableLabel(c: TrackingChoice): string {
  if (c.available > 0) {
    return t(props.kind === 'return' ? 'invoice.tracking.returnable' : 'invoice.tracking.available', {
      qty: formatNumber(c.available, { maximumFractionDigits: 3 }), unit: props.baseUnit,
    })
  }
  if (c.takenElsewhere > 0) return t('invoice.tracking.taken_elsewhere')
  return t(props.kind === 'return' ? 'invoice.tracking.not_returnable' : 'invoice.tracking.not_in_stock')
}
/** U vratky bez původní faktury není s čím porovnat, kus se nehlídá proti zdroji. */
function overLimit(c: TrackingChoice): boolean {
  return !props.allowFreeText && exceedsAvailable(props.modelValue, c)
}

function onLotChange(c: TrackingChoice, input: HTMLInputElement) {
  const next = setLotQuantity(props.modelValue, c, input.value)
  if (next === null) {
    input.value = selectedQuantity(props.modelValue, c)
    return
  }
  emit('update:modelValue', next)
}

const freeIdentity = ref('')
const freeQuantity = ref('')
const freeError = ref(false)
function addFreeText() {
  const allocation = freeTextAllocation(props.mode, freeIdentity.value, freeQuantity.value)
  if (allocation === null || props.modelValue.some(s => trackingKey(s) === trackingKey(allocation))) {
    freeError.value = true
    return
  }
  freeError.value = false
  emit('update:modelValue', [...props.modelValue, allocation])
  freeIdentity.value = ''
  freeQuantity.value = ''
}
function removeSelected(s: InvoiceItemTrackingAllocation) {
  emit('update:modelValue', props.modelValue.filter(x => trackingKey(x) !== trackingKey(s)))
}
</script>

<template>
  <div class="mt-1.5 rounded-md border border-neutral-200 bg-neutral-50 px-2.5 py-2 text-xs"
    role="group" :aria-labelledby="`${groupId}-title`" data-test="invoice-tracking-picker">
    <div class="flex flex-wrap items-center gap-2">
      <span :id="`${groupId}-title`" class="font-medium text-neutral-600">
        {{ t(mode === 'serial' ? 'invoice.tracking.serials' : 'invoice.tracking.lots') }}{{ kind === 'return' ? ` · ${t('invoice.tracking.return')}` : '' }}
      </span>
      <!-- Faktura vystavená před evidencí kusů výběr nemá; varování by na ní bylo jen šum. -->
      <span v-if="!readonly || modelValue.length" class="rounded px-1.5 py-0.5 font-medium" :class="stateClass" aria-live="polite" data-test="invoice-tracking-state">{{ summary }}</span>
      <span v-if="!readonly && requiredOnIssue && state !== 'complete'" class="text-neutral-500">{{ t('invoice.tracking.required_on_issue') }}</span>
    </div>

    <!-- Vystavená faktura: výběr se po výdeji nemění (backend tracking_locked), jen se ukáže. -->
    <p v-if="readonly" class="mt-1.5 font-mono text-neutral-700" data-test="invoice-tracking-readonly">
      <template v-if="modelValue.length">
        {{ modelValue.map(s => s.serial_number ?? `${lotLabel(s)}: ${formatNumber(Number(s.quantity), { maximumFractionDigits: 3 })} ${baseUnit}`).join(', ') }}
      </template>
      <span v-else class="font-sans text-neutral-500">{{ t('invoice.tracking.none_selected') }}</span>
    </p>

    <template v-else>
      <p v-if="loading" class="mt-1.5 text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="error" class="mt-1.5 flex flex-wrap items-center gap-2 text-danger-600">
        {{ t('invoice.tracking.load_failed') }}
        <button type="button" :class="btnOutlineSm('neutral')" @click="emit('retry')">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('invoice.tracking.retry') }}
        </button>
      </p>
      <p v-else-if="choices.length === 0 && !allowFreeText" class="mt-1.5 text-neutral-500">
        {{ t(kind === 'return' ? 'invoice.tracking.empty_return' : 'invoice.tracking.empty') }}
      </p>

      <div v-else-if="mode === 'serial' && choices.length" class="mt-1.5 flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
        <label v-for="c in choices" :key="c.key"
          class="inline-flex items-center gap-1.5 rounded border px-2 py-1 font-mono"
          :class="isSelected(modelValue, c)
            ? (overLimit(c) ? 'border-danger-500 bg-danger-50 text-danger-600' : 'border-primary-500 bg-primary-50 text-primary-700')
            : (c.available > 0 || allowFreeText ? 'cursor-pointer border-neutral-300 bg-surface text-neutral-700' : 'border-neutral-200 bg-neutral-100 text-neutral-400')">
          <input type="checkbox" class="rounded border-neutral-300 text-primary-600"
            :checked="isSelected(modelValue, c)" :disabled="!isSelected(modelValue, c) && c.available <= 0 && !allowFreeText"
            @change="emit('update:modelValue', toggleSerial(modelValue, c))" />
          <span>{{ c.serial_number }}</span>
          <span v-if="c.available <= 0" class="font-sans" :class="isSelected(modelValue, c) && overLimit(c) ? 'text-danger-600' : 'text-neutral-500'">{{ availableLabel(c) }}</span>
        </label>
      </div>

      <div v-else-if="choices.length" class="mt-1.5 max-h-40 space-y-1 overflow-y-auto">
        <div v-for="c in choices" :key="c.key" class="flex flex-wrap items-center gap-2">
          <span class="min-w-0 flex-1 font-mono text-neutral-700">{{ lotLabel(c) }}</span>
          <span class="whitespace-nowrap" :class="c.available > 0 ? 'text-neutral-500' : 'text-danger-600'">{{ availableLabel(c) }}</span>
          <input type="text" inputmode="decimal" :value="selectedQuantity(modelValue, c)"
            :aria-label="t('invoice.tracking.lot_quantity', { lot: lotLabel(c) })"
            :aria-invalid="overLimit(c) ? 'true' : undefined"
            :aria-describedby="overLimit(c) ? `${groupId}-${c.key}-over` : undefined"
            @change="onLotChange(c, $event.target as HTMLInputElement)"
            class="h-8 w-20 rounded-md border bg-surface px-2 text-right font-mono"
            :class="overLimit(c) ? 'border-danger-500 text-danger-600' : 'border-neutral-300'" />
          <span v-if="overLimit(c)" :id="`${groupId}-${c.key}-over`" class="basis-full text-right text-danger-600">
            {{ t(kind === 'return' ? 'invoice.tracking.over_returnable' : 'invoice.tracking.over_available') }}
          </span>
        </div>
      </div>

      <!-- Vratka bez původní faktury: kus se zadá ručně (u sériového čísla jen číslo). -->
      <div v-if="allowFreeText" class="mt-1.5 space-y-1.5">
        <div v-if="mode === 'lot' && modelValue.length" class="flex flex-wrap gap-1.5">
          <span v-for="s in modelValue" :key="trackingKey(s)" class="inline-flex items-center gap-1 rounded border border-primary-500 bg-primary-50 px-2 py-1 font-mono text-primary-700">
            {{ lotLabel(s) }}: {{ formatNumber(Number(s.quantity), { maximumFractionDigits: 3 }) }} {{ baseUnit }}
            <button type="button" class="inline-flex cursor-pointer items-center rounded text-neutral-500 hover:text-danger-600"
              :aria-label="t('common.delete')" :title="t('common.delete')" @click="removeSelected(s)">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            </button>
          </span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <input v-model="freeIdentity" type="text"
            :aria-label="t(mode === 'serial' ? 'invoice.tracking.free_serial' : 'invoice.tracking.free_lot')"
            :placeholder="t(mode === 'serial' ? 'invoice.tracking.free_serial' : 'invoice.tracking.free_lot')"
            @keydown.enter.prevent="addFreeText"
            class="h-8 min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-2 font-mono" />
          <input v-if="mode === 'lot'" v-model="freeQuantity" type="text" inputmode="decimal"
            :aria-label="t('invoice.tracking.free_quantity')" :placeholder="baseUnit"
            @keydown.enter.prevent="addFreeText"
            class="h-8 w-20 rounded-md border border-neutral-300 bg-surface px-2 text-right font-mono" />
          <button type="button" :class="btnOutlineSm('primary')" @click="addFreeText">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('invoice.tracking.add') }}
          </button>
        </div>
        <p v-if="freeError" class="text-danger-600">{{ t('invoice.tracking.free_invalid') }}</p>
        <p class="text-neutral-500">{{ t('invoice.tracking.free_hint') }}</p>
      </div>
    </template>
  </div>
</template>
