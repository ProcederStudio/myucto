<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { companyEmailTemplatesApi, type CompanyEmailTemplate, type CompanyEmailTemplateItem, type EmailTemplateContent } from '@/api/companyEmailTemplates'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = withDefaults(defineProps<{ clientScoped?: boolean }>(), { clientScoped: false })
const { t, te } = useI18n()
const supplier = useSupplierStore()
const toast = useToast()
const id = useId()
const list = ref<CompanyEmailTemplateItem[]>([])
const editing = ref<CompanyEmailTemplate | null>(null)
const preview = ref<EmailTemplateContent | null>(null)
const loading = ref(false)
const opening = ref(false)
const busy = ref(false)
const error = ref('')
let generation = 0
let editorGeneration = 0
let previewGeneration = 0
let editorSupplierId = 0
const companyName = computed(() => supplier.currentSupplier?.company_name ?? '')
// The sandbox denies scripts and the app origin; CSP also blocks tracking assets and forms.
const previewDocument = computed(() => preview.value ? `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'">${preview.value.body_html}` : '')
function variableDescription(name: string): string {
  const key = `company_email_templates.variable_descriptions.${name.replaceAll('.', '_')}`
  return te(key) ? t(key) : t('company_email_templates.variable_fallback', { variable: name })
}
const content = (item: EmailTemplateContent): EmailTemplateContent => ({ subject: item.subject, body_html: item.body_html, body_text: item.body_text })
const message = (e: unknown) => (e as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error?.message || t('common.error')

function close() {
  editorGeneration++
  previewGeneration++
  editing.value = null
  preview.value = null
  opening.value = false
  busy.value = false
}
async function load() {
  const request = ++generation
  close()
  list.value = []
  error.value = ''
  const sid = supplier.currentSupplierId
  if (!sid) { loading.value = false; return }
  loading.value = true
  try {
    const result = await companyEmailTemplatesApi(props.clientScoped, sid).list()
    if (request !== generation || supplier.currentSupplierId !== sid) return
    if (result.supplier_id !== sid) { error.value = t('company_email_templates.scope_changed'); return }
    list.value = result.data
  } catch (e) {
    if (request === generation) error.value = message(e)
  } finally {
    if (request === generation) loading.value = false
  }
}
watch(() => [supplier.currentSupplierId, props.clientScoped], load, { immediate: true, flush: 'sync' })
onBeforeUnmount(() => { generation++; close() })

async function open(item: CompanyEmailTemplateItem) {
  close()
  const request = ++editorGeneration
  const scope = generation
  const sid = supplier.currentSupplierId
  opening.value = true
  error.value = ''
  try {
    const result = await companyEmailTemplatesApi(props.clientScoped, sid).get(item.code, item.locale)
    if (request !== editorGeneration || scope !== generation || sid !== supplier.currentSupplierId) return
    editorSupplierId = sid
    editing.value = result
  } catch (e) {
    if (request === editorGeneration && scope === generation) error.value = message(e)
  } finally {
    if (request === editorGeneration) opening.value = false
  }
}
async function mutate(reset = false) {
  const item = editing.value
  const sid = editorSupplierId
  if (!item || busy.value || sid !== supplier.currentSupplierId) return
  if (reset && !window.confirm(t('company_email_templates.reset_confirm', { company: companyName.value, language: item.locale }))) return
  const scope = generation
  const editor = editorGeneration
  busy.value = true
  error.value = ''
  try {
    const api = companyEmailTemplatesApi(props.clientScoped, sid)
    if (reset) await api.reset(item.code, item.locale)
    else await api.save(item.code, item.locale, content(item))
    if (scope !== generation || editor !== editorGeneration || sid !== supplier.currentSupplierId) return
    toast.success(t(reset ? 'company_email_templates.reset_done' : 'users.et_saved'))
    await load()
  } catch (e) {
    if (scope === generation && editor === editorGeneration) error.value = message(e)
  } finally {
    if (scope === generation && editor === editorGeneration) busy.value = false
  }
}
async function showPreview() {
  const item = editing.value
  const sid = editorSupplierId
  if (!item || busy.value || sid !== supplier.currentSupplierId) return
  const request = ++previewGeneration
  const scope = generation
  const editor = editorGeneration
  const draft = content(item)
  busy.value = true
  error.value = ''
  preview.value = null
  try {
    const result = await companyEmailTemplatesApi(props.clientScoped, sid).preview(item.code, item.locale, draft)
    if (scope === generation && editor === editorGeneration && request === previewGeneration && sid === supplier.currentSupplierId && JSON.stringify(draft) === JSON.stringify(content(item))) preview.value = result
  } catch (e) {
    if (scope === generation && editor === editorGeneration) error.value = message(e)
  } finally {
    if (scope === generation && editor === editorGeneration) busy.value = false
  }
}
watch(() => editing.value && content(editing.value), () => { preview.value = null; previewGeneration++ }, { deep: true })
</script>

<template>
  <section class="space-y-4">
    <header>
      <h2 class="text-xl font-semibold">{{ t('users.email_templates_title') }}</h2>
      <p class="mt-1 text-sm font-medium">{{ t('company_email_templates.company', { company: companyName }) }}</p>
      <p class="mt-1 text-sm text-neutral-500">{{ t('company_email_templates.hint') }}</p>
    </header>
    <p v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</p>
    <p v-if="loading || opening" role="status" class="py-5 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <div v-if="!loading && !editing" class="rounded-lg border border-neutral-200 bg-surface shadow-sm divide-y divide-neutral-100">
      <div v-for="item in list" :key="`${item.code}.${item.locale}`" class="flex flex-wrap items-center justify-between gap-3 p-4">
        <div class="min-w-0 space-y-1">
          <h3 class="font-medium">{{ t(`users.et_known_codes.${item.code}`) }} <span class="text-xs uppercase text-neutral-500">{{ item.locale }}</span></h3>
          <p class="text-xs text-neutral-500">{{ t(`company_email_templates.source_${item.source}`) }} · {{ t('common.language') }}: {{ item.resolved_locale }}</p>
          <p v-if="item.has_override && item.updated_at" class="text-xs text-neutral-500">{{ t('company_email_templates.updated', { date: item.updated_at }) }}</p>
        </div>
        <button type="button" data-test="template-edit" :class="btnOutline('primary')" :disabled="opening" @click="open(item)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}
        </button>
      </div>
    </div>
    <form v-if="editing" class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" @submit.prevent="mutate()">
      <h3 class="font-semibold">{{ t(`users.et_known_codes.${editing.code}`) }} · {{ editing.locale }}</h3>
      <p class="text-xs text-neutral-500">{{ t(`company_email_templates.source_${editing.source}`) }} · {{ t('common.language') }}: {{ editing.resolved_locale }}</p>
      <fieldset class="space-y-3" :disabled="busy">
        <div><label :for="`${id}-subject`" class="mb-1 block text-sm font-medium">{{ t('users.et_subject') }}</label><input :id="`${id}-subject`" v-model="editing.subject" data-test="template-subject" class="h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" /></div>
        <div><label :for="`${id}-html`" class="mb-1 block text-sm font-medium">{{ t('users.et_body_html') }}</label><textarea :id="`${id}-html`" v-model="editing.body_html" rows="12" class="w-full rounded-md border border-neutral-300 p-3 font-mono text-xs" /></div>
        <div><label :for="`${id}-text`" class="mb-1 block text-sm font-medium">{{ t('users.et_body_text') }}</label><textarea :id="`${id}-text`" v-model="editing.body_text" rows="7" class="w-full rounded-md border border-neutral-300 p-3 font-mono text-xs" /></div>
      </fieldset>
      <details class="text-sm">
        <summary class="cursor-pointer font-medium">{{ t('company_email_templates.variables') }}</summary>
        <dl class="mt-2 space-y-2"><div v-for="name in Object.keys(editing.variables)" :key="name"><dt class="font-mono text-xs">{{ name }}</dt><dd class="text-neutral-500">{{ variableDescription(name) }}</dd></div></dl>
      </details>
      <div class="flex flex-wrap gap-2 border-t border-neutral-200 pt-4">
        <button type="submit" data-test="template-save" :disabled="busy" :class="btnFilled('primary')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('users.et_save') }}</button>
        <button type="button" data-test="template-preview" :disabled="busy" :class="btnOutline('neutral')" @click="showPreview"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('company_email_templates.preview') }}</button>
        <button v-if="editing.has_override" type="button" data-test="template-reset" :disabled="busy" :class="btnOutline('warning')" @click="mutate(true)"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('company_email_templates.reset') }}</button>
        <button type="button" :class="btnOutline('neutral')" @click="close"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
      </div>
      <div v-if="preview" class="space-y-3 border-t border-neutral-200 pt-4">
        <h4 class="font-medium">{{ t('company_email_templates.preview') }}</h4>
        <p class="text-sm text-neutral-500">{{ t('company_email_templates.preview_hint') }}</p>
        <p class="text-sm font-medium">{{ preview.subject }}</p>
        <iframe :title="t('company_email_templates.preview')" sandbox="" referrerpolicy="no-referrer" :srcdoc="previewDocument" class="h-96 w-full rounded-md border border-neutral-200 bg-white" />
        <pre class="whitespace-pre-wrap break-words text-xs">{{ preview.body_text }}</pre>
      </div>
    </form>
  </section>
</template>
