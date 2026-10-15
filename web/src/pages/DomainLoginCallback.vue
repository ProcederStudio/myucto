<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import { authApi } from '@/api/auth'
import { setCsrfToken } from '@/api/client'
import { clearTargetDomainLogin, readTargetDomainLogin } from '@/security/domainLogin'
import { isClientDomainAuthenticatedPath } from '@/security/clientRoutePolicy'
import { clearPortalLoggedOut } from '@/security/portalLogout'

const route = useRoute()
const { t } = useI18n()
const error = ref('')

onMounted(async () => {
  const pending = readTargetDomainLogin(route.query.request, route.query.code, route.query.state)
  if (!pending) {
    error.value = t('domain_login.invalid_callback')
    return
  }
  try {
    const result = await authApi.domainLoginExchange(
      pending.requestToken,
      pending.code,
      pending.state,
      pending.verifier,
    )
    setCsrfToken(result.csrf_token)
    clearTargetDomainLogin()
    if (!isClientDomainAuthenticatedPath(result.return_path)) {
      throw new Error('invalid_domain_login_return_path')
    }
    clearPortalLoggedOut()
    window.location.replace(result.return_path)
  } catch (e: any) {
    clearTargetDomainLogin()
    error.value = e?.response?.data?.error?.message || t('domain_login.exchange_failed')
  }
})
</script>

<template>
  <AppShell :title="t('domain_login.title')">
    <div class="w-full max-w-md rounded-lg border border-neutral-200 bg-surface p-6 text-center shadow-sm">
      <template v-if="!error">
        <div class="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-2 border-primary-200 border-t-primary-600" />
        <p class="text-sm text-neutral-600">{{ t('domain_login.finishing') }}</p>
      </template>
      <div v-else class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-3 text-sm text-danger-600">
        {{ error }}
      </div>
    </div>
  </AppShell>
</template>
