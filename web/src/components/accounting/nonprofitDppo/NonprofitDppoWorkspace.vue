<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { formDateInput as dateInput, formInput as input, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import NonprofitDppoReductionEditor from './NonprofitDppoReductionEditor.vue'
import { nonprofitDppoApi as api } from '@/api/nonprofitDppo'
import type { AccountingPeriod } from '@/api/accounting'
import type { LegalConfirm, LegalKind, LegalOverview, NonprofitDppoState, NonprofitOperationKey, SavingConfirm, SavingOverview, SavingPlan, SavingReverseConfirm, SavingSource, SavingUseInput } from '@/api/nonprofitDppoTypes'
const props = defineProps<{ supplier: number; canManage: boolean; canEditReturn?: boolean }>()
const { t, locale } = useI18n()
const periods = ref<AccountingPeriod[]>([]), period = ref(0), legal = ref<LegalOverview | null>(null), savings = ref<SavingOverview | null>(null), native = ref<NonprofitDppoState | null>(null)
const busy = ref(false), error = ref(''), notice = ref(''), uncertain = ref(false), acknowledged = ref(false), mode = ref<'profile' | 'use' | 'reverse'>('profile')
const kind = ref<LegalKind | ''>(''), entity = ref(''), nonbusiness = ref(false), legalBasis = ref(''), evidence = ref(''), reason = ref('')
const sourceId = ref(0), costId = ref(0), purpose = ref(''), amount = ref(''), eventId = ref(0), useEvidence = ref(''), reverseReason = ref(''), plan = ref<SavingPlan | null>(null)
type Pending = { kind: 'profile'; supplier: number; period: number; body: LegalConfirm } | { kind: 'use'; supplier: number; source: number; body: SavingConfirm } | { kind: 'reverse'; supplier: number; source: number; event: number; body: SavingReverseConfirm }
const pending = ref<Pending | null>(null)

let alive = true, generation = 0, revision = 0
const selectedPeriod = computed(() => periods.value.find(p => p.id === period.value))
const source = computed(() => savings.value?.sources.find(s => s.source.id === sourceId.value))
const cost = computed(() => source.value?.eligible_costs.find(c => c.cost_line_id === costId.value))
const reversible = computed(() => source.value?.events.filter(e => e.operation === 'use' && !source.value?.events.some(r => r.operation === 'reverse_use' && r.original_id === e.id)) || [])
const profileWritable = computed(() => props.canManage && legal.value?.capabilities.can_confirm === true)
const savingsWritable = computed(() => props.canManage && savings.value?.capabilities.can_confirm === true)
const locked = computed(() => busy.value || uncertain.value)
type Capabilities = LegalOverview['capabilities']
const operationKeys: NonprofitOperationKey[] = ['compute', 'finalize', 'export_xml', 'submit']
function canLoadNative(c: Capabilities) { return c.native_dppo === true && (c.calculation_ready ?? c.filing_ready) === true && c.operations?.compute?.allowed !== false }
function canFinalize(c: Capabilities) { return c.operations?.finalize ? c.operations.finalize.allowed === true : true }
function money(cents: string | number) { return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(Number(cents) / 100) }
function kc(value: number) { return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(value) }
function cents(value: string): string | null { const m = /^(\d+)(?:[,.](\d{1,2}))?$/.exec(value.trim()); if (!m) return null; const n = BigInt(m[1]!) * 100n + BigInt((m[2] || '').padEnd(2, '0')); return n > 0n ? String(n) : null }
function message(e: unknown) { const r = (e as { response?: { status?: number; data?: { error?: { message?: string } } } }).response; return r?.data?.error?.message || (r?.status === 409 ? t('nonprofitDppo.stale') : t('nonprofitDppo.error')) }
function resetReview() { revision++; if (uncertain.value) return; pending.value = null; plan.value = null; acknowledged.value = false }
watch([kind, entity, nonbusiness, legalBasis, evidence, reason, sourceId, costId, purpose, amount, eventId, useEvidence, reverseReason, mode], resetReview, { flush: 'sync' })
watch(sourceId, () => { costId.value = 0; eventId.value = 0; purpose.value = ''; amount.value = '' })
watch(costId, () => { purpose.value = '' })
onBeforeUnmount(() => { alive = false; generation++ })
function current(ticket: number, supplier: number) { return alive && ticket === generation && supplier === props.supplier }
async function loadProfile() {
 const supplier = props.supplier, selected = period.value, ticket = ++generation; legal.value = null; native.value = null; resetReview(); if (!selected) return
 busy.value = true; error.value = ''
 try { const data = await api.profile(supplier, selected); if (!current(ticket, supplier)) return; legal.value = data; kind.value = data.latest?.profile.kind || ''; entity.value = data.latest?.profile.entity_type || ''; nonbusiness.value = data.latest?.profile.main_activity_nonbusiness || false; legalBasis.value = data.latest?.profile.legal_basis || ''; evidence.value = data.latest?.profile.evidence || ''; reason.value = ''
  if (canLoadNative(data.capabilities)) { const result = await api.native(supplier, data.period.fiscal_year); if (current(ticket, supplier)) native.value = result }
 } catch (e) { if (current(ticket, supplier)) error.value = message(e) } finally { if (current(ticket, supplier)) busy.value = false }
}
async function reload() {
 if (uncertain.value) return
 const supplier = props.supplier, ticket = ++generation; busy.value = true; error.value = ''; legal.value = null; native.value = null; savings.value = null; resetReview()
 try { const [available, overview] = await Promise.all([api.periods(supplier), api.savings(supplier)]); if (!current(ticket, supplier)) return; periods.value = available; savings.value = overview; const next = available.some(p => p.id === period.value) ? period.value : available[0]?.id || 0; if (period.value === next) await loadProfile(); else period.value = next }
 catch (e) { if (current(ticket, supplier)) error.value = message(e) } finally { if (current(ticket, supplier)) busy.value = false }
}
watch(period, loadProfile)
watch(() => props.supplier, () => { generation++; uncertain.value = false; pending.value = null; period.value = 0; periods.value = []; sourceId.value = 0; legal.value = null; savings.value = null; native.value = null; void reload() }, { immediate: true })
function profileReview() {
 if (!profileWritable.value || locked.value || !kind.value || !legal.value?.kinds.includes(kind.value) || !legal.value.entity_types.includes(entity.value) || !legalBasis.value.trim() || !evidence.value.trim() || !reason.value.trim()) return
 mode.value = 'profile'; pending.value = { kind: 'profile', supplier: props.supplier, period: period.value, body: { expected_revision: legal.value.latest?.revision_no || 0, profile: { kind: kind.value, entity_type: entity.value, main_activity_nonbusiness: nonbusiness.value, legal_basis: legalBasis.value.trim(), evidence: evidence.value.trim() }, reason: reason.value.trim(), idempotency_key: crypto.randomUUID() } }; acknowledged.value = false
}
async function reviewSaving(reverse = false) {
 const selected = source.value, amountCents = cents(amount.value)
 if (!selected || !savingsWritable.value || locked.value || (!reverse && (selected.void || !selected.eligibility.ready || !cost.value || !cost.value.allowed_purposes.includes(purpose.value) || !amountCents || !useEvidence.value.trim())) || (reverse && (!reversible.value.some(e => e.id === eventId.value) || !reverseReason.value.trim()))) return
 mode.value = reverse ? 'reverse' : 'use'; const supplier = props.supplier, ticket = ++generation, edit = revision; busy.value = true; error.value = ''; pending.value = null; acknowledged.value = false
 const use: SavingUseInput = { cost_line_id: costId.value, amount_cents: amountCents || '0', purpose: purpose.value, evidence: useEvidence.value.trim() }, why = reverseReason.value.trim(), selectedEvent = eventId.value
 try { const preview = reverse ? await api.previewReverse(supplier, selected.source.id, selectedEvent, why) : await api.previewUse(supplier, selected.source.id, use); if (!current(ticket, supplier) || edit !== revision) return; if (preview.source_id !== selected.source.id || preview.head_hash !== selected.head_hash || preview.operation !== (reverse ? 'reverse_use' : 'use') || (!reverse && (preview.cost_line_id !== use.cost_line_id || preview.amount_cents !== use.amount_cents || preview.period_id !== cost.value?.period_id))) throw new Error('preview_changed'); plan.value = preview
  pending.value = reverse ? { kind: 'reverse', supplier, source: selected.source.id, event: selectedEvent, body: { reason: why, preview_hash: preview.preview_hash, expected_head_hash: preview.head_hash, idempotency_key: crypto.randomUUID() } } : { kind: 'use', supplier, source: selected.source.id, body: { input: use, preview_hash: preview.preview_hash, expected_head_hash: preview.head_hash, idempotency_key: crypto.randomUUID() } }
 } catch (e) { if (current(ticket, supplier)) error.value = message(e) } finally { if (current(ticket, supplier)) busy.value = false }
}
async function confirm() {
 const request = pending.value; if (!request || busy.value || !props.canManage || (!uncertain.value && !acknowledged.value) || request.supplier !== props.supplier || (request.kind === 'profile' ? !profileWritable.value : !savingsWritable.value)) return
 const supplier = props.supplier, ticket = ++generation; busy.value = true; error.value = ''
 try { if (request.kind === 'profile') await api.confirmProfile(request.supplier, request.period, request.body); else if (request.kind === 'use') await api.confirmUse(request.supplier, request.source, request.body); else await api.confirmReverse(request.supplier, request.source, request.event, request.body); if (!current(ticket, supplier)) return; uncertain.value = false; pending.value = null; notice.value = t('nonprofitDppo.saved'); busy.value = false; await reload() }
 catch (e) { if (!current(ticket, supplier)) return; const status = (e as { response?: { status?: number } }).response?.status; uncertain.value = !status || status >= 500; error.value = uncertain.value ? t('nonprofitDppo.uncertain') : message(e); if (!uncertain.value) { pending.value = null; plan.value = null; acknowledged.value = false } }
 finally { if (current(ticket, supplier)) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [{ key: 'confirm', label: t(uncertain.value ? 'nonprofitDppo.retry' : 'nonprofitDppo.confirm'), icon: 'check', variant: 'success', tier: 'primary', show: Boolean(pending.value), disabled: busy.value || (!uncertain.value && !acknowledged.value) || !props.canManage, run: confirm }, { key: 'refresh', label: t('nonprofitDppo.refresh'), icon: 'search', variant: 'neutral', tier: 'secondary', disabled: locked.value, run: reload }])
const profileActions = computed<ActionItem[]>(() => [{ key: 'review-profile', label: t('nonprofitDppo.reviewProfile'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: locked.value || !profileWritable.value, run: profileReview }])
const savingActions = computed<ActionItem[]>(() => [{ key: 'preview-use', label: t('nonprofitDppo.previewUse'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: locked.value || !savingsWritable.value || !source.value?.eligibility.ready || source.value?.void, run: () => reviewSaving() }, { key: 'preview-reverse', label: t('nonprofitDppo.previewReverse'), icon: 'uturn', variant: 'danger', tier: 'secondary', disabled: locked.value || !savingsWritable.value || !eventId.value, run: () => reviewSaving(true) }])
const nativeProofMatches = computed(() => legal.value?.capabilities.native_dppo === true && Boolean(native.value?.nonprofit_filing_proof_hash && native.value.computed.nonprofit) && native.value?.return.type === 'po' && native.value.return.year === legal.value.period.fiscal_year && native.value.podklady.accounting_decree === '504' && (native.value.podklady.period as { id?: number } | undefined)?.id === legal.value.period.id)
const filingReady = computed(() => Boolean(legal.value && nativeProofMatches.value && legal.value.capabilities.filing_ready === true && canFinalize(legal.value.capabilities)))
const operations = computed(() => operationKeys.flatMap(key => { const op = legal.value?.capabilities.operations?.[key]; return op ? [{ key, ...op }] : [] }))
const formManifest = computed(() => legal.value?.capabilities.form_manifest || null)
const filingActions = computed<ActionItem[]>(() => [{ key: 'open-filing', label: t('nonprofitDppo.openFiling'), icon: 'doc', variant: 'primary', tier: 'primary', show: filingReady.value, to: { name: 'reports-income-tax', query: { type: 'po', year: String(legal.value?.period.fiscal_year), tab: 'nahled' } } }])
function sourceLabel(s: SavingSource) { return `${s.source.basis.period.starts_on} - ${s.source.basis.period.ends_on} · ${money(s.remaining_cents)}` }
</script>
<template>
 <section class="space-y-6" :aria-busy="busy">
  <header><h1 class="text-2xl font-bold">{{ t('nonprofitDppo.title') }}</h1><p class="text-sm text-neutral-500">{{ t('nonprofitDppo.intro') }}</p></header>
  <p v-if="error" role="alert" class="rounded border border-danger p-3 text-danger">{{ error }}</p><p v-if="notice" role="status" class="rounded border border-success p-3">{{ notice }}</p>
  <ActionBar :actions="actions" />
  <label :class="[formLabel, 'max-w-lg']">{{ t('nonprofitDppo.period') }}<select v-model.number="period" data-field="period" :class="input" :disabled="locked"><option :value="0">{{ t('nonprofitDppo.choose') }}</option><option v-for="p in periods" :key="p.id" :value="p.id">{{ p.starts_on }} - {{ p.ends_on }}</option></select></label>
  <div v-if="selectedPeriod" class="grid gap-3 sm:grid-cols-2"><label :class="formLabel">{{ t('nonprofitDppo.from') }}<DateInput :class="dateInput" :model-value="selectedPeriod.starts_on" readonly /></label><label :class="formLabel">{{ t('nonprofitDppo.to') }}<DateInput :class="dateInput" :model-value="selectedPeriod.ends_on" readonly /></label></div>
  <article v-if="legal" class="space-y-4 rounded-xl border bg-surface p-5">
   <h2 class="text-lg font-semibold">{{ t('nonprofitDppo.profile') }}</h2><p class="text-sm text-neutral-500">{{ t('nonprofitDppo.profileHelp') }}</p><p v-if="legal.latest" data-test="revision">{{ t('nonprofitDppo.revision', { n: legal.latest.revision_no }) }} · {{ t(`nonprofitDppo.kinds.${legal.latest.profile.kind}`) }}</p>
   <fieldset :disabled="locked || !profileWritable" class="grid gap-4 md:grid-cols-2">
    <label :class="formLabel">{{ t('nonprofitDppo.entity') }}<select v-model="entity" data-field="entity" :class="input"><option value="">{{ t('nonprofitDppo.choose') }}</option><option v-for="value in legal.entity_types" :key="value" :value="value">{{ t(`nonprofitDppo.entities.${value}`) }}</option></select></label>
    <label :class="formLabel">{{ t('nonprofitDppo.kind') }}<select v-model="kind" data-field="kind" :class="input"><option value="">{{ t('nonprofitDppo.choose') }}</option><option v-for="value in legal.kinds" :key="value" :value="value">{{ t(`nonprofitDppo.kinds.${value}`) }}</option></select></label>
    <label class="flex items-start gap-2 md:col-span-2"><input v-model="nonbusiness" data-field="main-activity" type="checkbox" class="mt-1" />{{ t('nonprofitDppo.nonbusiness') }}</label>
    <label :class="formLabel">{{ t('nonprofitDppo.legalBasis') }}<textarea v-model="legalBasis" data-field="legal-basis" :class="textarea" /></label><label :class="formLabel">{{ t('nonprofitDppo.evidence') }}<textarea v-model="evidence" data-field="profile-evidence" :class="textarea" /></label><label :class="[formLabel, 'md:col-span-2']">{{ t('nonprofitDppo.reason') }}<textarea v-model="reason" data-field="profile-reason" :class="textarea" /></label>
   </fieldset><ActionBar :actions="profileActions" />
   <ul v-if="legal.capabilities.preparation_findings?.length" class="list-disc space-y-1 pl-5"><li v-for="finding in legal.capabilities.preparation_findings" :key="finding.code">{{ finding.message }}</li></ul>
   <section v-if="operations.length || formManifest" class="space-y-2" data-test="operations">
    <h3 class="font-semibold">{{ t('nonprofitDppo.operations.title') }}</h3>
    <p v-if="formManifest" class="text-sm text-neutral-500" data-test="form-manifest">{{ t('nonprofitDppo.operations.form', { code: formManifest.form_code, version: formManifest.form_version, date: formManifest.form_date }) }}</p>
    <ul v-if="operations.length" class="space-y-1 text-sm">
     <li v-for="op in operations" :key="op.key" :data-operation="op.key" :data-allowed="String(op.allowed)"><span class="font-medium">{{ t(`nonprofitDppo.operations.labels.${op.key}`) }}</span> · <span :class="op.allowed ? 'text-success' : 'text-warning'">{{ t(op.allowed ? 'nonprofitDppo.operations.supported' : 'nonprofitDppo.operations.blocked') }}</span><span v-if="!op.allowed && op.message" data-test="operation-message"> - {{ op.message }}</span></li>
    </ul>
   </section>
   <ActionBar :actions="filingActions" /><p v-if="!filingReady" class="text-sm text-neutral-500" data-test="not-ready">{{ t('nonprofitDppo.notReady') }}</p>
   <dl v-if="native?.computed.nonprofit" class="grid gap-3 sm:grid-cols-2"><div><dt>{{ t('nonprofitDppo.filingObligation') }}</dt><dd>{{ t(native.computed.nonprofit.filing_obligation.required ? 'nonprofitDppo.required' : 'nonprofitDppo.notRequired') }} · {{ native.computed.nonprofit.filing_obligation.legal_basis }}</dd></div><div><dt>{{ t('nonprofitDppo.taxSaving') }}</dt><dd>{{ kc(native.computed.nonprofit.saving.saving_kc) }}</dd></div></dl>
   <NonprofitDppoReductionEditor v-if="native?.computed.nonprofit" :supplier="supplier" :state="native" :can-edit="Boolean(nativeProofMatches && canEditReturn && legal.capabilities.can_edit_reduction)" @updated="native = $event" />
  </article>
  <article v-if="savings" class="space-y-4 rounded-xl border bg-surface p-5">
   <h2 class="text-lg font-semibold">{{ t('nonprofitDppo.savings') }}</h2><p class="text-sm text-neutral-500">{{ t('nonprofitDppo.savingsHelp') }}</p><p v-if="!savings.sources.length">{{ t('nonprofitDppo.noSavings') }}</p>
   <fieldset :disabled="locked" class="space-y-4">
    <label :class="formLabel">{{ t('nonprofitDppo.savingSource') }}<select v-model.number="sourceId" data-field="source" :class="input"><option :value="0">{{ t('nonprofitDppo.choose') }}</option><option v-for="s in savings.sources" :key="s.source.id" :value="s.source.id">{{ sourceLabel(s) }}</option></select></label>
    <template v-if="source"><p v-if="source.void" class="text-warning">{{ t('nonprofitDppo.void') }}</p><p v-if="source.eligibility.message" class="text-sm">{{ source.eligibility.message }}</p><p v-if="source.next_period">{{ t('nonprofitDppo.nextPeriod') }}: {{ source.next_period.starts_on }} - {{ source.next_period.ends_on }}</p>
     <div class="grid gap-4 md:grid-cols-2"><label :class="[formLabel, 'md:col-span-2']">{{ t('nonprofitDppo.cost') }}<select v-model.number="costId" data-field="cost" :class="input"><option :value="0">{{ t('nonprofitDppo.choose') }}</option><option v-for="c in source.eligible_costs" :key="c.cost_line_id" :value="c.cost_line_id">{{ c.date }} · {{ c.account_code }} · {{ c.description }} · {{ c.document_ref }} · {{ money(c.available_cents) }}</option></select></label><label :class="formLabel">{{ t('nonprofitDppo.amount') }}<input v-model="amount" data-field="amount" inputmode="decimal" :class="input" /></label><label :class="formLabel">{{ t('nonprofitDppo.purpose') }}<select v-model="purpose" data-field="purpose" :class="input"><option value="">{{ t('nonprofitDppo.choose') }}</option><option v-for="value in cost?.allowed_purposes || []" :key="value" :value="value">{{ t(`nonprofitDppo.purposes.${value}`) }}</option></select></label><label :class="[formLabel, 'md:col-span-2']">{{ t('nonprofitDppo.evidence') }}<textarea v-model="useEvidence" data-field="use-evidence" :class="textarea" /></label></div>
     <div class="grid gap-4 md:grid-cols-2"><label :class="formLabel">{{ t('nonprofitDppo.reverseEvent') }}<select v-model.number="eventId" data-field="event" :class="input"><option :value="0">{{ t('nonprofitDppo.choose') }}</option><option v-for="e in reversible" :key="e.id" :value="e.id">{{ e.id }} · {{ money(e.amount_cents) }}</option></select></label><label :class="formLabel">{{ t('nonprofitDppo.reverseReason') }}<textarea v-model="reverseReason" data-field="reverse-reason" :class="textarea" /></label></div>
    </template>
   </fieldset><ActionBar :actions="savingActions" /><ul v-if="source?.cost_findings?.length" class="list-disc space-y-1 pl-5"><li v-for="finding in source.cost_findings" :key="`${finding.cost_line_id}:${finding.code}`">{{ finding.message }}</li></ul>
  </article>
  <article v-if="pending" class="space-y-3 rounded-xl border border-primary p-5" data-test="confirmation"><h2 class="font-semibold">{{ t('nonprofitDppo.review') }}</h2><template v-if="pending.kind === 'profile'"><p>{{ t(`nonprofitDppo.entities.${pending.body.profile.entity_type}`) }} · {{ t(`nonprofitDppo.kinds.${pending.body.profile.kind}`) }}</p><p>{{ pending.body.profile.legal_basis }}</p><p>{{ pending.body.profile.evidence }}</p><p>{{ pending.body.reason }}</p></template><p v-else-if="plan">{{ t(plan.operation === 'use' ? 'nonprofitDppo.previewUse' : 'nonprofitDppo.previewReverse') }} · {{ money(plan.amount_cents) }}</p><label class="flex items-center gap-2"><input v-model="acknowledged" data-field="acknowledged" type="checkbox" :disabled="locked" />{{ t('nonprofitDppo.acknowledge') }}</label></article>
 </section>
</template>
