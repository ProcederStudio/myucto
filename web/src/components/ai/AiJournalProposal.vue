<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { accountingApi } from '@/api/accounting'
import { documentsApi } from '@/api/documents'
import { aiErrorMessage, type AiJournalEntry, type AiManualDraft } from '@/api/aiAssistant'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE } from '@/components/ui/buttonStyles'

/**
 * Návrh zápisu do deníku od AI. Zaúčtuje se existující cestou ručního zápisu
 * (POST /accounting/journal) — návrh nemá vlastní endpoint ani výjimku v právech.
 * Dokumenty z `linkDocumentIds` se na vzniklý zápis navážou až po úspěchu.
 */
const props = withDefaults(defineProps<{
  entry: AiJournalEntry
  linkDocumentIds?: number[]
  /** Návrh už byl použit dřív (historie konverzace) — id vzniklého záznamu. */
  appliedId?: number | null
}>(), { linkDocumentIds: () => [], appliedId: null })

const emit = defineEmits<{ posted: [entryId: number] }>()

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const posting = ref(false)
const postedId = ref<number | null>(props.appliedId ?? null)
watch(() => props.appliedId, v => { if (v) postedId.value = v })

const canWrite = computed(() => auth.canWrite('accounting.journal.write'))
const totalDebit = computed(() => props.entry.lines.filter(l => l.side === 'debit').reduce((s, l) => s + Number(l.amount || 0), 0))
const totalCredit = computed(() => props.entry.lines.filter(l => l.side === 'credit').reduce((s, l) => s + Number(l.amount || 0), 0))
const unknown = computed(() => props.entry.unknown_accounts ?? [])

const blockReason = computed<string | null>(() => {
  if (!canWrite.value) return t('accounting.ai_assistant.proposal.no_permission')
  if (!props.entry.balanced) return t('accounting.ai_assistant.proposal.not_balanced_reason')
  if (unknown.value.length) return t('accounting.ai_assistant.proposal.unknown_reason')
  if (!props.entry.lines.length) return t('accounting.ai_assistant.proposal.no_lines')
  return null
})

async function post() {
  if (blockReason.value || posting.value || postedId.value) return
  if (blockDemoMutation()) return
  if (!window.confirm(t('accounting.ai_assistant.proposal.confirm'))) return
  posting.value = true
  try {
    const created = await accountingApi.createEntry({
      entry_date: props.entry.entry_date,
      description: props.entry.description || undefined,
      document_no: props.entry.document_no || undefined,
      lines: props.entry.lines.map(l => ({
        account_code: l.account_code,
        side: l.side,
        amount: Number(l.amount),
      })),
    })
    postedId.value = created.id
    toast.success(t('accounting.ai_assistant.proposal.posted', { id: created.id }))
    let linkFailed = false
    for (const docId of props.linkDocumentIds) {
      try {
        await documentsApi.addLink(docId, 'journal_entry', created.id)
      } catch {
        linkFailed = true
      }
    }
    if (linkFailed) toast.warning(t('accounting.ai_assistant.proposal.link_failed'))
    emit('posted', created.id)
  } catch (e) {
    toast.error(aiErrorMessage(e, t('common.error')))
  } finally {
    posting.value = false
  }
}

function openInManualEntry() {
  const aiDraft: AiManualDraft = {
    entry_date: props.entry.entry_date,
    description: props.entry.description ?? '',
    document_no: props.entry.document_no ?? '',
    lines: props.entry.lines.map(l => ({ account_code: l.account_code, side: l.side, amount: Number(l.amount) })),
    document_ids: [...props.linkDocumentIds],
  }
  router.push({ name: 'accounting-journal-new', state: { aiDraft: JSON.parse(JSON.stringify(aiDraft)) } })
}
</script>

<template>
  <div class="border border-neutral-200 rounded-lg bg-surface overflow-hidden" data-test="ai-journal-proposal">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 bg-neutral-50 border-b border-neutral-200">
      <span class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.ai_assistant.proposal.title') }}</span>
      <span class="text-sm text-neutral-800 font-medium min-w-0 break-words">{{ entry.description || '—' }}</span>
      <span class="text-xs text-neutral-500 whitespace-nowrap">{{ formatDate(entry.entry_date) }}</span>
      <span v-if="entry.document_no" class="text-xs text-neutral-500 whitespace-nowrap">{{ t('accounting.ai_assistant.proposal.document_no') }}: {{ entry.document_no }}</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-1.5 text-left font-medium">{{ t('accounting.ai_assistant.proposal.account') }}</th>
            <th class="px-3 py-1.5 text-right font-medium whitespace-nowrap">{{ t('accounting.ai_assistant.proposal.debit') }}</th>
            <th class="px-3 py-1.5 text-right font-medium whitespace-nowrap">{{ t('accounting.ai_assistant.proposal.credit') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in entry.lines" :key="i" class="border-t border-neutral-100">
            <td class="px-3 py-1.5">
              <span class="font-mono" :class="unknown.includes(l.account_code) ? 'text-danger-600 font-semibold' : 'text-neutral-800'">{{ l.account_code }}</span>
              <span v-if="l.account_name" class="text-neutral-600"> · {{ l.account_name }}</span>
              <span v-if="l.note" class="block text-xs text-neutral-400">{{ l.note }}</span>
            </td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ l.side === 'debit' ? formatMoney(l.amount) : '' }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ l.side === 'credit' ? formatMoney(l.amount) : '' }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-neutral-200 font-medium">
            <td class="px-3 py-1.5 text-neutral-500">{{ t('accounting.ai_assistant.proposal.total') }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ formatMoney(totalDebit) }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ formatMoney(totalCredit) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div v-if="!entry.balanced || unknown.length" class="px-3 py-2 space-y-1 border-t border-neutral-100">
      <p v-if="!entry.balanced" class="text-xs text-warning-700">{{ t('accounting.ai_assistant.proposal.not_balanced') }}</p>
      <p v-if="unknown.length" class="text-xs text-danger-600">{{ t('accounting.ai_assistant.proposal.unknown_accounts', { codes: unknown.join(', ') }) }}</p>
    </div>

    <div class="px-3 py-2 border-t border-neutral-100 space-y-1.5">
      <div class="flex flex-wrap items-center gap-2">
        <template v-if="postedId">
          <span class="inline-flex items-center gap-1.5 text-sm text-success-700 whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
            {{ t('accounting.ai_assistant.proposal.posted_label', { id: postedId }) }}
          </span>
          <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(postedId) } }" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
            {{ t('accounting.ai_assistant.proposal.open_entry') }}
          </RouterLink>
        </template>
        <template v-else>
          <button type="button" :class="btnFilled('success')" :disabled="!!blockReason || posting"
            :title="disabledTitle(!!blockReason, blockReason)" data-test="ai-proposal-post" @click="post">
            <svg v-if="posting" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('accounting.ai_assistant.proposal.post') }}
          </button>
          <button v-if="canWrite" type="button" :class="btnOutline('primary')" data-test="ai-proposal-edit" @click="openInManualEntry">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
            {{ t('accounting.ai_assistant.proposal.edit') }}
          </button>
        </template>
      </div>
      <p v-if="blockReason && !postedId" :class="BTN_DISABLED_NOTE">{{ blockReason }}</p>
    </div>
  </div>
</template>
