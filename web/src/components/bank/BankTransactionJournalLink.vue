<script setup lang="ts">
import { computed } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { BankTransaction } from '@/api/bank'
import { useBankJournalNavigation } from './useBankJournalNavigation'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'

const props = defineProps<{ tx: BankTransaction }>()
const { t } = useI18n()
const navigation = useBankJournalNavigation()

const target = computed<{ to: RouteLocationRaw; label: string } | null>(() => {
  const to = navigation.entryTarget(props.tx.posting?.journal_entry_id)
  return to ? { to, label: 'bank.open_accounting_journal' } : null
})
</script>

<template>
  <a v-if="target" :href="navigation.href(target.to)" class="whitespace-nowrap" :class="btnOutlineSm('neutral')"
     :title="t(target.label)" data-workspace-open="beside" data-test="bank-transaction-journal" @click="navigation.open($event, target.to)">
    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
    </svg>
    {{ t(target.label) }}
  </a>
</template>
