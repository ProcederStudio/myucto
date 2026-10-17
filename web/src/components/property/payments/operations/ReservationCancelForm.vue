<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Reservation } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { useWriteFlow } from './flows'
import { moneyText } from './format'
import { inputClass, subBoxClass } from './ui'
const props = defineProps<{ supplier: number; reservation: Reservation; mode: 'cancel' | 'submission'; canManage: boolean }>()
const emit = defineEmits<{ changed: []; close: [] }>()
const { t } = useI18n()
const reason = ref('')
const evidence = ref('')
const form = ref<HTMLFormElement | null>(null)
const flow = useWriteFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false, canWrite: () => props.canManage,
  build: () => ({ reason: reason.value, evidence: evidence.value, version: props.reservation.row_version }),
  write: (supplier, request, token) => props.mode === 'cancel'
    ? propertyPaymentOperationsApi.cancelReservation(supplier, props.reservation.id, request.version, request.reason, token)
    : propertyPaymentOperationsApi.cancelSubmission(supplier, props.reservation.id, request.version, request.reason, request.evidence, token),
  onDone: () => { emit('changed'); emit('close') },
})
const unknown = () => t('propertyPayments.unknown')
const help = () => props.mode === 'submission'
  ? t('propertyPayments.reservation.submissionHelp', { amount: moneyText(props.reservation.remaining_cents, unknown()) })
  : props.reservation.paid_cents !== '0'
    ? t('propertyPayments.reservation.partialCancelHelp', { amount: moneyText(props.reservation.remaining_cents, unknown()) })
    : t('propertyPayments.reservation.cancelHelp')
const actions = () => [{ key: 'cancel-submit', label: t(props.mode === 'cancel' ? 'propertyPayments.reservation.cancelSubmit' : 'propertyPayments.reservation.submissionSubmit'), icon: 'x', variant: 'danger', tier: 'primary', disabled: flow.busy.value || !props.canManage, run: () => { void flow.submit() } }] as ActionItem[]
</script>

<template>
  <form ref="form" :class="subBoxClass" :data-section="mode === 'cancel' ? 'reservation-cancel' : 'submission-cancel'" @submit.prevent="flow.submit">
    <fieldset :disabled="flow.busy.value || !canManage" class="space-y-2">
      <p class="text-sm">{{ help() }}</p>
      <label class="block">{{ t('propertyPayments.reason') }}<input v-model="reason" required :class="inputClass" data-field="cancel-reason"></label>
      <label v-if="mode === 'submission'" class="block">{{ t('propertyPayments.reservation.submissionEvidence') }}<input v-model="evidence" required :class="inputClass" data-field="cancel-evidence"></label>
    </fieldset>
    <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
    <ActionBar :actions="actions()" />
  </form>
</template>
