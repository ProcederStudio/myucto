<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { formatDate } from '@/composables/useFormat'
import type { PurchaseInvoiceItem } from '@/api/purchaseInvoices'

/**
 * Daňové a majetkové zařazení řádku přijaté faktury (druh nákladu §DM,
 * klasifikace plnění, časové rozlišení §DČR, karta drobného majetku).
 *
 * Vytažené ze stránky detailu, protože tytéž údaje ukazuje desktopová tabulka
 * i mobilní karta — dvě kopie markupu by se dřív nebo později rozešly.
 */
defineProps<{ item: PurchaseInvoiceItem }>()

const { t } = useI18n()
</script>

<template>
  <div v-if="item.expense_kind || item.expense_account_code || item.vat_classification_code || item.accrual_from"
    class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
    <span v-if="item.expense_kind" class="inline-block px-1.5 py-0.5 rounded"
      :class="item.expense_kind === 'fixed_asset' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">
      {{ t('purchase_invoice.expense_kind.' + item.expense_kind) }}
    </span>
    <span v-if="item.expense_account_code" class="inline-block px-1.5 py-0.5 rounded bg-neutral-100 font-mono text-neutral-600"
      :title="t('purchase_invoice.items.expense_account')">{{ item.expense_account_code }}</span>
    <span v-if="item.vat_classification_code" class="inline-block px-1.5 py-0.5 rounded bg-neutral-100 font-mono text-neutral-600"
      :title="t('purchase_invoice.classification.vat_classification')">
      {{ item.vat_classification_code }}
    </span>
    <span v-if="item.accrual_from" class="text-neutral-500" :title="t('purchase_invoice.items.accrual_hint')">
      {{ t('purchase_invoice.items.accrual_from') }} {{ formatDate(item.accrual_from) }}–{{ item.accrual_to ? formatDate(item.accrual_to) : '—' }}
    </span>
  </div>
  <div v-if="item.small_asset" class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
    <span class="inline-block px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">
      {{ t('accounting.small_assets.title') }}: {{ item.small_asset.name }}
    </span>
    <RouterLink v-if="item.small_asset.status === 'in_use'"
      :to="{ name: 'accounting-small-assets', query: { sell: String(item.small_asset.id) } }"
      class="text-primary-600 hover:underline">
      {{ t('purchase_invoice.items.small_asset_sell') }}
    </RouterLink>
    <RouterLink v-else :to="{ name: 'accounting-small-assets' }" class="text-neutral-500 hover:underline">
      {{ t(`accounting.small_assets.status_${item.small_asset.status}`) }}
    </RouterLink>
  </div>
</template>
