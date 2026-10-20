<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { propertyOpeningApi as api, type OpeningRequest, type OpeningBatch, type OpeningPreview } from '@/api/propertyOpening'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { propertyError } from '@/components/property/propertyError'
import { propertyDateInput as dateInput, propertyInput as input } from '@/components/property/propertyFormStyles'
const { t } = useI18n()
const supplier = computed(() => useSupplierStore().currentSupplierId)
const empty = (): OpeningRequest => ({ mode: 'post_new', accounting_date: '', effective_at: '', expected_revision: 0, evidence_ref: '', reason: '', items: [], other_balances: [] })
const form = reactive<OpeningRequest>(empty())
const history = ref<OpeningBatch[]>([])
const preview = ref<OpeningPreview | null>(null)
const capabilities = reactive({ manage: false, post: false, revise: false })
const busy = ref(false), error = ref(''), notice = ref('')
let epoch = 0, token = crypto.randomUUID(), submitted: OpeningRequest | null = null
function invalidate() { epoch++; preview.value = null; submitted = null; token = crypto.randomUUID(); notice.value = '' }
watch(form, invalidate, { deep: true, flush: 'sync' })
onBeforeUnmount(() => epoch++)
async function load() {
 const id = supplier.value; invalidate(); const ticket = epoch; history.value = []; error.value = ''; Object.assign(capabilities, { manage: false, post: false, revise: false }); if (!id) return
 busy.value = true
 try { const data = await api.list(id); if (ticket === epoch && supplier.value === id) { history.value = data.items; Object.assign(capabilities, data.capabilities) } }
 catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (ticket === epoch && supplier.value === id) busy.value = false }
}
watch(supplier, () => { Object.assign(form, empty()); void load() }, { immediate: true })
async function importFile(event: Event) {
 const file = (event.target as HTMLInputElement).files?.[0]; invalidate(); const ticket = epoch; error.value = ''
 if (!file) return
 if (file.size > 5_000_000 || !file.name.toLowerCase().endsWith('.json')) { error.value = t('propertyOpening.invalidFile'); return }
 try { const data = JSON.parse(await file.text()) as { format?: string; request?: OpeningRequest }; if (ticket !== epoch) return; if (data.format !== 'myucto.property.opening.v1' || !data.request || !Array.isArray(data.request.items) || data.request.items.length > 10000 || !data.request.items.every(item => item && typeof item === 'object' && typeof item.case_ref === 'string' && typeof item.occurrence_ref === 'string' && ['debt', 'credit', 'advance', 'service_carry'].includes(item.kind) && Array.isArray(item.components) && item.components.every(part => part && typeof part.key === 'string' && typeof part.role === 'string' && typeof part.account_code === 'string' && typeof part.amount_cents === 'string'))) throw new Error(); Object.assign(form, empty(), data.request) }
 catch { if (ticket === epoch) error.value = t('propertyOpening.invalidFile') }
}
function download(request: OpeningRequest) {
 const copy = JSON.parse(JSON.stringify(request)) as OpeningRequest
 for (const item of copy.items) { delete item.case_key; delete item.amount_cents; for (const part of item.components) delete (part as unknown as Record<string, unknown>).side }
 const blob = new Blob([JSON.stringify({ format: 'myucto.property.opening.v1', request: copy }, null, 2)], { type: 'application/json' }); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'myucto-opening.json'; link.click(); URL.revokeObjectURL(url)
}
function select(batch: OpeningBatch, mode: 'revision' | 'reversal') {
 const data = JSON.parse(JSON.stringify(batch.request)) as OpeningRequest
 for (const item of data.items) { delete item.case_key; delete item.amount_cents; for (const part of item.components) delete (part as unknown as Record<string, unknown>).side }
 Object.assign(form, data, { mode, expected_revision: batch.revision_no, journal_entry_id: null, journal_version: null, reason: '', evidence_ref: '' }); if (mode === 'reversal') { form.items = []; form.other_balances = [] }
}
async function showPreview() {
 if (!supplier.value || busy.value) return
 const id = supplier.value, ticket = epoch, request = JSON.parse(JSON.stringify(form)) as OpeningRequest; busy.value = true; error.value = ''
 try { const result = await api.preview(id, request); if (ticket === epoch && supplier.value === id) { submitted = request; preview.value = result } }
 catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (ticket === epoch && supplier.value === id) busy.value = false }
}
async function confirm() {
 if (!supplier.value || !preview.value || !submitted || busy.value) return
 const id = supplier.value, ticket = epoch, request = submitted, hash = preview.value.preview_hash; busy.value = true; error.value = ''
 try { await api.confirm(id, request, hash, token); if (ticket === epoch && supplier.value === id) { Object.assign(form, empty()); await load(); notice.value = t('propertyOpening.saved') } }
 catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (ticket === epoch && supplier.value === id) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [preview.value ? { key: 'confirm', label: t('propertyOpening.confirm'), icon: 'check', variant: 'success', tier: 'primary', disabled: busy.value || (['revision', 'reversal'].includes(form.mode) ? !capabilities.revise : !capabilities.post), run: () => { void confirm() } } : { key: 'preview', label: t('propertyOpening.preview'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: busy.value || !capabilities.manage, run: () => { void showPreview() } }, { key: 'export', label: t('propertyOpening.download'), icon: 'download', tier: 'secondary', disabled: busy.value, run: () => download(JSON.parse(JSON.stringify(form)) as OpeningRequest) }, { key: 'refresh', label: t('propertyOpening.refresh'), icon: 'cycle', tier: 'secondary', disabled: busy.value, run: () => { void load() } }])
</script>
<template>
 <div class="space-y-5">
  <div><h1 class="text-2xl font-semibold">{{ t('propertyOpening.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('propertyOpening.help') }}</p></div>
  <p v-if="error" role="alert" class="rounded border border-danger-300 bg-danger-50 p-3 text-danger-700">{{ error }}</p>
  <p v-if="notice" role="status" class="rounded border p-3">{{ notice }}</p>
  <section class="space-y-4 rounded-lg border bg-surface p-4">
   <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyOpening.file') }}<input type="file" accept=".json,application/json" :disabled="busy || !capabilities.manage" class="mt-1 block w-full" @change="importFile" /></label>
   <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyOpening.mode') }}<select v-model="form.mode" :class="input" :disabled="busy"><option v-for="mode in ['post_new', 'bind_existing', 'revision', 'reversal']" :key="mode" :value="mode">{{ t(`propertyOpening.modes.${mode}`) }}</option></select></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyOpening.accountingDate') }}<DateInput :class="dateInput" v-model="form.accounting_date" :disabled="busy" /></label>
    <fieldset :disabled="busy" class="min-w-0"><PropertyInstantInput v-model="form.effective_at" :label="t('propertyOpening.effectiveAt')" /></fieldset>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyOpening.revision') }}<input v-model.number="form.expected_revision" type="number" min="0" :class="input" :disabled="busy" /></label>
    <template v-if="form.mode === 'bind_existing'"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyOpening.journal') }}<input v-model.number="form.journal_entry_id" type="number" min="1" :class="input" :disabled="busy" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyOpening.journalVersion') }}<input v-model.number="form.journal_version" type="number" min="1" :class="input" :disabled="busy" /></label></template>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyOpening.evidence') }}<input v-model="form.evidence_ref" :class="input" :disabled="busy" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyOpening.reason') }}<input v-model="form.reason" :class="input" :disabled="busy" /></label>
   </div>
   <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>{{ t('propertyOpening.case') }}</th><th>{{ t('propertyOpening.kind') }}</th><th>{{ t('propertyOpening.unit') }}</th><th>{{ t('propertyOpening.service') }}</th><th>{{ t('propertyOpening.roles') }}</th></tr></thead><tbody><tr v-for="(item, index) in form.items" :key="index" class="border-t"><td class="p-2">{{ item.case_ref }} / {{ item.occurrence_ref }}</td><td>{{ t(`propertyOpening.kinds.${item.kind}`) }}</td><td>{{ item.unit_id }}</td><td>{{ item.service_id }}</td><td><div v-for="part in item.components" :key="part.key">{{ part.account_code }} · {{ t(`propertyOpening.rolesList.${part.role}`) }} · {{ part.amount_cents }} {{ t('propertyOpening.cents') }}</div></td></tr></tbody></table></div>
   <ActionBar :actions="actions" />
  </section>
  <section v-if="preview" class="rounded-lg border bg-surface p-4"><h2 class="font-semibold">{{ t('propertyOpening.preview') }}</h2><p class="text-sm">{{ t('propertyOpening.previewHelp') }}</p><div v-for="part in ['reversal', 'journal'] as const" :key="part"><h3 v-if="preview.snapshot[part]" class="mt-3 font-medium">{{ t(`propertyOpening.${part}`) }}</h3><div v-for="(line, index) in preview.snapshot[part]?.lines ?? []" :key="index" class="flex flex-wrap gap-4 border-t py-1"><span>{{ line.account_code }}</span><span>{{ t(`propertyOpening.${line.side}`) }}</span><span>{{ line.amount }}</span></div></div></section>
  <section class="space-y-2"><h2 class="font-semibold">{{ t('propertyOpening.history') }}</h2><article v-for="batch in history" :key="batch.id" class="flex flex-wrap items-center gap-3 rounded-lg border bg-surface p-3"><span>#{{ batch.id }} · {{ batch.accounting_date }} · {{ t(`propertyOpening.statuses.${batch.status}`) }} · {{ t('propertyOpening.revision') }} {{ batch.revision_no }}</span><div class="flex flex-wrap gap-2"><PropertyButton icon="download" @click="download(batch.request)">{{ t('propertyOpening.download') }}</PropertyButton><PropertyButton v-if="batch.status === 'posted' && batch.mode !== 'reversal' && capabilities.revise" icon="edit" variant="warning" :disabled="busy" @click="select(batch, 'revision')">{{ t('propertyOpening.revise') }}</PropertyButton><PropertyButton v-if="batch.status === 'posted' && batch.mode !== 'reversal' && capabilities.revise" icon="trash" variant="danger" :disabled="busy" @click="select(batch, 'reversal')">{{ t('propertyOpening.reverse') }}</PropertyButton></div></article></section>
 </div>
</template>
