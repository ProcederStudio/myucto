<script setup lang="ts">
import { reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DateInput from '@/components/ui/DateInput.vue'
import { propertyControl as control } from './propertyFormStyles'

const props = withDefaults(defineProps<{ modelValue: string; label: string; required?: boolean }>(), { required: false })
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const { t } = useI18n()
const parts = reactive({ date: '', time: '', offset: 'Z' })
let emitted = ''
watch(() => props.modelValue, value => {
  if (value === emitted) return
  const match = value.match(/^(\d{4}-\d{2}-\d{2})T([0-9:.]+)(Z|[+-]\d{2}:\d{2})$/)
  parts.date = match?.[1] ?? ''
  parts.time = match?.[2] ?? ''
  parts.offset = match?.[3] ?? 'Z'
}, { immediate: true })
function change(part: number, value: string) {
  if (part === 1) parts.date = value
  if (part === 2) parts.time = value.length === 5 ? `${value}:00` : value
  if (part === 3) parts.offset = value
  emitted = parts.date || parts.time ? `${parts.date}T${parts.time}${parts.offset}` : ''
  emit('update:modelValue', emitted)
}
</script>

<template>
  <fieldset class="space-y-1 min-w-0">
    <legend class="text-sm font-medium">{{ label }}</legend>
    <div class="flex min-w-0 flex-wrap gap-2">
      <DateInput :model-value="parts.date" :class="[control, 'w-40']" :required="required || !!parts.time" :aria-label="label" @update:model-value="change(1, $event ?? '')" />
      <input type="time" step="0.000001" :value="parts.time" :required="required || !!parts.date" :class="[control, 'w-36']" :aria-label="t('propertyRegistry.time')" @input="change(2, ($event.target as HTMLInputElement).value)">
      <input :value="parts.offset" :required="required || !!parts.date || !!parts.time" pattern="Z|[+\-](0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]" :class="[control, 'w-24']" :aria-label="t('propertyRegistry.offset')" placeholder="+02:00" @input="change(3, ($event.target as HTMLInputElement).value)">
    </div>
    <p class="text-xs text-neutral-500">{{ t('propertyRegistry.instantHelp') }}</p>
  </fieldset>
</template>
