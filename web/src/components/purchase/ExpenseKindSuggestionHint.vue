<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ExpenseKindSuggestion } from '@/api/purchaseInvoices'
import ConfidenceLabel from '@/components/automation/ConfidenceLabel.vue'

/**
 * §DM — návrh druhu nákladu u položky přijaté faktury, i s důvodem.
 *
 * Důvod se ukazuje VŽDY a je to záměr, ne výplň: „Zobraz, PROČ to tak je — jinak se tomu
 * nedá věřit." Bez něj uživatel nepozná rozdíl mezi pravidlem s jistotou 100 % a tipem AI.
 *
 * Komponenta sama NIC nemění — jen emituje `apply`; zápis dělá rodič na klik uživatele.
 */
const props = defineProps<{ suggestion: ExpenseKindSuggestion }>()
defineEmits<{ apply: []; dismiss: [] }>()

const { t } = useI18n()

const kindLabel = computed(() => t(`purchase_invoice.expense_kind.${props.suggestion.expense_kind}`))
const sourceLabel = computed(() => t(`purchase_invoice.expense_suggestion.source.${props.suggestion.source}`))
</script>

<template>
  <div class="mt-1 rounded border border-warning-200 bg-warning-50/60 px-2 py-1.5 text-xs">
    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
      <span class="font-medium text-neutral-800">{{ t('purchase_invoice.expense_suggestion.proposes', { kind: kindLabel }) }}</span>
      <span v-if="suggestion.expense_account_code" class="font-mono text-neutral-800">{{ suggestion.expense_account_code }}</span>
      <span class="text-neutral-500">·</span>
      <span class="text-neutral-600">{{ sourceLabel }}</span>
      <ConfidenceLabel :confidence="suggestion.confidence" />
      <!-- Slabý důkaz musí být VIDĚT jako slabý, ne jen tiše nižší procento. -->
      <span
        v-if="!suggestion.auto"
        class="inline-flex items-center rounded bg-warning-100 px-1.5 py-0.5 font-medium text-warning-700"
      >
        {{ t('purchase_invoice.expense_suggestion.needs_review') }}
      </span>
    </div>
    <p class="mt-1 text-neutral-600">{{ suggestion.reason }}</p>
    <div class="mt-1.5 flex flex-wrap gap-2">
      <button
        type="button"
        class="cursor-pointer rounded bg-success-600 px-2 py-0.5 font-medium text-white hover:bg-success-700"
        @click="$emit('apply')"
      >
        {{ t('purchase_invoice.expense_suggestion.apply') }}
      </button>
      <button
        type="button"
        class="cursor-pointer rounded border border-neutral-300 px-2 py-0.5 font-medium text-neutral-600 hover:bg-neutral-50"
        @click="$emit('dismiss')"
      >
        {{ t('purchase_invoice.expense_suggestion.dismiss') }}
      </button>
    </div>
  </div>
</template>
