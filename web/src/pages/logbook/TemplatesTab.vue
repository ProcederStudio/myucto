<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { logbookApi, type Car, type TripCategory, type TripTemplate, type TripTemplatePayload } from '@/api/logbook'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const props = defineProps<{ resetToken?: number }>()
const pageId = useId()
const canWrite = computed(() => auth.canWrite('logbook.write'))
const canDelete = computed(() => auth.canWrite('logbook.delete'))

const templates = ref<TripTemplate[]>([])
const cars = ref<Car[]>([])
const categories = ref<TripCategory[]>([])
const purposes = ref<string[]>([])
const places = ref<string[]>([])
const loading = ref(false)
const showArchived = ref(false)

const open = ref(false)
const saving = ref(false)
type Draft = Omit<TripTemplatePayload, 'distance_km'> & { id: number; distance_km: number | null }
const emptyDraft = (): Draft => ({ id: 0, car_id: null, label: '', origin: '', destination: '', purpose: '', distance_km: null,
  category_id: null, time_start: '', time_end: '', display_order: 0, is_archived: false })
const draft = reactive<Draft>(emptyDraft())

async function load() {
  loading.value = true
  try { templates.value = await logbookApi.listTemplates(showArchived.value) }
  finally { loading.value = false }
}
async function loadCodebooks() {
  const [c, cat, p, pl] = await Promise.all([
    logbookApi.listCars(), logbookApi.listCategories(), logbookApi.tripPurposes(), logbookApi.tripPlaces(),
  ])
  cars.value = c; categories.value = cat; purposes.value = p; places.value = pl
}
onMounted(() => { load(); loadCodebooks() })

watch(() => props.resetToken, () => { showArchived.value = false; load() })

function newTemplate() {
  const defaultCategory = categories.value.find(c => c.is_default && !c.is_private) ?? categories.value.find(c => !c.is_private)
  Object.assign(draft, emptyDraft(), { category_id: defaultCategory?.id ?? null, display_order: (templates.value.length + 1) * 10 })
  open.value = true
}
function editTemplate(tt: TripTemplate) {
  Object.assign(draft, {
    id: tt.id, car_id: tt.car_id, label: tt.label, origin: tt.origin ?? '', destination: tt.destination ?? '',
    purpose: tt.purpose ?? '', distance_km: tt.distance_km, category_id: tt.category_id,
    time_start: tt.time_start ?? '', time_end: tt.time_end ?? '', display_order: tt.display_order, is_archived: tt.is_archived,
  })
  open.value = true
}

// Návrh názvu z trasy, dokud ho uživatel nevyplní sám.
function suggestLabel() {
  if (draft.label.trim() || !draft.origin?.trim() || !draft.destination?.trim()) return
  draft.label = `${draft.origin.trim()} → ${draft.destination.trim()}`
}

async function save() {
  if (!draft.label.trim() || !draft.distance_km || Number(draft.distance_km) <= 0) {
    toast.error(t('logbook.tpl_required')); return
  }
  saving.value = true
  try {
    const payload: TripTemplatePayload = {
      car_id: draft.car_id || null, label: draft.label.trim(), origin: draft.origin || null, destination: draft.destination || null,
      purpose: draft.purpose || null, distance_km: Number(draft.distance_km), category_id: draft.category_id || null,
      time_start: draft.time_start || null, time_end: draft.time_end || null,
      display_order: Number(draft.display_order) || 0, is_archived: draft.is_archived,
    }
    if (draft.id) await logbookApi.updateTemplate(draft.id, payload)
    else await logbookApi.createTemplate(payload)
    open.value = false
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { saving.value = false }
}

async function removeTemplate(tt: TripTemplate) {
  if (!confirm(t('logbook.tpl_confirm_delete', { label: tt.label }))) return
  try {
    await logbookApi.deleteTemplate(tt.id)
    toast.success(t('common.deleted'))
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.error?.message ?? t('common.error')) }
}

