<script setup lang="ts">
import { ref, watch, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import TripsTab from './TripsTab.vue'
import CarsTab from './CarsTab.vue'
import FuelingsTab from './FuelingsTab.vue'
import CategoriesTab from './CategoriesTab.vue'
import TemplatesTab from './TemplatesTab.vue'
import SummariesTab from './SummariesTab.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

type Tab = 'trips' | 'cars' | 'fuel' | 'templates' | 'categories' | 'summary'
const tabs: Tab[] = ['trips', 'cars', 'fuel', 'templates', 'categories', 'summary']
const tabLabelKey: Record<Tab, string> = {
  trips: 'logbook.tab_trips', cars: 'logbook.tab_cars', fuel: 'logbook.tab_fuel',
  templates: 'logbook.tab_templates', categories: 'logbook.tab_categories', summary: 'logbook.tab_summary',
}
const tab = ref<Tab>((tabs as string[]).includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'trips')

watch(tab, (v) => {
  if (route.query.tab !== v) router.replace({ query: { ...route.query, tab: v } })
})

// Rychlé vytvoření z topbaru / „+" v menu přijde jako ?new=trip|fuel → přepni
// na správný tab a otevři modal nového záznamu. Každý tab poslouchá jen svůj
// token (žádné křížové otevírání). `new` z URL hned odstraníme, ať refresh /
// tlačítko zpět modal znovu neotevřou.
const openNewTripToken = ref(0)
const openNewFuelToken = ref(0)

async function handleNewQuery() {
  const n = route.query.new
  if (n !== 'trip' && n !== 'fuel') return
  tab.value = n === 'trip' ? 'trips' : 'fuel'
  const q = { ...route.query }; delete q.new
  await router.replace({ query: q })
  await nextTick()
  if (n === 'trip') openNewTripToken.value++
  else openNewFuelToken.value++
}
onMounted(handleNewQuery)

// Klik na menu „Kniha jízd" (= /logbook bez query) → reset filtrů na default,
// stejně jako přehled faktur. Záložky poslouchají na resetToken.
const resetToken = ref(0)
watch(() => route.query, (q) => {
  if (Object.keys(q).length === 0) {
    tab.value = 'trips'
    resetToken.value++
  } else {
    handleNewQuery()
  }
})
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('logbook.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('logbook.subtitle') }}</p>
    </div>

    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in tabs" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t(tabLabelKey[tt]) }}
      </button>
    </div>

    <KeepAlive>
      <TripsTab v-if="tab === 'trips'" :reset-token="resetToken" :open-new-token="openNewTripToken" />
      <CarsTab v-else-if="tab === 'cars'" :reset-token="resetToken" />
      <FuelingsTab v-else-if="tab === 'fuel'" :reset-token="resetToken" :open-new-token="openNewFuelToken" />
      <TemplatesTab v-else-if="tab === 'templates'" :reset-token="resetToken" />
      <CategoriesTab v-else-if="tab === 'categories'" :reset-token="resetToken" />
      <SummariesTab v-else :reset-token="resetToken" />
    </KeepAlive>
  </div>
</template>
