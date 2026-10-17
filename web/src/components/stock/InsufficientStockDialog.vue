<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled } from '@/components/ui/buttonStyles'
import { formatNumber } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import type { InsufficientStockItem } from './insufficientStock'

/**
 * Okno nedostatku zásob (vystavení faktury, zaúčtování skladového dokladu).
 * Místo jednořádkového toastu vypíše každou chybějící kartu: kód, název, sklad,
 * požadované a dostupné množství, kolik chybí a jednotku. Z řádku vede odkaz
 * na kartu a, s právem zapisovat skladové doklady, na novou příjemku do skladu řádku.
 */
const props = defineProps<{ items: InsufficientStockItem[] }>()
const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()
const auth = useAuthStore()
const canOpenItem = auth.canRead('stock')
const canReceive = auth.canWrite('stock.documents.write')

const closeButton = ref<HTMLButtonElement | null>(null)
let returnFocus: HTMLElement | null = null
onMounted(() => {
  returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
  void nextTick(() => closeButton.value?.focus())
})
onBeforeUnmount(() => {
  if (returnFocus?.isConnected) returnFocus.focus()
})

function qty(value: string | number): string {
  return formatNumber(Number(value), { maximumFractionDigits: 3 })
}
function missing(item: InsufficientStockItem): string {
  return qty(Math.max(0, Number(item.requested) - Number(item.available)))
}
function receiptLink(item: InsufficientStockItem) {
  const query: Record<string, string> = { doc_type: 'receipt', stock_item_id: String(item.stock_item_id) }
  if (item.warehouse_id) query.warehouse_id = String(item.warehouse_id)
  return { path: '/stock/documents/new', query }
}

void props
</script>

<template>
  <Modal :title="t('stock.insufficient.title')" width-class="max-w-4xl" @close="emit('close')">
    <p class="text-sm text-neutral-600">{{ t('stock.insufficient.intro') }}</p>

    <!-- Desktop: tabulka -->
    <div class="hidden md:block mt-4 overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th scope="col" class="px-3 py-2 text-left font-medium">{{ t('stock.insufficient.col_sku') }}</th>
            <th scope="col" class="px-3 py-2 text-left font-medium">{{ t('stock.insufficient.col_name') }}</th>
            <th scope="col" class="px-3 py-2 text-left font-medium">{{ t('stock.insufficient.col_warehouse') }}</th>
            <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('stock.insufficient.col_requested') }}</th>
            <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('stock.insufficient.col_available') }}</th>
            <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('stock.insufficient.col_missing') }}</th>
            <th scope="col" class="px-3 py-2 text-left font-medium">{{ t('stock.insufficient.col_unit') }}</th>
            <th scope="col" class="px-3 py-2"><span class="sr-only">{{ t('stock.insufficient.col_actions') }}</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200">
          <tr v-for="item in items" :key="`d-${item.warehouse_id ?? 0}-${item.stock_item_id}`" data-test="insufficient-stock-row">
            <td class="px-3 py-2 font-mono whitespace-nowrap">{{ item.sku || '-' }}</td>
            <td class="px-3 py-2">{{ item.name || '-' }}</td>
            <td class="px-3 py-2">{{ item.warehouse_name || '-' }}</td>
            <td class="px-3 py-2 text-right font-mono">{{ qty(item.requested) }}</td>
            <td class="px-3 py-2 text-right font-mono">{{ qty(item.available) }}</td>
            <td class="px-3 py-2 text-right font-mono font-semibold text-danger-600">{{ missing(item) }}</td>
            <td class="px-3 py-2">{{ item.unit || '' }}</td>
            <td class="px-3 py-2 text-right whitespace-nowrap">
              <RouterLink v-if="canOpenItem" :to="`/stock/items/${item.stock_item_id}`" class="text-primary-600 hover:text-primary-700 hover:underline" @click="emit('close')">
                {{ t('stock.insufficient.open_item') }}
              </RouterLink>
              <RouterLink v-if="canReceive" :to="receiptLink(item)" :class="canOpenItem ? 'ml-3' : ''" class="text-primary-600 hover:text-primary-700 hover:underline" @click="emit('close')">
                {{ t('stock.insufficient.new_receipt') }}
              </RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Mobil: karta na položku -->
    <ul class="md:hidden mt-4 divide-y divide-neutral-200 border-y border-neutral-200">
      <li v-for="item in items" :key="`m-${item.warehouse_id ?? 0}-${item.stock_item_id}`" data-test="insufficient-stock-row" class="py-3 space-y-1.5">
        <div class="text-sm font-medium">
          <span class="font-mono">{{ item.sku || '-' }}</span> · {{ item.name || '-' }}
        </div>
        <div v-if="item.warehouse_name" class="text-xs text-neutral-500">{{ t('stock.insufficient.col_warehouse') }}: {{ item.warehouse_name }}</div>
        <dl class="grid grid-cols-3 gap-2 text-xs">
          <div>
            <dt class="text-neutral-500">{{ t('stock.insufficient.col_requested') }}</dt>
            <dd class="font-mono">{{ qty(item.requested) }} {{ item.unit || '' }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('stock.insufficient.col_available') }}</dt>
            <dd class="font-mono">{{ qty(item.available) }} {{ item.unit || '' }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('stock.insufficient.col_missing') }}</dt>
            <dd class="font-mono font-semibold text-danger-600">{{ missing(item) }} {{ item.unit || '' }}</dd>
          </div>
        </dl>
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          <RouterLink v-if="canOpenItem" :to="`/stock/items/${item.stock_item_id}`" class="inline-flex items-center min-h-9 text-primary-600 hover:text-primary-700" @click="emit('close')">
            {{ t('stock.insufficient.open_item') }}
          </RouterLink>
          <RouterLink v-if="canReceive" :to="receiptLink(item)" class="inline-flex items-center min-h-9 text-primary-600 hover:text-primary-700" @click="emit('close')">
            {{ t('stock.insufficient.new_receipt') }}
          </RouterLink>
        </div>
      </li>
    </ul>

    <template #footer>
      <div class="flex justify-end">
        <button ref="closeButton" type="button" data-test="insufficient-stock-close" :class="btnFilled('primary')" @click="emit('close')">
          {{ t('common.close') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