const carLabel = (tt: TripTemplate) => tt.car_id ? (tt.car_name || tt.car_registration || '') : t('logbook.tpl_any_car')
const timeRange = (tt: TripTemplate) => tt.time_start ? `${tt.time_start}${tt.time_end ? '–' + tt.time_end : ''}` : ''
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <label class="flex items-center gap-2 text-sm text-neutral-600">
        <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-600" @change="load" />
        {{ t('logbook.show_archived') }}
      </label>
      <button v-if="canWrite" @click="newTemplate" class="whitespace-nowrap" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('logbook.tpl_new') }}
      </button>
    </div>

    <p class="text-sm text-neutral-500 mb-3">{{ t('logbook.templates_hint') }}</p>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="templates.length === 0" icon="pin" :title="t('logbook.no_templates')" dense boxed />

    <template v-else>
      <!-- Desktop -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.tpl_label') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.origin') }} → {{ t('logbook.destination') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.purpose') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.car') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('logbook.distance_km') }}</th>
              <th class="px-3 py-2 w-px"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="tt in templates" :key="tt.id" class="hover:bg-neutral-50" :class="tt.is_archived ? 'opacity-50' : ''">
              <td class="px-3 py-2">
                <div class="font-medium">{{ tt.label }}</div>
                <div class="flex flex-wrap items-center gap-1.5 mt-0.5 text-xs text-neutral-500">
                  <span v-if="tt.category_label" class="px-1.5 py-0.5 rounded" :class="tt.category_is_private ? 'bg-warning-50 text-warning-600' : 'bg-success-50 text-success-600'">{{ tt.category_label }}</span>
                  <span v-if="timeRange(tt)" class="font-mono">{{ timeRange(tt) }}</span>
                </div>
              </td>
              <td class="px-3 py-2 text-neutral-700">{{ tt.origin || '—' }} → {{ tt.destination || '—' }}</td>
              <td class="px-3 py-2 text-neutral-700">{{ tt.purpose || '—' }}</td>
              <td class="px-3 py-2 text-neutral-600">{{ carLabel(tt) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ tt.distance_km.toLocaleString('cs-CZ') }}</td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap justify-end gap-1.5">
                  <button v-if="canWrite" @click="editTemplate(tt)" :class="btnOutlineSm('neutral')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                    {{ t('common.edit') }}
                  </button>
                  <button v-if="canDelete" @click="removeTemplate(tt)" :class="btnOutlineSm('danger')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 overflow-hidden">
        <div v-for="tt in templates" :key="`m-${tt.id}`" class="px-4 py-3" :class="tt.is_archived ? 'opacity-50' : ''">
          <div class="flex items-baseline justify-between gap-2">
            <span class="font-medium text-neutral-900">{{ tt.label }}</span>
            <span class="font-mono text-sm shrink-0">{{ tt.distance_km.toLocaleString('cs-CZ') }} km</span>
          </div>
          <div class="mt-1 text-xs text-neutral-500">{{ tt.origin || '—' }} → {{ tt.destination || '—' }}</div>
          <div class="flex flex-wrap items-center gap-1.5 mt-1 text-xs text-neutral-500">
            <span v-if="tt.category_label" class="px-1.5 py-0.5 rounded" :class="tt.category_is_private ? 'bg-warning-50 text-warning-600' : 'bg-success-50 text-success-600'">{{ tt.category_label }}</span>
            <span>{{ carLabel(tt) }}</span>
            <span v-if="timeRange(tt)" class="font-mono">{{ timeRange(tt) }}</span>
          </div>
          <div v-if="canWrite || canDelete" class="flex flex-wrap gap-2 mt-2">
            <button v-if="canWrite" @click="editTemplate(tt)" :class="btnOutlineSm('neutral')">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              {{ t('common.edit') }}
            </button>
            <button v-if="canDelete" @click="removeTemplate(tt)" :class="btnOutlineSm('danger')" class="ml-auto">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
    </template>

    <datalist :id="`${pageId}-tpl-purposes`"><option v-for="p in purposes" :key="p" :value="p" /></datalist>
    <datalist :id="`${pageId}-tpl-places`"><option v-for="p in places" :key="p" :value="p" /></datalist>

    <!-- Modal -->
    <div v-if="open" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <form @submit.prevent="save" class="p-5 space-y-4">
          <h2 class="text-lg font-semibold">{{ draft.id ? t('logbook.tpl_edit') : t('logbook.tpl_new') }}</h2>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.origin') }}</label>
              <input v-model="draft.origin" type="text" maxlength="255" :list="`${pageId}-tpl-places`" @blur="suggestLabel" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.destination') }}</label>
              <input v-model="draft.destination" type="text" maxlength="255" :list="`${pageId}-tpl-places`" @blur="suggestLabel" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.tpl_label') }} *</label>
              <input v-model="draft.label" type="text" maxlength="120" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.purpose') }}</label>
              <input v-model="draft.purpose" type="text" maxlength="255" :list="`${pageId}-tpl-purposes`" :placeholder="t('logbook.purpose_placeholder')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.distance_km') }} *</label>
              <input v-model.number="draft.distance_km" type="number" min="0.1" step="0.1" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.category') }}</label>
              <select v-model="draft.category_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm">
                <option :value="null">—</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.tpl_time_start') }}</label>
              <input v-model="draft.time_start" type="time" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.tpl_time_end') }}</label>
              <input v-model="draft.time_end" type="time" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.car') }}</label>
              <select v-model="draft.car_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm">
                <option :value="null">{{ t('logbook.tpl_any_car') }}</option>
                <option v-for="c in cars" :key="c.id" :value="c.id">{{ c.name ? `${c.name} (${c.registration})` : c.registration }}</option>
              </select>
            </div>
          </div>
          <label v-if="draft.id" class="flex items-center gap-2 text-sm">
            <input v-model="draft.is_archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('logbook.archived') }}
          </label>
          <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
            <button type="button" @click="open = false" class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1.5 whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :disabled="saving" class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50 inline-flex items-center gap-1.5 whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check"/></svg>
              {{ t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>
