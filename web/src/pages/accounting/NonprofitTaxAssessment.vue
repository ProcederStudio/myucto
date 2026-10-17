<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { nonprofitTaxApi, type TaxSnapshot, type TaxRevision, type TaxPreview, type TaxExport } from '@/api/nonprofitTax'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { requestSupplierId } from '@/workspace/supplierContext'
import { activityName, assessmentDraft, assessmentPayload, validateManualAssessment, type AssessmentDraft } from '@/utils/nonprofitTaxDraft'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import SourceDecisionEditor from '@/components/accounting/nonprofitTax/SourceDecisionEditor.vue'
import FundingEditor from '@/components/accounting/nonprofitTax/FundingEditor.vue'
import AssessmentBridge from '@/components/accounting/nonprofitTax/AssessmentBridge.vue'
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
const periodId = computed(() => Number(route.params.id))
const snapshot = ref<TaxSnapshot | null>(null)
const draft = ref<AssessmentDraft | null>(null)
const latest = ref<TaxRevision | null>(null)
const history = ref<TaxRevision[]>([])
const preview = ref<TaxPreview | null>(null)
const selectedId = ref<number | null>(null)
const exported = ref<TaxExport | null>(null)
const reason = ref('')
const acknowledged = ref(false)
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const form = ref<HTMLFormElement | null>(null)
let epoch = 0
let retry: { body: string; key: string } | null = null
const canConfirm = computed(() => auth.can('accounting.periods.close', 'write'))
const hasFindings = computed(() => (snapshot.value?.findings.length ?? 0) > 0)
const reportError = (e: unknown) => { error.value = e instanceof Error && e.message.startsWith('nonprofitTax.') ? t(e.message) : apiErrorMessage(e) }
watch(draft, () => { preview.value = null; acknowledged.value = false }, { deep: true, flush: 'sync' })
async function load() {
  const token = ++epoch
  loading.value = true
  error.value = ''
  preview.value = null
  snapshot.value = null
  draft.value = null
  exported.value = null
  latest.value = null
  history.value = []
  selectedId.value = null
  reason.value = ''
  acknowledged.value = false
  try {
    const [data, revisions] = await Promise.all([nonprofitTaxApi.read(periodId.value), nonprofitTaxApi.history(periodId.value)])
    if (token !== epoch) return
    snapshot.value = data.snapshot
    latest.value = data.latest
    history.value = revisions
    draft.value = assessmentDraft(data.snapshot, data.latest?.assessment)
    selectedId.value = data.latest?.id ?? null
    if (selectedId.value) await loadRevision()
  } catch (e) { if (token === epoch) reportError(e) }
  finally { if (token === epoch) loading.value = false }
}
async function loadRevision() {
  exported.value = null
  const id = selectedId.value
  const token = epoch
  if (!id) return
  try {
    const result = await nonprofitTaxApi.export(id)
    if (token === epoch && selectedId.value === id) exported.value = result
  } catch (e) { if (token === epoch) reportError(e) }
}
async function calculate() {
  if (!draft.value || busy.value || hasFindings.value) return
  busy.value = true
  error.value = ''
  preview.value = null
  try {
    if (form.value && !form.value.reportValidity()) throw new Error('nonprofitTax.manual.invalidForm')
    if (snapshot.value) validateManualAssessment(draft.value, snapshot.value)
    const body = assessmentPayload(draft.value)
    const fingerprint = JSON.stringify(body)
    const token = epoch
    const supplier = requestSupplierId()
    const result = await nonprofitTaxApi.preview(periodId.value, body)
    if (token !== epoch || supplier !== requestSupplierId() || !draft.value || JSON.stringify(assessmentPayload(draft.value)) !== fingerprint) return
    snapshot.value = result.snapshot
    preview.value = result
  } catch (e) { reportError(e) }
  finally { busy.value = false }
}
function keyFor(body: unknown): string {
  const value = JSON.stringify(body)
  if (retry?.body !== value) retry = { body: value, key: crypto.randomUUID() }
  return retry!.key
}
async function confirmAssessment() {
  if (!draft.value || !preview.value || !acknowledged.value || !reason.value.trim() || busy.value || !canConfirm.value) return
  busy.value = true
  error.value = ''
  try {
    const body = { assessment: assessmentPayload(draft.value), input_hash: preview.value.snapshot.input_hash,
      reason: reason.value, expected_assessment_id: preview.value.previous_assessment_id }
    await nonprofitTaxApi.confirm(periodId.value, { ...body, idempotency_key: keyFor(body) })
    retry = null
    reason.value = ''
    toast.success(t('nonprofitTax.confirmed'))
    await load()
  } catch (e) { reportError(e) }
  finally { busy.value = false }
}
async function withdraw() {
  if (!latest.value || latest.value.status !== 'confirmed' || !reason.value.trim() || !acknowledged.value || busy.value || !canConfirm.value) return
  busy.value = true
  error.value = ''
  try {
    const body = { expected_id: latest.value.id, reason: reason.value }
    await nonprofitTaxApi.withdraw(periodId.value, { ...body, idempotency_key: keyFor(body) })
    retry = null
    reason.value = ''
    await load()
    toast.success(t('nonprofitTax.withdrawn'))
  } catch (e) { reportError(e) }
  finally { busy.value = false }
}
async function download(format: 'pdf' | 'xlsx') {
  if (!selectedId.value || busy.value) return
  busy.value = true
  try {
    const id = selectedId.value
    const response = await nonprofitTaxApi.document(id, format)
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `danove-posouzeni-${id}.${format}`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (e) { reportError(e) }
  finally { busy.value = false }
}
const actions = computed<ActionItem[]>(() => [
  { key: 'preview', label: t('nonprofitTax.preview'), icon: 'chart', tier: preview.value && canConfirm.value ? 'secondary' : 'primary', variant: 'primary', disabled: busy.value || loading.value || !draft.value || hasFindings.value, run: () => { void calculate() } },
  { key: 'confirm', label: t('nonprofitTax.confirm'), icon: 'check', tier: 'primary', variant: 'success', show: !!preview.value && canConfirm.value, disabled: busy.value || !reason.value.trim() || !acknowledged.value, disabledReason: t('nonprofitTax.confirmHint'), run: () => { void confirmAssessment() } },
  ...(['pdf', 'xlsx'] as const).map(format => ({ key: format, label: t(`nonprofitTax.download.${format}`), icon: 'download' as const, tier: 'secondary' as const, variant: 'neutral' as const, disabled: busy.value || !selectedId.value, run: () => { void download(format) } })),
  { key: 'refresh', label: t('nonprofitTax.refresh'), icon: 'cycle', tier: 'overflow', variant: 'neutral', disabled: busy.value || loading.value, run: () => { void load() } },
  { key: 'withdraw', label: t('nonprofitTax.withdraw'), icon: 'uturn', tier: 'overflow', variant: 'warning', show: latest.value?.status === 'confirmed' && canConfirm.value, disabled: busy.value || !reason.value.trim() || !acknowledged.value, disabledReason: t('nonprofitTax.confirmHint'), run: () => { void withdraw() } },
])
watch(periodId, () => { void load() }, { immediate: true })
</script>
<template>
  <form ref="form" class="space-y-6" @submit.prevent="calculate">
    <header><h1 class="text-2xl font-semibold">{{ t('nonprofitTax.title') }}</h1><p class="mt-2 max-w-4xl text-sm text-neutral-600">{{ t('nonprofitTax.scopeHint') }}</p></header>
    <ActionBar :actions="actions" />
    <p v-if="error" role="alert" class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-danger-700">{{ error }}</p>
    <p v-if="loading" role="status">{{ t('nonprofitTax.loading') }}</p>
    <template v-if="snapshot && draft">
      <div class="flex flex-wrap gap-4"><label class="text-sm">{{ t('nonprofitTax.periodFrom') }}<DateInput :model-value="snapshot.period.starts_on" readonly class="mt-1 h-9" /></label><label class="text-sm">{{ t('nonprofitTax.periodTo') }}<DateInput :model-value="snapshot.period.ends_on" readonly class="mt-1 h-9" /></label></div>
      <section v-if="hasFindings" class="rounded-lg border border-warning-300 bg-warning-50 p-4"><h2 class="font-semibold">{{ t('nonprofitTax.sourcesIncomplete') }}</h2><ul class="mt-2 list-disc pl-5 text-sm"><li v-for="(finding, index) in snapshot.findings" :key="index">{{ t(`nonprofitTax.findings.${finding.code}`) }} <span v-if="finding.line_id">({{ t('nonprofitTax.line') }} {{ finding.line_id }})</span></li></ul></section>
      <section v-if="draft.method" data-test="manual-method" class="rounded-lg border border-warning-300 bg-warning-50 p-4 space-y-3">
        <h2 class="text-lg font-semibold">{{ t('nonprofitTax.manual.title') }}</h2>
        <p class="text-sm">{{ t('nonprofitTax.manual.notice') }}</p>
        <div class="flex flex-wrap gap-3"><label class="text-sm">{{ t('nonprofitTax.manual.validFrom') }}<DateInput v-model="draft.method.valid_from" required :max="snapshot.period.starts_on" class="mt-1 h-9" /></label><label class="text-sm">{{ t('nonprofitTax.manual.validTo') }}<DateInput v-model="draft.method.valid_to" required :min="snapshot.period.ends_on" class="mt-1 h-9" /></label></div>
        <label class="block text-sm">{{ t('nonprofitTax.legalBasis') }}<textarea v-model="draft.method.legal_basis" required data-test="method-legal-basis" rows="2" maxlength="2000" class="form-input mt-1 w-full" /></label>
        <label class="block text-sm">{{ t('nonprofitTax.evidence') }}<textarea v-model="draft.method.evidence" required data-test="method-evidence" rows="2" maxlength="2000" class="form-input mt-1 w-full" /></label>
      </section>
      <section class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3"><h2 class="text-lg font-semibold">{{ t('nonprofitTax.profile') }}</h2><p class="text-sm text-neutral-600">{{ t(draft.method ? 'nonprofitTax.manual.profileHint' : 'nonprofitTax.profileHint') }}</p><label class="block text-sm">{{ t('nonprofitTax.profile') }}<select v-model="draft.profile.kind" :disabled="!!draft.method" class="form-input mt-1 h-9 w-full"><option value="">{{ t('nonprofitTax.choose') }}</option><option v-for="profile in draft.method ? ['documented'] : ['narrow_vpp', 'broad_vpp', 'non_vpp']" :key="profile" :value="profile">{{ t(`nonprofitTax.profiles.${profile}`) }}</option></select></label><label class="block text-sm">{{ t('nonprofitTax.evidence') }}<textarea v-model="draft.profile.evidence" :required="!!draft.method" data-test="profile-evidence" rows="3" maxlength="2000" class="form-input mt-1 w-full" /></label></section>
      <section class="space-y-3"><h2 class="text-lg font-semibold">{{ t('nonprofitTax.activities') }}</h2><p class="text-sm text-neutral-600">{{ t(draft.method ? 'nonprofitTax.manual.activitiesHint' : 'nonprofitTax.activitiesHint') }}</p><article v-for="(activity, id) in draft.activities" :key="id" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3"><h3 class="font-semibold">{{ activityName(snapshot.sources.find(row => String(row.activity_kind_id) === id), id) }}</h3><label class="block text-sm">{{ t('nonprofitTax.evidence') }}<textarea v-model="activity.evidence" :required="!!draft.method" rows="2" class="form-input mt-1 w-full" /></label><div v-for="(cohort, index) in activity.cohorts" :key="index" class="flex flex-wrap items-end gap-3"><label class="text-sm">{{ t('nonprofitTax.cohort') }}<input v-model="cohort.code" class="form-input mt-1 h-9 w-full" /></label><label class="min-w-48 flex-1 text-sm">{{ t('nonprofitTax.cohortEvidence') }}<input v-model="cohort.evidence" class="form-input mt-1 h-9 w-full" /></label><button type="button" :class="btnOutline('danger')" @click="activity.cohorts.splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTax.remove') }}</button></div><button type="button" :class="btnOutline('neutral')" @click="activity.cohorts.push({ code: '', evidence: '' })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('nonprofitTax.addCohort') }}</button></article></section>
      <section class="space-y-3"><h2 class="text-lg font-semibold">{{ t('nonprofitTax.sourceDecisions') }}</h2><p class="text-sm text-neutral-600">{{ t('nonprofitTax.sourceHint') }}</p><SourceDecisionEditor v-for="source in snapshot.sources" :key="source.id" v-model="draft.decisions[source.id]!" :source="source" :manual="!!draft.method" :cohorts="source.activity_kind_id === null ? [] : draft.activities[source.activity_kind_id]?.cohorts ?? []" /></section>
      <FundingEditor v-model="draft" :snapshot="snapshot" />
      <AssessmentBridge v-if="preview" :result="preview.result" />
      <section v-if="canConfirm" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3"><h2 class="font-semibold">{{ t('nonprofitTax.ownDecision') }}</h2><label class="block text-sm">{{ t('nonprofitTax.reason') }}<textarea v-model="reason" maxlength="2000" rows="3" class="form-input mt-1 w-full" /></label><label class="flex items-start gap-2 text-sm"><input v-model="acknowledged" type="checkbox" class="mt-1" />{{ t('nonprofitTax.acknowledgement') }}</label><p class="text-sm text-neutral-600">{{ t('nonprofitTax.confirmHint') }}</p></section>
    </template>
    <section v-if="history.length" class="space-y-4"><h2 class="text-lg font-semibold">{{ t('nonprofitTax.history') }}</h2><label class="block text-sm">{{ t('nonprofitTax.revision') }}<select v-model="selectedId" class="form-input mt-1 h-9 w-full" @change="loadRevision"><option v-for="revision in [...history].reverse()" :key="revision.id" :value="revision.id">{{ t('nonprofitTax.revision') }} {{ revision.revision_no }} · {{ revision.created_at }} · {{ t(`nonprofitTax.status.${revision.status}`) }}</option></select></label><template v-if="exported"><p :class="exported.current_confirmed ? 'text-success-700' : 'text-warning-700'">{{ t(exported.current_confirmed ? 'nonprofitTax.currentConfirmation' : 'nonprofitTax.historicalNotice') }}</p><p class="text-sm whitespace-pre-wrap">{{ exported.revision.reason }}</p><p class="text-xs text-neutral-600">{{ t('nonprofitTax.confirmedBy', { actor: exported.revision.created_by, date: exported.revision.created_at }) }}</p><AssessmentBridge :result="exported.revision.result" /></template></section>
  </form>
</template>
