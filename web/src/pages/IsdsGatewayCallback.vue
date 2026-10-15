<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type GatewayComplete } from '@/api/dataBox'
import { btnOutline } from '@/components/ui/buttonStyles'
import { navigateFullPage } from '@/workspace/fullPageNavigation'
import { useRoute, useRouter } from 'vue-router'
import { pageLocation, replacePageLocation } from '@/workspace/pageLocation'

const { t } = useI18n()
const loading = ref(true)
const error = ref('')
const completed = ref<GatewayComplete | null>(null)
const route = useRoute()
const router = useRouter()

onMounted(async () => {
  const url = pageLocation(route)
  const params = url.searchParams
  const appToken = params.get('appToken') ?? ''
  const sessionId = params.get('sessionId') ?? ''

  params.delete('appToken')
  params.delete('sessionId')
  replacePageLocation(router, url)

  if (appToken === '' || sessionId === '') {
    error.value = t('databox.gateway.callback.invalid')
    loading.value = false
    return
  }

  // Relaci dokončí ta cesta, ke které má uživatel oprávnění: obecná fronta,
  // mzdová podání, daňová podání. Dál se padá jen na 403, jiná chyba je odpověď.
  const completions = [
    dataBoxApi.gatewayComplete,
    dataBoxApi.gatewayCompletePayroll,
    dataBoxApi.gatewayCompleteTax,
  ]

  try {
    for (const [index, complete] of completions.entries()) {
      try {
        completed.value = await complete(appToken, sessionId)
        break
      } catch (exception) {
        const forbidden = isAxiosError(exception) && exception.response?.status === 403
        if (!forbidden || index === completions.length - 1) throw exception
      }
    }

    if (!completed.value) return

    if (completed.value.redirect_url) {
      navigateFullPage(completed.value.redirect_url)
      return
    }
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('databox.gateway.callback.failed'))
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="mx-auto w-full max-w-2xl space-y-6">
    <header>
      <h1 class="text-xl font-semibold text-neutral-900">
        {{ t('databox.gateway.callback.title') }}
      </h1>
    </header>

    <section class="rounded-xl border border-neutral-200 bg-surface p-6 shadow-sm">
      <div v-if="loading" class="flex items-center gap-3 text-sm text-neutral-600" data-test="isds-callback-loading">
        <span class="h-5 w-5 animate-spin rounded-full border-2 border-primary-200 border-t-primary-600" />
        {{ t('databox.gateway.callback.finishing') }}
      </div>

      <div
        v-else-if="error"
        class="rounded-lg border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
        data-test="isds-callback-error"
      >
        {{ error }}
      </div>

      <div v-else-if="completed" class="space-y-3" data-test="isds-callback-result">
        <p class="font-medium text-neutral-900">
          {{ t(`databox.gateway.state.${completed.state}`) }}
        </p>
        <p class="text-sm text-neutral-600">{{ completed.message }}</p>
        <p v-if="completed.external_message_id" class="text-sm text-neutral-600">
          {{ t('databox.gateway.callback.messageId', { value: completed.external_message_id }) }}
        </p>
      </div>

      <div v-if="!loading" class="mt-5 flex flex-wrap gap-2">
        <RouterLink to="/payroll/submissions" :class="btnOutline('neutral')">
          {{ t('databox.gateway.callback.payroll') }}
        </RouterLink>
        <RouterLink to="/reports/submissions" :class="btnOutline('neutral')">
          {{ t('databox.gateway.callback.tax') }}
        </RouterLink>
        <RouterLink to="/admin/databox?tab=outbox" :class="btnOutline('neutral')">
          {{ t('databox.gateway.callback.outbox') }}
        </RouterLink>
      </div>
    </section>
  </div>
</template>
