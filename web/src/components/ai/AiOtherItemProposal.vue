<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { otherItemsApi } from '@/api/otherItems'
import { otherItemPlansApi } from '@/api/otherItemPlans'
import { documentsApi } from '@/api/documents'
import { aiErrorMessage, type AiOtherItem } from '@/api/aiAssistant'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE } from '@/components/ui/buttonStyles'

/**
 * Návrh ostatní pohledávky / závazku od AI. Zakládá se existující cestou
 * (POST /accounting/other-items) jako KONCEPT — nic se tím nezaúčtuje.
 */
const props = withDefaults(defineProps<{
  item: AiOtherItem
  linkDocumentIds?: number[]
  /** Návrh už byl použit dřív (historie konverzace) — id vzniklého záznamu. */
  appliedId?: number | null
}>(), { linkDocumentIds: () => [], appliedId: null })

const emit = defineEmits<{ created: [itemId: number] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const creating = ref(false)
const createdId = ref<number | null>(props.appliedId ?? null)
watch(() => props.appliedId, v => { if (v) createdId.value = v })

const unknown = computed(() => props.item.unknown_accounts ?? [])
const installments = computed(() => props.item.installments ?? [])
const installmentsSum = computed(() => installments.value.reduce((s, i) => s + Number(i.amount || 0), 0))
const kindLabel = computed(() => {
  const key = `other_items.kind_by_side.${props.item.side}.${props.item.kind}`
  const v = t(key)
  return v === key ? props.item.kind : v
})

const blockReason = computed<string | null>(() =>
  auth.canWrite('other_items') ? null : t('accounting.ai_assistant.other_item.no_permission'))

async function create() {
  if (blockReason.value || creating.value || createdId.value) return
  if (blockDemoMutation()) return
  if (!window.confirm(t('accounting.ai_assistant.other_item.confirm'))) return
  creating.value = true
  try {
    const it = props.item
    const created = await otherItemsApi.create({
      side: it.side,
      kind: it.kind,
      title: it.title,
      partner_id: it.partner_id,
      partner_name: it.partner_name,
      issued_on: it.issued_on,
      accounting_on: it.issued_on,
      due_on: it.due_on,
      currency: it.currency || 'CZK',
      exchange_rate: null,
      amount: Number(it.amount),
      variable_symbol: it.variable_symbol,
      account_code: it.account_code,
      counter_account_code: it.counter_account_code,
      note: it.note,
    })
    const newId = Number(created.id)
    createdId.value = newId
    if (installments.value.length >= 2 && it.installments_valid) {
      try {
        await otherItemPlansApi.setInstallments(newId, installments.value.map(i => ({ due_on: i.due_on, amount: Number(i.amount) })))
      } catch (e) {
        toast.warning(aiErrorMessage(e, t('accounting.ai_assistant.other_item.installments_failed')))
      }
    }
    const links = await Promise.allSettled(props.linkDocumentIds.map(id => documentsApi.addLink(id, 'other_item', newId)))
    if (links.some(r => r.status === 'rejected')) toast.warning(t('accounting.ai_assistant.other_item.link_failed'))
    toast.success(t('accounting.ai_assistant.other_item.created', { id: newId }))
    emit('created', newId)
  } catch (e) {
    toast.error(aiErrorMessage(e, t('common.error')))
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <div class="border border-neutral-200 rounded-lg bg-surface overflow-hidden" data-test="ai-other-item-proposal">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 bg-neutral-50 border-b border-neutral-200">
      <span class="text-xs font-semibold uppercase tracking-wide whitespace-nowrap" :class="item.side === 'receivable' ? 'text-success-700' : 'text-warning-700'">{{ kindLabel }}</span>
      <span class="text-sm text-neutral-800 font-medium min-w-0 break-words">{{ item.title || '—' }}</span>
      <span class="ml-auto text-sm font-semibold tabular-nums whitespace-nowrap">{{ formatMoney(item.amount, item.currency || 'CZK') }}</span>
    </div>

    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 px-3 py-2 text-sm">
      <div class="sm:col-span-2">
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.other_item.partner') }}</dt>
        <dd class="text-neutral-800 break-words">
          {{ item.partner_name || '—' }}<span v-if="item.partner_ico" class="text-neutral-500"> · {{ t('accounting.ai_assistant.other_item.ico') }} {{ item.partner_ico }}</span>
          <span v-if="item.partner_id" class="ml-1 text-xs text-success-700 whitespace-nowrap">{{ t('accounting.ai_assistant.other_item.partner_matched') }}</span>
        </dd>
      </div>
      <div>
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.other_item.issued_on') }}</dt>
        <dd class="text-neutral-800">{{ formatDate(item.issued_on) }}</dd>
      </div>
      <div>
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.other_item.due_on') }}</dt>
        <dd class="text-neutral-800">{{ formatDate(item.due_on) }}</dd>
      </div>
      <div class="sm:col-span-2">
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.other_item.accounts') }}</dt>
        <dd class="text-neutral-800 break-words">
          <span class="font-mono" :class="item.account_code && unknown.includes(item.account_code) ? 'text-danger-600 font-semibold' : ''">{{ item.account_code || '—' }}</span>
          <span v-if="item.account_name" class="text-neutral-600">&nbsp;{{ item.account_name }}</span>
          <span class="text-neutral-400"> → </span>
          <span class="font-mono" :class="item.counter_account_code && unknown.includes(item.counter_account_code) ? 'text-danger-600 font-semibold' : ''">{{ item.counter_account_code || '—' }}</span>
          <span v-if="item.counter_account_name" class="text-neutral-600">&nbsp;{{ item.counter_account_name }}</span>
        </dd>
      </div>
      <div v-if="item.variable_symbol">
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.other_item.variable_symbol') }}</dt>
        <dd class="text-neutral-800 font-mono">{{ item.variable_symbol }}</dd>
      </div>
      <div v-if="item.note" class="sm:col-span-2">
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.other_item.note') }}</dt>
        <dd class="text-neutral-800 whitespace-pre-wrap break-words">{{ item.note }}</dd>
      </div>
    </dl>

    <div v-if="installments.length" class="overflow-x-auto border-t border-neutral-100">
      <table class="w-full text-sm">
        <thead class="text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-1.5 text-left font-medium w-12">{{ t('accounting.ai_assistant.other_item.installment_no') }}</th>
            <th class="px-3 py-1.5 text-left font-medium">{{ t('accounting.ai_assistant.other_item.installment_due') }}</th>
            <th class="px-3 py-1.5 text-right font-medium">{{ t('accounting.ai_assistant.other_item.installment_amount') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(ins, i) in installments" :key="i" class="border-t border-neutral-100">
            <td class="px-3 py-1 text-neutral-500 tabular-nums">{{ i + 1 }}.</td>
            <td class="px-3 py-1 whitespace-nowrap">{{ formatDate(ins.due_on) }}</td>
            <td class="px-3 py-1 text-right tabular-nums whitespace-nowrap">{{ formatMoney(ins.amount, item.currency || 'CZK') }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-neutral-200 font-medium">
            <td class="px-3 py-1.5 text-neutral-500" colspan="2">{{ t('accounting.ai_assistant.other_item.installments_sum') }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ formatMoney(installmentsSum, item.currency || 'CZK') }}</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div v-if="(installments.length && !item.installments_valid) || unknown.length" class="px-3 py-2 space-y-1 border-t border-neutral-100">
      <p v-if="installments.length && !item.installments_valid" class="text-xs text-warning-700">{{ t('accounting.ai_assistant.other_item.installments_invalid') }}</p>
      <p v-if="unknown.length" class="text-xs text-danger-600">{{ t('accounting.ai_assistant.proposal.unknown_accounts', { codes: unknown.join(', ') }) }}</p>
    </div>

    <div class="px-3 py-2 border-t border-neutral-100 space-y-1.5">
      <div class="flex flex-wrap items-center gap-2">
        <template v-if="createdId">
          <span class="inline-flex items-center gap-1.5 text-sm text-success-700 whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
            {{ t('accounting.ai_assistant.other_item.created_label', { id: createdId }) }}
          </span>
          <RouterLink :to="`/other-items/${createdId}`" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
            {{ t('accounting.ai_assistant.other_item.open') }}
          </RouterLink>
        </template>
        <button v-else type="button" :class="btnFilled('primary')" :disabled="!!blockReason || creating"
          :title="disabledTitle(!!blockReason, blockReason)" data-test="ai-other-item-create" @click="create">
          <svg v-if="creating" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
          <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.ai_assistant.other_item.create') }}
        </button>
      </div>
      <p v-if="blockReason && !createdId" :class="BTN_DISABLED_NOTE">{{ blockReason }}</p>
      <p v-else-if="!createdId" class="text-xs text-neutral-400">{{ t('accounting.ai_assistant.other_item.draft_hint') }}</p>
    </div>
  </div>
</template>
