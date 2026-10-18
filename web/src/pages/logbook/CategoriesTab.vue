<script setup lang="ts">
import { ref, reactive, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { logbookApi, type TripCategory, type TripCategoryPayload } from '@/api/logbook'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const props = defineProps<{ resetToken?: number }>()

const categories = ref<TripCategory[]>([])
const loading = ref(false)
const showArchived = ref(false)

const open = ref(false)
const saving = ref(false)
const draft = reactive<TripCategoryPayload & { id: number }>({ id: 0, code: '', label: '', is_private: false, is_default: false, display_order: 0, is_archived: false })

async function load() {
  loading.value = true
  try { categories.value = await logbookApi.listCategories(showArchived.value) }
  finally { loading.value = false }
}
onMounted(load)

watch(() => props.resetToken, () => { showArchived.value = false; load() })

function newCategory() {
  // První kategorie tenantu se rovnou nabídne jako výchozí (jako u aut).
  Object.assign(draft, { id: 0, code: '', label: '', is_private: false, is_default: categories.value.length === 0, display_order: (categories.value.length + 1) * 10, is_archived: false })
  open.value = true
}
function editCategory(c: TripCategory) {
  Object.assign(draft, { id: c.id, code: c.code, label: c.label, is_private: c.is_private, is_default: c.is_default, display_order: c.display_order, is_archived: c.is_archived })
  open.value = true
}

async function save() {
  if (!draft.code.trim() || !draft.label.trim()) { toast.error(t('logbook.cat_required')); return }
  saving.value = true
  try {
    const payload: TripCategoryPayload = { code: draft.code.trim(), label: draft.label.trim(), is_private: draft.is_private, is_default: draft.is_default, display_order: Number(draft.display_order), is_archived: draft.is_archived }
    if (draft.id) await logbookApi.updateCategory(draft.id, payload)
    else await logbookApi.createCategory(payload)
    open.value = false
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { saving.value = false }
}

async function removeCategory(c: TripCategory) {
  if ((c.trips_count ?? 0) > 0) { toast.error(t('logbook.cat_delete_blocked')); return }
  if (!confirm(t('logbook.confirm_delete_category', { label: c.label }))) return
  try {
    await logbookApi.deleteCategory(c.id)
    toast.success(t('common.deleted'))
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.error?.message ?? t('common.error')) }
}
</script>

<template>
  <section>
    <div class="flex items-center justify-between gap-2 mb-3">
      <label class="flex items-center gap-2 text-sm text-neutral-600">
        <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-600" @change="load" />
        {{ t('logbook.show_archived') }}
      </label>
      <button v-if="auth.canWrite('logbook.write')" @click="newCategory"
        :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('logbook.cat_new') }}
      </button>
    </div>

    <p class="text-sm text-neutral-500 mb-3">{{ t('logbook.categories_hint') }}</p>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="categories.length === 0" icon="tag" :title="t('logbook.no_categories')" dense boxed />

    <template v-else>
      <!-- Desktop -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.cat_label') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.cat_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.category') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('logbook.tab_trips') }}</th>
              <th class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in categories" :key="c.id" class="hover:bg-neutral-50" :class="c.is_archived ? 'opacity-50' : ''">
              <td class="px-3 py-2 font-medium">
                {{ c.label }}
                <span v-if="c.is_default" class="ml-1.5 text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700">{{ t('logbook.default_short') }}</span>
              </td>
              <td class="px-3 py-2 font-mono text-xs text-neutral-500">{{ c.code }}</td>
              <td class="px-3 py-2">
                <span class="text-xs px-1.5 py-0.5 rounded" :class="c.is_private ? 'bg-warning-50 text-warning-600' : 'bg-success-50 text-success-600'">
                  {{ c.is_private ? t('logbook.private') : t('logbook.business') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right font-mono">{{ c.trips_count ?? 0 }}</td>
              <td class="px-3 py-2 text-right text-xs whitespace-nowrap">
                <template v-if="auth.canWrite('logbook.write') || auth.canWrite('logbook.delete')">
                  <button v-if="auth.canWrite('logbook.write')" @click="editCategory(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                  <button v-if="auth.canWrite('logbook.delete')" @click="removeCategory(c)" :disabled="(c.trips_count ?? 0) > 0" :title="(c.trips_count ?? 0) > 0 ? t('logbook.cat_delete_blocked') : ''" class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-danger-500">{{ t('common.delete') }}</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 overflow-hidden">
        <div v-for="c in categories" :key="`m-${c.id}`" class="px-4 py-3" :class="c.is_archived ? 'opacity-50' : ''">
          <div class="flex items-baseline justify-between gap-2">
            <span class="font-medium text-neutral-900">
              {{ c.label }}
              <span v-if="c.is_default" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700">{{ t('logbook.default_short') }}</span>
            </span>
            <span class="text-xs px-1.5 py-0.5 rounded shrink-0" :class="c.is_private ? 'bg-warning-50 text-warning-600' : 'bg-success-50 text-success-600'">
              {{ c.is_private ? t('logbook.private') : t('logbook.business') }}
            </span>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span class="font-mono">{{ c.code }}</span>
            <span>{{ t('logbook.tab_trips') }}: {{ c.trips_count ?? 0 }}</span>
          </div>
          <div v-if="auth.canWrite('logbook.write') || auth.canWrite('logbook.delete')" class="flex gap-4 mt-2 text-xs">
            <button v-if="auth.canWrite('logbook.write')" @click="editCategory(c)" class="cursor-pointer text-primary-600 hover:text-primary-700">{{ t('common.edit') }}</button>
            <button v-if="auth.canWrite('logbook.delete')" @click="removeCategory(c)" :disabled="(c.trips_count ?? 0) > 0" :title="(c.trips_count ?? 0) > 0 ? t('logbook.cat_delete_blocked') : ''" class="cursor-pointer ml-auto text-danger-500 hover:text-danger-600 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-danger-500">{{ t('common.delete') }}</button>
          </div>
        </div>
      </div>
    </template>

    <!-- Modal -->
    <div v-if="open" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <form @submit.prevent="save" class="p-5 space-y-4">
          <h2 class="text-lg font-semibold">{{ draft.id ? t('logbook.cat_edit') : t('logbook.cat_new') }}</h2>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.cat_code') }} *</label>
              <input v-model="draft.code" type="text" maxlength="30" required placeholder="business" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.cat_label') }} *</label>
              <input v-model="draft.label" type="text" maxlength="100" required placeholder="Služební" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="draft.is_private" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('logbook.cat_is_private') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="draft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('logbook.cat_is_default') }}
          </label>
          <label v-if="draft.id" class="flex items-center gap-2 text-sm">
            <input v-model="draft.is_archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('logbook.archived') }}
          </label>
          <div class="flex justify-end gap-2 pt-2 border-t border-neutral-100">
            <button type="button" @click="open = false" class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :disabled="saving" class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50 inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              {{ t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>
