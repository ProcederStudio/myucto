<script setup lang="ts">
/**
 * Odeslání daňového podání datovou schránkou firmy.
 *
 * Při otevření podání zařadí do fronty datové schránky (idempotentně: pro týž
 * snapshot a příjemce vrátí už založenou zprávu) a nabídne čtyři cesty, jak
 * zprávu odeslat. Odeslání samo je teprve úkon zvolené cesty; po úspěchu backend
 * označí podání jako podané a rodič přenačte seznam.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type GatewayStart, type OutboxSubmission } from '@/api/dataBox'
import { epoSubmissionsApi, type TaxIsdsEnqueueResult } from '@/api/epoSubmissions'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import MobileKeySendButton from '@/components/submission/MobileKeySendButton.vue'

type Method = 'gateway' | 'mobile_key' | 'password' | 'certificate'

const props = defineProps<{
  submissionId: number
  title: string
}>()
const emit = defineEmits<{ close: []; sent: []; changed: [] }>()
const { t, locale } = useI18n()

const loading = ref(true)
const error = ref('')
const queued = ref<TaxIsdsEnqueueResult | null>(null)
const method = ref<Method>('gateway')
const busy = ref(false)
const sendError = ref('')
/** 403 u přihlašovacích cest: chybí právo podepisování, zbývá brána nebo ruční odeslání. */
const forbidden = ref(false)
const sent = ref(false)
const gateway = ref<GatewayStart | null>(null)
const username = ref('')
const password = ref('')
const certificateConfirmed = ref(false)

const METHODS: Method[] = ['gateway', 'mobile_key', 'password', 'certificate']
/** Přihlášení, certifikát a Mobilní klíč se nastavují na stránce Datová schránka. */
const ACCESS_LINK = '/admin/databox?tab=access'

const recommended = computed<Method>(() => {
  const transport = queued.value?.transport
  if (!transport) return 'gateway'
  if (transport.automatic || transport.channel === 'gateway') return 'gateway'
  return 'mobile_key'
})

