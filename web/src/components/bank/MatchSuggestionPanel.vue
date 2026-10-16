<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { MatchSuggestion, MatchSuggestionCandidate } from '@/api/bank'
import MatchSuggestionCandidateCard from './MatchSuggestionCandidateCard.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = withDefaults(defineProps<{
  suggestion: MatchSuggestion
  reviewing: number | null
  canReview: boolean
  canAcceptCandidate?: (candidate: MatchSuggestionCandidate) => boolean
  /** 'panel' = širší expand řádek v desktop tabulce, 'inline' = kompaktní (mobil/modal). */
  variant?: 'panel' | 'inline'
}>(), { variant: 'panel' })

const emit = defineEmits<{ accept: [candidateIndex: number]; reject: [] }>()
const { t } = useI18n()
</script>

<template>
  <div class="rounded-lg border border-warning-500/30 p-3"
    :class="variant === 'panel' ? 'bg-surface p-4' : 'bg-warning-50/40 space-y-3'">
    <h3 class="text-sm font-semibold text-neutral-800" :class="variant === 'panel' ? 'mb-3' : 'mb-2'">
      {{ t('bank.match_v2.title') }}
    </h3>
    <div :class="variant === 'panel' ? 'space-y-3' : 'space-y-2'">
      <MatchSuggestionCandidateCard v-for="(candidate, candidateIndex) in suggestion.candidates" :key="candidateIndex"
        :candidate="candidate" :reviewing="reviewing !== null" :can-accept="canReview && (!canAcceptCandidate || canAcceptCandidate(candidate))" :dense="variant !== 'panel'"
        @accept="emit('accept', candidateIndex)" />
    </div>
    <div v-if="canReview" :class="variant === 'panel' ? 'mt-3 flex justify-end' : 'mt-3'">
      <button type="button" :disabled="reviewing !== null"
        :class="[btnOutline('danger'), variant !== 'panel' ? 'w-full justify-center' : '']" @click="emit('reject')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        {{ t('bank.match_v2.reject') }}
      </button>
    </div>
  </div>
</template>
