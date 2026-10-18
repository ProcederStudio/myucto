<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { type RouteLocationRaw } from 'vue-router'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ to: RouteLocationRaw | null; label: string }>()
const { t } = useI18n()
const toast = useToast()
const navigation = useWorkspaceNavigation()
const auth = useAuthStore()
const actions = computed<RowAction[]>(() => props.to && auth.canRead('reports') ? [{
  key: 'vat-book', label: props.label, icon: 'doc', variant: 'neutral', to: props.to,
  workspaceOpen: 'beside', onNavigate: onClick,
}] : [])

function onClick(event: MouseEvent) {
  if (!props.to || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  void navigation.openBeside(props.to).catch(() => toast.error(t('common.error')))
}
</script>

<template>
  <RowActionsMenu v-if="actions.length" :actions="actions" :inline-count="0" :menu-label="label" />
</template>