const outboxId = computed(() => queued.value?.outbox_id ?? 0)
const outboxLink = computed(() => `/admin/databox?tab=outbox&outbox=${outboxId.value}`)
const isReady = computed(() => (queued.value?.row?.dispatch_state ?? 'ready') === 'ready')

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KiB`
  return `${(bytes / 1024 / 1024).toLocaleString(locale.value, { maximumFractionDigits: 1 })} MiB`
}

async function enqueue() {
  loading.value = true
  error.value = ''
  try {
    queued.value = await epoSubmissionsApi.enqueueIsds(props.submissionId)
    method.value = recommended.value
    emit('changed')
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('reports.submissions.isds.enqueue_failed'))
  } finally {
    loading.value = false
  }
}

function selectMethod(next: Method) {
  method.value = next
  sendError.value = ''
  forbidden.value = false
}

function isForbidden(exception: unknown): boolean {
  return (exception as { response?: { status?: number } })?.response?.status === 403
}

function handleSendError(exception: unknown, fallback: string) {
  forbidden.value = isForbidden(exception)
  sendError.value = forbidden.value
    ? t('reports.submissions.isds.signing_forbidden')
    : apiErrorMessage(exception, fallback)
}

function finish(result: { row: OutboxSubmission; dispatched: boolean }) {
  if (queued.value) queued.value = { ...queued.value, row: result.row }
  if (result.dispatched) {
    sent.value = true
    emit('sent')
  } else {
    sendError.value = t('reports.submissions.isds.not_dispatched')
    emit('changed')
  }
}

async function startGateway() {
  if (!queued.value || busy.value) return
  busy.value = true
  sendError.value = ''
  try {
    gateway.value = await dataBoxApi.gatewayStartTax(queued.value.outbox_id)
  } catch (exception) {
    handleSendError(exception, t('reports.submissions.isds.gateway_failed'))
  } finally {
    busy.value = false
  }
}

function continueGateway() {
  // Plná navigace: cíl je v perimetru ISDS a vrací se na /isds-gateway/callback.
  if (gateway.value) window.location.assign(gateway.value.redirect_url)
}

async function sendWithPassword() {
  if (!queued.value || busy.value) return
  if (username.value.trim() === '' || password.value === '') {
    sendError.value = t('reports.submissions.isds.password_required')
    return
  }
  busy.value = true
  sendError.value = ''
  try {
    const result = await dataBoxApi.passwordSend(
      queued.value.outbox_id,
      'production',
      username.value.trim(),
      password.value,
    )
    finish(result)
  } catch (exception) {
    handleSendError(exception, t('databox.outbox.passwordSend.failed'))
  } finally {
    // Heslo se nedrží ani v paměti formuláře déle, než trvá odeslání.
    password.value = ''
    busy.value = false
  }
}

async function sendWithCertificate() {
  if (!queued.value || busy.value || !certificateConfirmed.value) return
  busy.value = true
  sendError.value = ''
  try {
    const result = await dataBoxApi.certificateSend(queued.value.outbox_id, 'production')
    finish(result)
  } catch (exception) {
    handleSendError(exception, t('databox.outbox.certificateSend.failed'))
  } finally {
    busy.value = false
  }
}

function mobileKeySent(dispatched: boolean) {
  if (dispatched) {
    sent.value = true
    emit('sent')
  } else {
    sendError.value = t('reports.submissions.isds.not_dispatched')
    emit('changed')
  }
}

async function cancelQueued() {
  if (!queued.value || busy.value) return
  if (!window.confirm(t('reports.submissions.isds.cancel_confirm'))) return
  busy.value = true
  sendError.value = ''
  try {
    await epoSubmissionsApi.cancelIsds(props.submissionId)
    emit('changed')
    emit('close')
  } catch (exception) {
    sendError.value = apiErrorMessage(exception, t('reports.submissions.isds.cancel_failed'))
  } finally {
    busy.value = false
  }
}

onMounted(() => { void enqueue() })
onBeforeUnmount(() => { password.value = '' })
</script>

<template>
  <div
    class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
    data-test="tax-isds-dialog"
    @click.self="emit('close')"
  >
    <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl bg-surface shadow-xl border border-neutral-200">
      <div class="sticky top-0 z-10 bg-surface px-5 py-4 border-b border-neutral-200 flex items-start justify-between gap-3">
        <div>
          <h2 class="font-semibold">{{ t('reports.submissions.isds.dialog_title') }}</h2>
          <p class="text-xs text-neutral-500 mt-1">{{ title }}</p>
        </div>
        <button type="button" class="p-2 text-neutral-500 hover:text-neutral-900" :aria-label="t('common.close')" @click="emit('close')">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
          </svg>
        </button>
      </div>

      <div class="p-5 space-y-4">
        <div v-if="loading" class="py-8 text-center text-sm text-neutral-500" data-test="tax-isds-loading">
          {{ t('reports.submissions.isds.enqueueing') }}
        </div>

        <div
          v-else-if="error"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="tax-isds-enqueue-error"
        >
          {{ error }}
        </div>

        <template v-else-if="queued">
          <div class="rounded-lg border border-primary-500/25 bg-primary-50 p-3 text-sm text-primary-900">
            <p>{{ t('reports.submissions.isds.receipt_note') }}</p>
            <p class="mt-1 text-primary-800">{{ t('reports.submissions.isds.defects_note') }}</p>
          </div>

          <div class="rounded-lg border border-neutral-200 p-3" data-test="tax-isds-recipient">
            <h3 class="font-medium text-sm">{{ t('reports.submissions.isds.recipient') }}</h3>
            <dl class="mt-2 space-y-1.5 text-sm">
              <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-neutral-500">{{ t('reports.submissions.isds.office') }}</dt>
                <dd class="text-right font-medium">{{ queued.recipient.name }}</dd>
              </div>
              <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-neutral-500">{{ t('reports.submissions.isds.box_id') }}</dt>
                <dd class="text-right font-mono">{{ queued.recipient.box_id }}</dd>
              </div>
              <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-neutral-500">{{ t('reports.submissions.isds.recipient_source') }}</dt>
                <dd class="text-right">
                  <span
                    class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium"
                    :class="queued.recipient.source === 'override'
                      ? 'border-warning-500/30 bg-warning-50 text-warning-700'
                      : 'border-neutral-200 bg-neutral-100 text-neutral-600'"
                  >
                    {{ queued.recipient.source === 'override'
                      ? t('reports.submissions.isds.source_override')
                      : t('reports.submissions.isds.source_default') }}
                  </span>
                </dd>
              </div>
            </dl>
            <div
              v-if="queued.office_mismatch"
              class="mt-3 rounded border border-warning-500/30 bg-warning-50 px-3 py-2 text-xs text-warning-800"
              role="alert"
              data-test="tax-isds-mismatch"
            >
              {{ t('reports.submissions.isds.office_mismatch', { code: queued.xml_office_code ?? '?' }) }}
            </div>
          </div>

          <div class="rounded-lg border border-neutral-200 p-3 text-sm">
            <dl class="space-y-1.5">
              <div>
                <dt class="text-xs text-neutral-500">{{ t('reports.submissions.isds.subject') }}</dt>
                <dd class="mt-0.5 break-words">{{ queued.subject }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('reports.submissions.isds.attachment') }}</dt>
                <dd class="mt-0.5 break-all" data-test="tax-isds-attachment">
                  {{ queued.attachment.filename }} · {{ formatSize(queued.attachment.bytes) }}
                  · <span class="font-mono text-xs" :title="queued.attachment.sha256">SHA-256 {{ queued.attachment.sha256.slice(0, 12) }}…</span>
                </dd>
              </div>
            </dl>
          </div>

          <div
            v-if="sent"
            class="rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-800"
            role="status"
            data-test="tax-isds-sent"
          >
            {{ t('reports.submissions.isds.sent') }}
          </div>

          <div
            v-else-if="!isReady"
            class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700"
            data-test="tax-isds-not-ready"
          >
            {{ t('reports.submissions.isds.not_ready', { state: t(`reports.submissions.isds.state_${queued.row.dispatch_state}`) }) }}
          </div>

          <template v-else>
            <div>
              <h3 class="font-medium text-sm">{{ t('reports.submissions.isds.method_title') }}</h3>
              <div class="mt-2 grid gap-2 sm:grid-cols-2" role="radiogroup" data-test="tax-isds-methods">
                <button
                  v-for="option in METHODS"
                  :key="option"
                  type="button"
                  role="radio"
                  :aria-checked="method === option"
                  class="rounded-lg border p-3 text-left transition"
                  :class="method === option
                    ? 'border-primary-500 bg-primary-50/60 ring-1 ring-primary-500/40'
                    : 'border-neutral-200 hover:border-primary-400 hover:bg-neutral-50'"
                  :data-test="`tax-isds-method-${option}`"
                  @click="selectMethod(option)"
                >
                  <span class="flex flex-wrap items-center gap-2 text-sm font-medium">
                    {{ t(`reports.submissions.isds.method_${option}`) }}
                    <span
                      v-if="recommended === option"
                      class="inline-flex rounded-full border border-success-500/30 bg-success-50 px-2 py-0.5 text-[11px] font-medium text-success-700"
                    >
                      {{ t('reports.submissions.isds.recommended') }}
                    </span>
                  </span>
                  <span class="mt-1 block text-xs text-neutral-500">{{ t(`reports.submissions.isds.method_${option}_hint`) }}</span>
                </button>
              </div>
              <p v-if="queued.transport.channel === 'manual_upload'" class="mt-2 text-xs text-neutral-500" data-test="tax-isds-transport-manual">{{ t('reports.submissions.isds.transport_manual') }}</p>
            </div>

            <div class="rounded-lg border border-neutral-200 p-3" :data-test="`tax-isds-panel-${method}`">
              <template v-if="method === 'gateway'">
                <template v-if="gateway">
                  <p class="text-sm text-neutral-700">{{ gateway.login_guidance }}</p>
                  <p v-if="!gateway.login_policy_documented" class="mt-2 text-xs text-warning-800">
                    {{ t('reports.submissions.isds.gateway_methods') }}
                  </p>
                  <button type="button" :class="[btnFilled('primary'), 'mt-3']" @click="continueGateway">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
                    </svg>
                    {{ t('reports.submissions.isds.gateway_continue') }}
                  </button>
                </template>
                <template v-else>
                  <p class="text-sm text-neutral-700">{{ t('reports.submissions.isds.gateway_intro') }}</p>
                  <button type="button" :class="[btnFilled('primary'), 'mt-3']" :disabled="busy" data-test="tax-isds-gateway-start" @click="startGateway">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
                    </svg>
                    {{ busy ? t('common.loading') : t('reports.submissions.isds.gateway_start') }}
                  </button>
                </template>
              </template>

              <template v-else-if="method === 'mobile_key'">
                <MobileKeySendButton :outbox-id="outboxId" environment="production" auto-open @sent="mobileKeySent" />
              </template>

              <template v-else-if="method === 'password'">
                <p class="text-sm text-neutral-700">{{ t('databox.outbox.passwordSend.intro') }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                  <label class="block text-sm">
                    <span class="font-medium">{{ t('databox.outbox.passwordSend.username') }}</span>
                    <input v-model="username" type="text" maxlength="128" autocomplete="off" class="form-input mt-1 w-full" data-test="tax-isds-username">
                  </label>
                  <label class="block text-sm">
                    <span class="font-medium">{{ t('databox.outbox.passwordSend.password') }}</span>
                    <input v-model="password" type="password" maxlength="512" autocomplete="off" class="form-input mt-1 w-full" data-test="tax-isds-password">
                  </label>
                </div>
                <button type="button" :class="[btnFilled('primary'), 'mt-3']" :disabled="busy" data-test="tax-isds-password-send" @click="sendWithPassword">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" />
                  </svg>
                  {{ busy ? t('common.loading') : t('databox.outbox.passwordSend.submit') }}
                </button>
              </template>

              <template v-else>
                <p class="text-sm text-neutral-700">{{ t('reports.submissions.isds.certificate_intro') }}</p>
                <label class="mt-3 flex items-start gap-2 text-sm font-medium">
                  <input v-model="certificateConfirmed" type="checkbox" class="mt-0.5" data-test="tax-isds-certificate-confirm">
                  <span>{{ t('reports.submissions.isds.certificate_confirm') }}</span>
                </label>
                <button
                  type="button"
                  :class="[btnFilled('primary'), 'mt-3']"
                  :disabled="busy || !certificateConfirmed"
                  data-test="tax-isds-certificate-send"
                  @click="sendWithCertificate"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" />
                  </svg>
                  {{ busy ? t('common.loading') : t('databox.outbox.certificateSend.action') }}
                </button>
              </template>

              <div
                v-if="sendError"
                class="mt-3 rounded border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700"
                role="alert"
                data-test="tax-isds-send-error"
              >
                {{ sendError }}
                <a v-if="forbidden" :href="outboxLink" class="mt-1 block font-medium underline">
                  {{ t('reports.submissions.isds.open_outbox') }}
                </a>
              </div>
            </div>

            <div class="rounded-lg border border-dashed border-neutral-300 p-3 text-xs text-neutral-600">
              <p>{{ t('reports.submissions.isds.manual_hint') }}</p>
              <a :href="outboxLink" :class="[btnOutlineSm('neutral'), 'mt-2']" data-test="tax-isds-open-outbox">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.inbox" />
                </svg>
                {{ t('reports.submissions.isds.open_outbox') }}
              </a>
              <p class="mt-3">{{ t('reports.submissions.isds.access_hint') }}</p>
              <a :href="ACCESS_LINK" :class="[btnOutlineSm('neutral'), 'mt-2']" data-test="tax-isds-open-access">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" />
                </svg>
                {{ t('reports.submissions.isds.open_access') }}
              </a>
            </div>
          </template>
        </template>
      </div>

      <div class="px-5 py-4 border-t border-neutral-200 flex flex-wrap justify-end gap-2">
        <button
          v-if="queued && isReady && !sent"
          type="button"
          :class="btnOutline('danger')"
          :disabled="busy"
          data-test="tax-isds-cancel"
          @click="cancelQueued"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
          </svg>
          {{ t('reports.submissions.isds.cancel_queued') }}
        </button>
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          {{ t('common.close') }}
        </button>
      </div>
    </div>
  </div>
</template>
