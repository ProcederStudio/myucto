<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '@/stores/workspace'
import { formatShortcut, useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'

const { t } = useI18n()
const workspace = useWorkspaceStore()
const shortcuts = useKeyboardShortcuts()
</script>

<template>
  <button
    type="button"
    class="relative inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-primary-700 focus-visible:ring-2 focus-visible:ring-primary-400"
    :class="workspace.windowSwitcherOpen ? 'bg-neutral-100 text-primary-700' : ''"
    :title="[t('workspace.switch_windows'), formatShortcut(shortcuts.comboFor('windows.switcher'))].filter(Boolean).join(' · ')"
    :aria-label="t('workspace.windows')"
    :aria-expanded="workspace.windowSwitcherOpen"
    aria-haspopup="dialog"
    @click="workspace.windowSwitcherOpen = !workspace.windowSwitcherOpen"
  >
    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
      <rect x="3" y="7" width="14" height="13" rx="2" />
      <path d="M7 7V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2M3 11h14" />
    </svg>
    <span v-if="workspace.windows.length > 1" class="absolute -right-0.5 -top-0.5 min-w-3.5 rounded-full bg-primary-600 px-0.5 text-center text-[9px] leading-3.5 text-white" aria-hidden="true">{{ workspace.windows.length }}</span>
  </button>
</template>
