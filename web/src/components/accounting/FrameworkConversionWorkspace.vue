<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import FrameworkConversionDecision from './FrameworkConversionDecision.vue'
import { frameworkConversionMessages } from './frameworkConversionMessages'
import { frameworkConversionApi as api, type CancellationReview, type ConversionChoices, type ConversionCommand, type ConversionDefinition, type ConversionMapping, type ConversionPage, type ConversionReview } from '@/api/frameworkConversion'
const props = defineProps<{ supplier: number }>()
const i18n = useI18n({ useScope: 'local', inheritLocale: true, messages: frameworkConversionMessages })
const label = (key: string) => i18n.t(`conversion.${key}`)
const page = ref<ConversionPage>({ periods: [], rows: [], financial_enabled: false, can_write: false })
const source = ref(''), target = ref(''), evidence = ref(''), choices = ref<ConversionChoices | null>(null)
const definitions = ref<ConversionDefinition[]>([]), mapping = ref<Record<string, ConversionMapping>>({})
const preview = ref<ConversionReview | null>(null), reviewed = ref<ConversionCommand | null>(null), token = ref(''), ack = ref(false), busy = ref(false), error = ref(''), notice = ref(''), uncertain = ref(false)
const cancellation = ref<CancellationReview | null>(null), cancellationId = ref<number | null>(null), cancellationToken = ref(''), cancellationAck = ref(false), decisionId = ref<number | null>(null)
let alive = true, generation = 0
onBeforeUnmount(() => { alive = false; generation++ })
const enabled = computed(() => page.value.financial_enabled && page.value.can_write)
const command = computed<ConversionCommand | null>(() => choices.value ? { source_period_id: Number(source.value), target_period_id: Number(target.value), evidence: evidence.value, definitions: definitions.value, mapping: mapping.value } : null)
function invalidate() { preview.value = null; reviewed.value = null; token.value = ''; ack.value = false; uncertain.value = false }
watch(command, invalidate, { deep: true })
watch([source, target], () => { generation++; choices.value = null; definitions.value = []; mapping.value = {}; busy.value = false; invalidate() })
const boundary = computed(() => page.value.periods.find(p => Number(p.id) === Number(target.value))?.starts_on || '')
const sources = computed(() => page.value.periods.filter(p => ['closed','reviewed','approved'].includes(p.status)))
const targets = computed(() => page.value.periods.filter(p => p.status === 'open'))
function message(e: unknown) {
 const response = (e as { response?: { status?: number; data?: { error?: { code?: string } } } }).response
 const code = response?.data?.error?.code || ''
 if (code === 'conversion_svj_adoption_required') return label('svj')
 if (code === 'conversion_native_subledger_adoption_required') return label('subledger')
 if (code === 'conversion_source_approval_required') return label('sourceApproval')
 if (code === 'conversion_native_followup_required') return label('followup')
 if (code === 'conversion_offbalance_adoption_required') return label('offbalance')
 if (code === 'conversion_integration_required') return label('integration')
 if (['conversion_already_consumed','conversion_configuration_consumed'].includes(code)) return label('consumed')
 if (response?.status === 409) return label('stale')
 return label('error')
}
async function refresh() {
 const ticket = ++generation; busy.value = true; error.value = ''
 try { const result = await api.list(props.supplier); if (alive && ticket === generation) page.value = result }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function load() {
 if (!source.value || !target.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const result = await api.choices(props.supplier,Number(source.value),Number(target.value)); if (!alive || ticket !== generation) return; choices.value = result; definitions.value = structuredClone(result.definitions); mapping.value = Object.fromEntries(result.cells.map(c => [c.cell_key, { account_code: '', activity: '', activity_kind: { code: '', name: '' }, tax_regime: '' }])) }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function review() {
 if (!enabled.value || !command.value || busy.value) return
 const candidate = JSON.parse(JSON.stringify(command.value)) as ConversionCommand, ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const result = await api.preview(props.supplier,candidate); if (!alive || ticket !== generation) return; reviewed.value = candidate; preview.value = result; token.value = crypto.randomUUID() }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function confirm() {
 if (!enabled.value || !reviewed.value || !preview.value || !ack.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''
 try { await api.confirm(props.supplier,reviewed.value,preview.value,token.value); if (!alive || ticket !== generation) return; invalidate(); notice.value = label('done'); choices.value = null; await refresh() }
 catch (e) { if (!alive || ticket !== generation) return; const status = (e as { response?: { status?: number } }).response?.status; if ([400,401,403,404,409,422].includes(status || 0)) { invalidate(); error.value = message(e) } else { uncertain.value = true; error.value = label('uncertain') } }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function reviewCancellation(id: number) {
 if (!enabled.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''; cancellation.value = null; cancellationAck.value = false
 try { const result = await api.cancellationPreview(props.supplier,id); if (!alive || ticket !== generation) return; cancellation.value = result; cancellationId.value = id; cancellationToken.value = crypto.randomUUID() }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function cancel() {
 if (!enabled.value || !cancellation.value || !cancellationId.value || !cancellationAck.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''
 try { await api.cancel(props.supplier,cancellationId.value,cancellation.value,cancellationToken.value); if (!alive || ticket !== generation) return; cancellation.value = null; notice.value = label('done'); await refresh() }
 catch (e) { if (!alive || ticket !== generation) return; if ((e as { response?: { status?: number } }).response?.status) { cancellation.value = null; error.value = message(e) } else error.value = label('uncertain') }
 finally { if (alive && ticket === generation) busy.value = false }
}
function accountChoices(type: string, role: string) { return definitions.value.filter(d => d.definition.is_active === 1 && d.account_type === type && (role === 'pending_approval' ? d.account_code === '931' : role === 'retained' ? d.account_code === '932' : d.account_code !== '931')) }
const actions = computed<ActionItem[]>(() => preview.value ? [ { key: 'confirm', label: label('confirm'), icon: 'check', tier: 'primary', variant: 'success', disabled: busy.value || !enabled.value || !ack.value, run: confirm }, { key: 'cancel-review', label: label('cancelReview'), icon: 'x', variant: 'neutral', run: invalidate } ] : [ { key: 'preview', label: label('preview'), icon: 'search', tier: 'primary', variant: 'primary', disabled: busy.value || !enabled.value || !command.value, run: review } ])
onMounted(refresh)
</script>
<template>
 <section class="space-y-5">
  <header><h1 class="text-2xl font-semibold">{{ label('title') }}</h1><p class="text-sm text-gray-500 dark:text-gray-400">{{ label('intro') }}</p></header>
  <p v-if="!page.financial_enabled" role="status" class="rounded-lg bg-amber-50 p-4 text-amber-900 dark:bg-amber-950 dark:text-amber-200">{{ label('integration') }}</p>
  <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-4 text-red-800 dark:bg-red-950 dark:text-red-200">{{ error }}</p><p v-if="notice" role="status">{{ notice }}</p>
  <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
   <div class="flex flex-wrap items-end gap-4">
    <label>{{ label('source') }}<select v-model="source" data-field="source" class="input"><option value="">{{ label('choose') }}</option><option v-for="p in sources" :key="p.id" :value="String(p.id)">{{ p.fiscal_year }} ({{ p.starts_on }} - {{ p.ends_on }})</option></select></label>
    <label>{{ label('target') }}<select v-model="target" data-field="target" class="input"><option value="">{{ label('choose') }}</option><option v-for="p in targets" :key="p.id" :value="String(p.id)">{{ p.fiscal_year }} ({{ p.starts_on }} - {{ p.ends_on }})</option></select></label>
    <label>{{ label('boundary') }}<DateInput :model-value="boundary" readonly data-field="boundary" /></label>
    <ActionBar :actions="[{ key: 'load', label: label('load'), icon: 'search', variant: 'neutral', disabled: busy || !source || !target, run: load }]" />
   </div>
  </div>
  <div v-if="choices" class="space-y-4">
   <label class="block">{{ label('evidence') }}<textarea v-model="evidence" data-field="evidence" required maxlength="1000" class="input w-full" /></label>
   <article v-for="cell in choices.cells" :key="cell.cell_key" :data-cell="cell.cell_key" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
    <h2 class="font-semibold">{{ cell.role === 'pending_approval' ? label('pending_approval') : cell.account_code }} · {{ (cell.czk_cents / 100).toFixed(2) }} CZK</h2><p>{{ label(cell.role === 'retained' ? 'retainedRole' : cell.role) }}<span v-if="cell.currency_code !== 'CZK'"> · {{ (cell.foreign_cents / 100).toFixed(2) }} {{ cell.currency_code }}</span></p>
    <div v-if="choices.source_labels[cell.cell_key]" class="mt-2 text-sm text-gray-600 dark:text-gray-300"><p v-if="choices.source_labels[cell.cell_key]!.cost_center">{{ label('costCenter') }}: {{ choices.source_labels[cell.cell_key]!.cost_center }}</p><p v-if="choices.source_labels[cell.cell_key]!.project">{{ label('project') }}: {{ choices.source_labels[cell.cell_key]!.project }}</p><p v-for="sourceLabel in choices.source_labels[cell.cell_key]!.dimensions" :key="sourceLabel">{{ sourceLabel }}</p></div>
    <div v-if="mapping[cell.cell_key]" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
     <label>{{ label('targetAccount') }}<select v-model="mapping[cell.cell_key]!.account_code" data-field="account" class="input"><option value="">{{ label('choose') }}</option><option v-for="d in accountChoices(cell.account_type,cell.role)" :key="d.account_code" :value="d.account_code">{{ d.account_code }} {{ d.definition.name }}</option></select></label>
     <label>{{ label('activity') }}<select v-model="mapping[cell.cell_key]!.activity" data-field="activity" class="input"><option value="">{{ label('choose') }}</option><option value="main">{{ label('main') }}</option><option value="economic">{{ label('economic') }}</option></select></label>
     <label>{{ label('tax') }}<select v-model="mapping[cell.cell_key]!.tax_regime" data-field="tax" class="input"><option value="">{{ label('choose') }}</option><option v-for="key in ['subject','out_of_scope','exempt']" :key="key" :value="key">{{ label(key) }}</option></select></label>
     <label>{{ label('activityCode') }}<input v-model="mapping[cell.cell_key]!.activity_kind.code" data-field="kind-code" maxlength="50" class="input" /></label><label>{{ label('activityName') }}<input v-model="mapping[cell.cell_key]!.activity_kind.name" data-field="kind-name" maxlength="100" class="input" /></label>
    </div>
   </article>
   <details class="rounded-xl border border-gray-200 p-4 dark:border-gray-700"><summary class="font-semibold">{{ label('definitions') }}</summary><p class="my-3 text-sm">{{ label('definitionHelp') }}</p>
    <article v-for="definition in definitions" :key="definition.account_code" class="grid gap-3 border-t border-gray-200 py-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-gray-700">
     <div><strong>{{ definition.account_code }}</strong><p class="text-sm">{{ label('before') }}: {{ choices.before_definitions[definition.account_code]?.name || label('newAccount') }} · {{ label(choices.before_definitions[definition.account_code]?.normal_side || 'both') }}</p></div>
     <label>{{ label('name') }}<input v-model="definition.definition.name" class="input" maxlength="190" /></label><label>{{ label('normalSide') }}<select v-model="definition.definition.normal_side" class="input"><option :value="null">{{ label('both') }}</option><option value="debit">{{ label('debit') }}</option><option value="credit">{{ label('credit') }}</option></select></label>
     <div><label><input v-model="definition.definition.is_active" type="checkbox" :true-value="1" :false-value="0" /> {{ label('active') }}</label><label>{{ label('taxDeductibility') }}<select v-model="definition.definition.tax_deductibility" class="input"><option value="deductible">{{ label('deductible') }}</option><option value="non_deductible">{{ label('non_deductible') }}</option></select></label></div>
    </article>
   </details>
   <div v-if="preview" data-section="preview" class="rounded-xl border border-green-200 p-4 dark:border-green-800"><div v-for="(line,index) in preview.plan.lines" :key="index">{{ line.account_code }} · {{ label(line.side) }} · {{ line.amount }} CZK <span v-if="line.currency_code">· {{ line.amount_foreign }} {{ line.currency_code }}</span></div><label class="mt-4 flex items-start gap-2"><input v-model="ack" data-field="ack" type="checkbox" />{{ label('acknowledgement') }}</label></div>
   <ActionBar :actions="actions" /><p v-if="uncertain" role="status">{{ label('uncertain') }}</p>
  </div>
  <section v-if="page.rows.length" class="space-y-3"><h2 class="text-lg font-semibold">{{ label('records') }}</h2><article v-for="row in page.rows" :key="row.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700"><span>{{ row.effective_from }} · {{ label(row.status) }}</span><ActionBar v-if="row.status === 'applied'" :actions="[{ key: 'decision', label: label('decision'), icon: 'check', variant: 'primary', disabled: busy || !enabled, run: () => { decisionId = row.id } }, { key: 'cancel', label: label('cancellation'), icon: 'trash', variant: 'danger', disabled: busy || !enabled, run: () => reviewCancellation(row.id) }]" /></article></section>
  <div v-if="cancellation" data-section="cancellation" class="space-y-3 rounded-xl border border-red-200 p-4"><label class="flex gap-2"><input v-model="cancellationAck" data-field="cancellation-ack" type="checkbox" />{{ label('cancelAck') }}</label><ActionBar :actions="[{ key: 'confirm-cancellation', label: label('confirmCancellation'), icon: 'trash', variant: 'danger', disabled: busy || !enabled || !cancellationAck, run: cancel }]" /></div>
  <FrameworkConversionDecision v-if="decisionId" :key="decisionId" :supplier="supplier" :conversion="decisionId" :enabled="enabled" @done="refresh" />
 </section>
</template>
