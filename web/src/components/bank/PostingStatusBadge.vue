<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BankTransaction } from '@/api/bank'
import { useBankJournalNavigation } from './useBankJournalNavigation'
import AutomationBadge from '@/components/automation/AutomationBadge.vue'

const props = defineProps<{ posting?: BankTransaction['posting']; currency?: string | null }>()
const { t } = useI18n()
const navigation = useBankJournalNavigation()
const target = computed(() => navigation.entryTarget(props.posting?.journal_entry_id))

// Cizí měna se odvozuje čistě z tx.currency (H4h) — není to stav z BE.
const isForeign = computed(() => !!props.currency && props.currency !== 'CZK')

const accounts = computed(() => {
  const p = props.posting
  if (!p?.debit_account_code || !p?.credit_account_code) return ''
  return `${p.debit_account_code}/${p.credit_account_code}`
})

const postedTitle = computed(() => {
  const p = props.posting
  if (!p) return ''
  const parts: string[] = []
  if (p.document_no) parts.push(p.document_no)
  if (p.rule_name) parts.push(t('bank.posting.auto_posted_by', { rule: p.rule_name }))
  return parts.join(' · ')
})
</script>

<template>
  <component :is="target ? 'a' : 'span'" v-if="posting?.note === 'payroll_partial_posting'"
    :href="target ? navigation.href(target) : undefined"
    :data-workspace-open="target ? 'beside' : undefined"
    :title="t('bank.posting.payroll_partial_hint')"
    class="mt-1 inline-flex items-center whitespace-nowrap text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600"
    :class="target ? 'hover:bg-warning-100' : ''" @click="navigation.open($event, target)">
    {{ t('bank.posting.badge_payroll_partial') }}
  </component>

  <component :is="target ? 'a' : 'span'" v-else-if="posting?.status === 'posted'"
    :href="target ? navigation.href(target) : undefined"
    :data-workspace-open="target ? 'beside' : undefined"
    :title="postedTitle"
    class="mt-1 inline-flex items-center whitespace-nowrap"
    :class="posting.automated ? '' : ['text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600', target ? 'hover:bg-success-100' : '']"
    @click="navigation.open($event, target)">
    <AutomationBadge v-if="posting.automated" variant="auto" />
    <span v-else>{{ t('bank.posting.badge_posted') }}</span>
  </component>

  <span v-else-if="posting?.status === 'suggested'"
    :title="posting.rule_name ?? undefined"
    class="mt-1 inline-flex items-center text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600">
    {{ accounts ? t('bank.posting.badge_suggested', { accounts }) : t('bank.posting.tab_suggestions') }}
  </span>

  <span v-else-if="isForeign"
    :title="t('bank.posting.skipped_fx_hint')"
    class="mt-1 inline-flex items-center text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500">
    {{ t('bank.posting.badge_fx') }}
  </span>
</template>
