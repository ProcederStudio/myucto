<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { aiAssistantApi, aiErrorMessage, type AiDocumentAnalysis } from '@/api/aiAssistant'
import type { DocItem } from '@/api/documents'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDateTime } from '@/composables/useFormat'
import { ICONS, btnOutline, btnOutlineSm, btnFilled } from '@/components/ui/buttonStyles'
import AiMarkdown from '@/components/ai/AiMarkdown.vue'
import AiJournalProposal from '@/components/ai/AiJournalProposal.vue'
import AiOtherItemProposal from '@/components/ai/AiOtherItemProposal.vue'

const props = defineProps<{ doc: DocItem }>()
const emit = defineEmits<{ posted: [entryId: number] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const summaryShort = ref<string | null>(null)
const summaryLong = ref<string | null>(null)
const summaryAt = ref<string | null>(null)
const analysis = ref<AiDocumentAnalysis | null>(null)
const summaryBusy = ref<'short' | 'long' | null>(null)
const analysisBusy = ref(false)
const detail = ref<'short' | 'long'>('short')
const withAccounting = ref(false)
const shownSummary = ref<'short' | 'long'>('short')

/** null = neznámo (bez práva na stav AI) — tlačítka zůstávají, chybu řekne server. */
const aiAvailable = ref<boolean | null>(null)
const canWrite = computed(() => auth.canWrite('documents'))
const hidden = computed(() => aiAvailable.value === false
  && !summaryShort.value && !summaryLong.value && !analysis.value)

function syncFromDoc(d: DocItem) {
  summaryShort.value = d.ai_summary_short ?? null
  summaryLong.value = d.ai_summary_long ?? null
  summaryAt.value = d.ai_summary_at ?? null
  analysis.value = d.ai_analysis ?? null
  shownSummary.value = summaryShort.value || !summaryLong.value ? 'short' : 'long'
  if (analysis.value) {
    detail.value = analysis.value.detail
    withAccounting.value = analysis.value.accounting
  }
}

async function generateSummary(length: 'short' | 'long') {
  if (summaryBusy.value || blockDemoMutation()) return
  summaryBusy.value = length
  try {
    const res = await aiAssistantApi.documentSummary(props.doc.id, length)
    summaryShort.value = res.ai_summary_short
    summaryLong.value = res.ai_summary_long
    summaryAt.value = res.ai_summary_at
    shownSummary.value = length
    toast.success(t('documents.ai.summary_saved'))
  } catch (e) {
    toast.error(aiErrorMessage(e, t('documents.ai.failed')))
  } finally {
    summaryBusy.value = null
  }
}

async function runAnalysis() {
  if (analysisBusy.value || blockDemoMutation()) return
  analysisBusy.value = true
  try {
    analysis.value = await aiAssistantApi.documentAnalysis(props.doc.id, detail.value, withAccounting.value)
  } catch (e) {
    toast.error(aiErrorMessage(e, t('documents.ai.failed')))
  } finally {
    analysisBusy.value = false
  }
}

const summaryText = computed(() => shownSummary.value === 'long' ? summaryLong.value : summaryShort.value)

// Jen při změně dokumentu: PATCH metadat vrací doklad bez AI polí a nesmí je smazat.
watch(() => props.doc.id, () => syncFromDoc(props.doc), { immediate: true })

onMounted(async () => {
  try {
    const st = await aiAssistantApi.status()
    aiAvailable.value = st.available
  } catch {
    aiAvailable.value = null
  }
})
</script>

<template>
  <div v-if="!hidden" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-4" data-test="document-ai-panel">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="text-sm font-medium text-neutral-700 inline-flex items-center gap-1.5">
        <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
        {{ t('documents.ai.title') }}
      </h3>
      <RouterLink
        v-if="auth.canRead('accounting')"
        :to="{ path: '/accounting/ai-assistant', query: { document_id: String(doc.id) } }"
        :class="btnOutlineSm('primary')"
        data-test="document-ai-ask"
      >
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" /></svg>
        {{ t('documents.ai.ask_assistant') }}
      </RouterLink>
    </div>

    <p v-if="aiAvailable === false" class="text-xs text-warning-700">{{ t('documents.ai.unavailable') }}</p>

    <!-- Shrnutí -->
    <section class="space-y-2">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('documents.ai.summary') }}</h4>
        <div v-if="summaryShort && summaryLong" class="inline-flex rounded-md border border-neutral-200 overflow-hidden text-xs">
          <button type="button" class="cursor-pointer px-2 h-6" :class="shownSummary === 'short' ? 'bg-primary-50 text-primary-700 font-medium' : 'text-neutral-600 hover:bg-neutral-50'" @click="shownSummary = 'short'">{{ t('documents.ai.short') }}</button>
          <button type="button" class="cursor-pointer px-2 h-6 border-l border-neutral-200" :class="shownSummary === 'long' ? 'bg-primary-50 text-primary-700 font-medium' : 'text-neutral-600 hover:bg-neutral-50'" @click="shownSummary = 'long'">{{ t('documents.ai.long') }}</button>
        </div>
      </div>
      <template v-if="summaryText">
        <AiMarkdown :text="summaryText" />
        <p v-if="summaryAt" class="text-xs text-neutral-400">{{ t('documents.ai.generated_at', { at: formatDateTime(summaryAt) }) }}</p>
      </template>
      <p v-else class="text-sm text-neutral-400">{{ t('documents.ai.no_summary') }}</p>
      <div v-if="canWrite && aiAvailable !== false" class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('primary')" :disabled="!!summaryBusy" data-test="document-ai-summary-short" @click="generateSummary('short')">
          <svg v-if="summaryBusy === 'short'" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
          <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="summaryShort ? ICONS.cycle : ICONS.doc" /></svg>
          {{ summaryShort ? t('documents.ai.regenerate_short') : t('documents.ai.generate_short') }}
        </button>
        <button type="button" :class="btnOutline('primary')" :disabled="!!summaryBusy" data-test="document-ai-summary-long" @click="generateSummary('long')">
          <svg v-if="summaryBusy === 'long'" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
          <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="summaryLong ? ICONS.cycle : ICONS.doc" /></svg>
          {{ summaryLong ? t('documents.ai.regenerate_long') : t('documents.ai.generate_long') }}
        </button>
      </div>
    </section>

    <!-- AI vytěžení dokladu -->
    <section class="space-y-2 border-t border-neutral-100 pt-3">
      <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('documents.ai.analysis') }}</h4>
      <div v-if="canWrite && aiAvailable !== false" class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
          <select v-model="detail" class="px-2 py-1.5 text-sm border border-neutral-300 rounded-md bg-surface" :aria-label="t('documents.ai.detail')">
            <option value="short">{{ t('documents.ai.detail_short') }}</option>
            <option value="long">{{ t('documents.ai.detail_long') }}</option>
          </select>
          <label class="inline-flex items-center gap-2 text-sm text-neutral-700 cursor-pointer">
            <input v-model="withAccounting" type="checkbox" class="rounded border-neutral-300" />
            {{ t('documents.ai.with_accounting') }}
          </label>
        </div>
        <button type="button" :class="btnFilled('primary')" class="w-full justify-center" :disabled="analysisBusy" data-test="document-ai-analysis-run" @click="runAnalysis">
          <svg v-if="analysisBusy" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
          <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
          {{ analysis ? t('documents.ai.rerun_analysis') : t('documents.ai.run_analysis') }}
        </button>
      </div>

      <div v-if="analysis" class="space-y-3" data-test="document-ai-analysis">
        <p class="text-xs text-neutral-400">
          {{ analysis.detail === 'long' ? t('documents.ai.detail_long') : t('documents.ai.detail_short') }}<template v-if="analysis.accounting"> · {{ t('documents.ai.with_accounting_short') }}</template><template v-if="analysis.created_at"> · {{ formatDateTime(analysis.created_at) }}</template>
        </p>
        <dl v-if="analysis.fields?.length" class="grid grid-cols-1 gap-y-1.5 text-sm">
          <div v-for="(f, i) in analysis.fields" :key="i" class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-2">
            <dt class="text-neutral-400 text-xs pt-0.5 break-words">{{ f.label }}</dt>
            <dd class="text-neutral-800 break-words">{{ f.value }}</dd>
          </div>
        </dl>
        <AiMarkdown v-if="analysis.answer" :text="analysis.answer" />
        <ul v-if="analysis.warnings?.length" class="space-y-1">
          <li v-for="(w, i) in analysis.warnings" :key="i" class="text-xs text-warning-700 bg-warning-50 rounded px-2 py-1">{{ w }}</li>
        </ul>
        <div v-if="(analysis.other_items ?? []).length" class="space-y-2">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.ai_assistant.other_item.heading') }}</h4>
          <AiOtherItemProposal v-for="(oi, i) in analysis.other_items ?? []" :key="i" :item="oi" :link-document-ids="[doc.id]" @created="id => emit('posted', id)" />
        </div>
        <div v-if="analysis.journal_entries?.length" class="space-y-2">
          <AiJournalProposal v-for="(je, i) in analysis.journal_entries" :key="i" :entry="je" :link-document-ids="[doc.id]" @posted="id => emit('posted', id)" />
        </div>
      </div>
      <p v-else class="text-sm text-neutral-400">{{ t('documents.ai.no_analysis') }}</p>
    </section>
  </div>
</template>
