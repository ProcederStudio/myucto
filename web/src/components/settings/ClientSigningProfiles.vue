<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type SigningProfile, type SigningProfilePayload, type SigningProfileCredentialMeta, type PdfSignatureOutputSetting } from '@/api/settings'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const busy = ref(false)
const profiles = ref<SigningProfile[]>([])
const outputs = ref<PdfSignatureOutputSetting[]>([])
const baseline = ref<Record<string, string>>({})
const showForm = ref(false)
const editingId = ref<number | null>(null)
const credential = ref<SigningProfileCredentialMeta | null>(null)
const file = ref<File | null>(null)
const password = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const draft = reactive<SigningProfilePayload>(emptyDraft())
const eligibleProfiles = computed(() => profiles.value.filter(p => p.is_active && p.owner_user_id === null))

function emptyDraft(): SigningProfilePayload {
  return { name: '', code: '', owner_user_id: null, default_backend: 'native', allowed_usages: ['pdf'], is_active: true, pdf_reason: '', pdf_tsa_url: '', pdf_tsa_username: '', pdf_tsa_password: '' }
}
function outputProfiles(row: PdfSignatureOutputSetting) {
  return eligibleProfiles.value.filter(p => p.allowed_usages.includes(row.usage))
}
function unavailableProfile(row: PdfSignatureOutputSetting) {
  return row.default_profile_id !== null && !outputProfiles(row).some(p => p.id === row.default_profile_id)
}
function outputPayload(row: PdfSignatureOutputSetting) {
  return { output_type: row.output_type, enabled: row.enabled, backend: row.usage === 'email_smime' ? 'smime' : 'native', selection_source: 'admin_profile_settings' as const, default_profile_id: row.default_profile_id, failure_policy: row.failure_policy, signature_config: row.signature_config }
}
function replaceOutputs(rows: PdfSignatureOutputSetting[]) {
  outputs.value = rows
  baseline.value = Object.fromEntries(rows.map(row => [row.output_type, JSON.stringify(outputPayload(row))]))
}
async function load() {
  loading.value = true
  try {
    const [rows, settings] = await Promise.all([settingsApi.listSigningProfiles(true), settingsApi.getPdfSigningSettings(true)])
    profiles.value = rows
    replaceOutputs(settings.output_settings)
  } catch (e) { toast.error(apiErrorMessage(e, t('common.error'))) }
  finally { loading.value = false }
}
onMounted(load)
function clearFile() {
  file.value = null
  password.value = ''
  if (fileInput.value) fileInput.value.value = ''
}
function create() {
  editingId.value = null
  credential.value = null
  Object.assign(draft, emptyDraft())
  clearFile()
  showForm.value = true
}
async function edit(profile: SigningProfile) {
  busy.value = true
  try {
    const meta = await settingsApi.getSigningProfileCredential(profile.id, true)
    editingId.value = profile.id
    credential.value = meta
    Object.assign(draft, emptyDraft(), { name: profile.name, code: profile.code, allowed_usages: [...profile.allowed_usages], is_active: profile.is_active, pdf_reason: profile.pdf_reason, pdf_tsa_url: profile.pdf_tsa_url, pdf_tsa_username: profile.pdf_tsa_username })
    clearFile()
    showForm.value = true
  } catch (e) { toast.error(apiErrorMessage(e, t('common.error'))) }
  finally { busy.value = false }
}
async function save() {
  if (!draft.allowed_usages.length) { toast.error(t('settings.signing_profile_usage_required')); return }
  busy.value = true
  try {
    const payload = { ...draft, name: draft.name.trim(), code: draft.code.trim(), pdf_tsa_enabled: Boolean(draft.pdf_tsa_url?.trim()) }
    if (!payload.pdf_tsa_password) delete payload.pdf_tsa_password
    const profile = editingId.value === null
      ? await settingsApi.createSigningProfile(payload, true)
      : await settingsApi.updateSigningProfile(editingId.value, payload, true)
    editingId.value = profile.id
    draft.pdf_tsa_password = ''
    toast.success(t('settings.signing_profile_saved'))
    await load()
  } catch (e) { toast.error(apiErrorMessage(e, t('common.error'))) }
  finally { busy.value = false }
}
async function remove(profile: SigningProfile) {
  if (!window.confirm(t('settings.signing_profile_delete_confirm', { name: profile.name }))) return
  busy.value = true
  try {
    await settingsApi.deleteSigningProfile(profile.id, true)
    if (editingId.value === profile.id) { showForm.value = false; clearFile() }
    await load()
    toast.success(t('settings.signing_profile_deleted'))
  } catch (e) { toast.error(apiErrorMessage(e, t('common.error'))) }
  finally { busy.value = false }
}
async function upload() {
  if (!file.value || editingId.value === null) return
  busy.value = true
  try {
    credential.value = await settingsApi.uploadSigningProfileCredential(editingId.value, file.value, password.value, 'encrypted_store', null, true)
    clearFile()
    await load()
    toast.success(t('settings.signing_profile_cert_uploaded'))
  } catch (e) { toast.error(apiErrorMessage(e, t('common.error'))) }
  finally { busy.value = false }
}
async function removeCertificate() {
  if (editingId.value === null || !window.confirm(t('settings.signing_profile_cert_delete_confirm'))) return
  busy.value = true
  try {
    credential.value = await settingsApi.deleteSigningProfileCredential(editingId.value, true)
    await load()
    toast.success(t('settings.signing_profile_cert_deleted'))
  } catch (e) { toast.error(apiErrorMessage(e, t('common.error'))) }
  finally { busy.value = false }
}
async function saveOutputs() {
  busy.value = true
  try {
    const changes = outputs.value.filter(row => JSON.stringify(outputPayload(row)) !== baseline.value[row.output_type]).map(outputPayload)
    if (changes.length) {
      const result = await settingsApi.updatePdfSignatureOutputSettings(changes, true)
      const updated = new Map(result.output_settings.map(row => [row.output_type, row]))
      replaceOutputs(outputs.value.map(row => updated.get(row.output_type) ?? row))
    }
    toast.success(t('settings.signing_outputs_saved'))
  } catch (e) { toast.error(apiErrorMessage(e, t('common.error'))) }
  finally { busy.value = false }
}
async function test(row: PdfSignatureOutputSetting) {
  busy.value = true
  try {
    const result = await settingsApi.testPdfSigning(row.output_type, true)
    const message = t('settings.client_signing_test_result', { status: t(`settings.client_signing_status_${result.status}`), detail: result.error || result.reason || result.certificate_cn || '' })
    if (result.status === 'signed') toast.success(message)
    else toast.warning(message)
  } catch (e) { toast.error(apiErrorMessage(e, t('common.error'))) }
  finally { busy.value = false }
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div><h2 class="text-lg font-semibold">{{ t('settings.signing_profiles_title') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ t('settings.client_signing_hint') }}</p></div>
      <button :class="btnFilled('primary')" :disabled="busy || loading" @click="create"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('settings.signing_profile_create') }}</button>
    </div>
    <p v-if="loading" role="status">{{ t('common.loading') }}</p>
    <template v-else>
      <p v-if="!profiles.length" class="text-sm text-neutral-500">{{ t('settings.client_signing_empty') }}</p>
      <div v-for="profile in profiles" :key="profile.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-surface p-4">
        <div><h3 class="font-medium">{{ profile.name }} <span class="text-sm text-neutral-500">({{ profile.code }})</span></h3><p class="text-sm text-neutral-500">{{ profile.allowed_usages.map(usage => t(`settings.signing_profile_usage_${usage}`)).join(', ') }} · {{ t(profile.is_active ? 'settings.signing_profile_active' : 'settings.client_signing_inactive') }}</p><p class="text-sm text-neutral-500">{{ profile.certificate_subject || t('settings.signing_profile_cert_no_certificate_settings') }}</p></div>
        <div class="flex flex-wrap gap-2">
          <button :class="btnOutline('neutral')" :disabled="busy" @click="edit(profile)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button>
          <button :class="btnOutline('danger')" :disabled="busy" @click="remove(profile)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>{{ t('common.delete') }}</button>
        </div>
      </div>
      <div v-if="showForm" class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4">
        <form class="space-y-4" @submit.prevent="save">
          <div class="grid gap-4 md:grid-cols-2">
            <label class="text-sm">{{ t('settings.signing_profile_name') }}<input v-model="draft.name" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface p-2" /></label>
            <label class="text-sm">{{ t('settings.signing_profile_code') }}<input v-model="draft.code" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface p-2" /></label>
          </div>
          <fieldset><legend class="mb-2 text-sm font-medium">{{ t('settings.signing_profile_usages') }}</legend><div class="flex flex-wrap gap-4"><label v-for="usage in (['pdf', 'email_smime'] as const)" :key="usage" class="flex items-center gap-2 text-sm"><input v-model="draft.allowed_usages" type="checkbox" :value="usage" />{{ t(`settings.signing_profile_usage_${usage}`) }}</label></div></fieldset>
          <label class="flex items-center gap-2 text-sm"><input v-model="draft.is_active" type="checkbox" />{{ t('settings.signing_profile_active') }}</label>
          <details v-if="draft.allowed_usages.includes('pdf')" class="space-y-3"><summary class="cursor-pointer text-sm font-medium">{{ t('settings.signing_profile_pdf_settings') }}</summary>
            <p class="text-xs text-neutral-500">{{ t('settings.signing_profile_pdf_settings_hint') }}</p>
            <div class="grid gap-3 md:grid-cols-2"><label v-for="field in (['pdf_reason', 'pdf_tsa_url', 'pdf_tsa_username', 'pdf_tsa_password'] as const)" :key="field" class="text-sm">{{ t(`settings.client_signing_${field}`) }}<input v-model="draft[field]" :type="field === 'pdf_tsa_password' ? 'password' : field === 'pdf_tsa_url' ? 'url' : 'text'" autocomplete="off" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface p-2" /></label></div>
          </details>
          <div class="flex flex-wrap gap-2"><button type="submit" :class="btnFilled('primary')" :disabled="busy"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('settings.signing_profile_update') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="showForm = false; draft.pdf_tsa_password = ''; clearFile()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.close') }}</button></div>
        </form>
        <section v-if="editingId !== null" class="space-y-3 border-t border-neutral-200 pt-4">
          <h3 class="font-medium">{{ t('settings.signing_profile_cert_title') }}</h3>
          <p class="text-sm text-neutral-500">{{ t('settings.client_signing_certificate_hint') }}</p>
          <p v-if="credential?.has_certificate" class="break-words text-sm">{{ credential.certificate_subject }} · {{ credential.certificate_email }} · {{ credential.certificate_valid_to }}</p>
          <p v-if="credential?.expired || (credential?.has_certificate && !credential.is_active)" class="text-sm text-warning-700">{{ t('settings.client_signing_certificate_invalid') }}</p>
          <form class="space-y-3" @submit.prevent="upload">
            <label class="block text-sm">{{ t('settings.client_signing_certificate_file') }}<input ref="fileInput" type="file" accept=".pfx,.p12" required class="mt-1 block w-full text-sm" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
            <label class="block text-sm">{{ t('settings.client_signing_certificate_password') }}<input v-model="password" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface p-2" /></label>
            <div class="flex flex-wrap gap-2"><button type="submit" :class="btnOutline('success')" :disabled="busy"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.upload" /></svg>{{ t('settings.signing_profile_cert_upload') }}</button><button v-if="credential?.has_certificate" type="button" :class="btnOutline('danger')" :disabled="busy" @click="removeCertificate"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>{{ t('common.delete') }}</button></div>
          </form>
        </section>
        <p v-else class="text-sm text-neutral-500">{{ t('settings.client_signing_save_first') }}</p>
      </div>
      <section class="space-y-4">
        <h2 class="text-lg font-semibold">{{ t('settings.signing_outputs_title') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('settings.client_signing_outputs_hint') }}</p>
        <div v-for="row in outputs" :key="row.output_type" class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-4">
          <h3 class="font-medium">{{ t(`settings.signing_output_type_${row.output_type}`) }}</h3>
          <div class="flex flex-wrap items-end gap-4">
            <label class="flex items-center gap-2 text-sm"><input v-model="row.enabled" type="checkbox" />{{ t('settings.signing_output_enabled') }}</label>
            <label class="text-sm">{{ t('settings.signing_output_profile') }}<select v-model="row.default_profile_id" class="mt-1 block max-w-full rounded-md border border-neutral-300 bg-surface p-2"><option :value="null">{{ t('settings.signing_output_profile_none') }}</option><option v-if="unavailableProfile(row)" :value="row.default_profile_id" disabled>{{ t('settings.client_signing_profile_unavailable') }}</option><option v-for="profile in outputProfiles(row)" :key="profile.id" :value="profile.id">{{ profile.name }}</option></select></label>
            <label class="text-sm">{{ t('settings.signing_output_failure_policy') }}<select v-model="row.failure_policy" class="mt-1 block max-w-full rounded-md border border-neutral-300 bg-surface p-2"><option v-for="policy in ['fail_closed', 'fallback_unsigned', 'skip_when_unconfigured']" :key="policy" :value="policy">{{ t(`settings.signing_output_policy_${policy}`) }}</option></select></label>
            <button v-if="row.usage === 'pdf'" :class="btnOutline('neutral')" :disabled="busy" @click="test(row)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.play" /></svg>{{ t('settings.signing_output_test') }}</button>
          </div>
          <p v-if="unavailableProfile(row)" class="text-sm text-warning-700">{{ t('settings.client_signing_profile_unavailable_hint') }}</p>
        </div>
        <button v-if="outputs.length" :class="btnFilled('primary')" :disabled="busy" @click="saveOutputs"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
      </section>
    </template>
  </div>
</template>
