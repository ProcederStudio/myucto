<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DateInput from '@/components/ui/DateInput.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import type { DimensionType, DimensionValue } from '@/api/dimensions'
import { jointAxes, type JointCell, type JointVersion, type JointVersionInput } from '@/api/jointAllocations'

const props = defineProps<{ types: DimensionType[]; values: DimensionValue[]; versions: JointVersion[]; busy: boolean }>()
const emit = defineEmits<{ save: [input: { keyId: number; code: string; name: string; version: JointVersionInput }]; close: [] }>()
const { t } = useI18n()
const form = ref<HTMLFormElement | null>(null)
const keyId = ref(0)
const code = ref('')
const name = ref('')
const additional = ref<number[]>([])
const emptyCell = (): JointCell => ({ activity: 0, activity_kind: 0, tax_regime: 0, weight: '1', additional_dimensions: {} })
const version = ref<JointVersionInput>({ valid_from: '', valid_to: '', basis: 'fixed', explanation: '', allocation_mode: 'weighted', cells: [emptyCell()] })
const keys = computed(() => props.versions.filter((v, i, list) => list.findIndex(k => k.key_id === v.key_id) === i))
const customTypes = computed(() => props.types.filter(type => type.is_active && !jointAxes.includes(type.system_key as typeof jointAxes[number])))
const axisTypes = computed(() => jointAxes.map(axis => ({ axis, type: props.types.find(type => type.system_key === axis) })))
const choices = (typeId?: number) => props.values.filter(value => value.type_id === typeId && value.is_active)
function addCell() {
  const cell = emptyCell()
  cell.additional_dimensions = Object.fromEntries(additional.value.map(id => [id, 0]))
  version.value.cells.push(cell)
}
function setAxes() {
  for (const cell of version.value.cells) cell.additional_dimensions = Object.fromEntries(additional.value.map(id => [id, cell.additional_dimensions?.[id] ?? 0]))
}
function copy(source: JointVersion) {
  keyId.value = 0
  code.value = ''
  name.value = source.name
  version.value = { valid_from: source.valid_from, valid_to: source.valid_to, basis: source.basis, explanation: source.explanation,
    allocation_mode: source.allocation_mode, cells: JSON.parse(JSON.stringify(source.cells)) as JointCell[] }
  additional.value = Object.keys(source.cells[0]?.additional_dimensions ?? {}).map(Number)
}
function validate() { return !props.busy && form.value?.reportValidity() === true }
function save() {
  if (!validate()) return
  emit('save', { keyId: keyId.value, code: code.value, name: name.value, version: JSON.parse(JSON.stringify(version.value)) as JointVersionInput })
}
defineExpose({ copy, validate })
</script>

<template>
  <form ref="form" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-4" @submit.prevent="save">
    <h2 class="font-semibold">{{ t('jointAllocations.keyEditor') }}</h2>
    <p class="text-sm text-neutral-500">{{ t('jointAllocations.immutableKey') }}</p>
    <div class="flex flex-wrap gap-3">
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.key') }}
        <select v-model="keyId" class="h-9 rounded border border-neutral-300 bg-surface px-2" :disabled="busy">
          <option :value="0">{{ t('jointAllocations.newKey') }}</option>
          <option v-for="key in keys" :key="key.key_id" :value="key.key_id">{{ key.name }}</option>
        </select>
      </label>
      <template v-if="!keyId">
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.code') }}<input v-model="code" required maxlength="50" pattern="[A-Za-z0-9_.-]+" class="h-9 rounded border border-neutral-300 px-2 bg-surface" :disabled="busy" /></label>
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.name') }}<input v-model="name" required maxlength="190" class="h-9 rounded border border-neutral-300 px-2 bg-surface" :disabled="busy" /></label>
      </template>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.validFrom') }}<DateInput v-model="version.valid_from" required :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.validTo') }}<DateInput v-model="version.valid_to" required :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.basis') }}
        <select v-model="version.basis" class="h-9 rounded border border-neutral-300 bg-surface px-2" :disabled="busy">
          <option v-for="basis in ['hours', 'area', 'revenue', 'fixed']" :key="basis" :value="basis">{{ t(`jointAllocations.bases.${basis}`) }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.mode') }}
        <select v-model="version.allocation_mode" class="h-9 rounded border border-neutral-300 bg-surface px-2" :disabled="busy">
          <option value="weighted">{{ t('jointAllocations.weighted') }}</option><option value="explicit_amounts">{{ t('jointAllocations.explicit') }}</option>
        </select>
      </label>
    </div>
    <label class="block text-sm">{{ t('jointAllocations.explanation') }}<textarea v-model="version.explanation" required maxlength="4000" rows="2" class="mt-1 w-full rounded border border-neutral-300 bg-surface p-2" :disabled="busy" /></label>
    <fieldset class="flex flex-wrap gap-3 text-sm" :disabled="busy">
      <legend class="mb-2 font-medium">{{ t('jointAllocations.additionalAxes') }}</legend>
      <label v-for="type in customTypes" :key="type.id" class="flex items-center gap-2"><input v-model="additional" type="checkbox" :value="type.id" @change="setAxes" />{{ type.name }}</label>
    </fieldset>
    <p class="text-sm text-neutral-500">{{ t('jointAllocations.fullTupleHelp') }}</p>
    <div v-for="(cell, index) in version.cells" :key="index" class="flex flex-wrap items-end gap-3 border-t border-neutral-100 pt-3">
      <label v-for="axis in axisTypes" :key="axis.axis" class="flex flex-col gap-1 text-sm">{{ axis.type?.name ?? t(`jointAllocations.axes.${axis.axis}`) }}
        <select v-model="cell[axis.axis]" required :disabled="busy" class="h-9 rounded border border-neutral-300 bg-surface px-2">
          <option :value="0" disabled>{{ t('jointAllocations.choose') }}</option>
          <option v-for="value in choices(axis.type?.id)" :key="value.id" :value="value.id">{{ value.name }}</option>
        </select>
      </label>
      <template v-if="cell.additional_dimensions">
        <label v-for="typeId in additional" :key="typeId" class="flex flex-col gap-1 text-sm">{{ types.find(type => type.id === typeId)?.name }}
          <select v-model="cell.additional_dimensions[typeId]" required :disabled="busy" class="h-9 rounded border border-neutral-300 bg-surface px-2">
            <option :value="0" disabled>{{ t('jointAllocations.choose') }}</option>
            <option v-for="value in choices(typeId)" :key="value.id" :value="value.id">{{ value.name }}</option>
          </select>
        </label>
      </template>
      <label v-if="version.allocation_mode === 'weighted'" class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.weight') }}<input v-model="cell.weight" required inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?" :disabled="busy" class="h-9 w-24 rounded border border-neutral-300 bg-surface px-2" /></label>
      <button type="button" :class="btnOutline('danger')" :disabled="busy || version.cells.length === 1" @click="version.cells.splice(index, 1)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('jointAllocations.removeCell') }}</button>
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="btnOutline('primary')" :disabled="busy || version.cells.length >= 200" @click="addCell"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('jointAllocations.addCell') }}</button>
      <button type="submit" :class="btnFilled('success')" :disabled="busy"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('jointAllocations.saveVersion') }}</button>
      <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="emit('close')"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.x" /></svg>{{ t('jointAllocations.close') }}</button>
    </div>
  </form>
</template>
