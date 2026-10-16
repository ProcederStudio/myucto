<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'

/**
 * Plovoucí lišta hromadných akcí.
 *
 * Why: akce nad výběrem byly v hlavičce stránky vedle nadpisu. Objevovaly se a
 * mizely podle počtu vybraných řádků, takže při každém zaškrtnutí poskočilo
 * tlačítko „Nová faktura" jinam — a hlavně: uživatel vybírá řádky dole v tabulce,
 * zatímco akce k nim byly úplně nahoře mimo zorné pole.
 *
 * Lišta je proto u spodní hrany, nad patičkou aplikace, a nese i počet vybraných
 * a způsob, jak výběr zrušit. Teleport na body, aby ji neořízl žádný wrapper
 * s overflow.
 */
defineProps<{
  /** Počet vybraných položek; při nule se lišta nevykresluje. */
  count: number
}>()

const emit = defineEmits<{ clear: [] }>()
const { t } = useI18n()
</script>

<template>
  <WorkspaceTeleport>
    <Transition
      enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1.2,0.36,1)]"
      enter-from-class="opacity-0 translate-y-4"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0 translate-y-2"
    >
      <div v-if="count > 0"
        class="nav-inverted fixed inset-x-0 bottom-3 lg:bottom-14 z-40 flex justify-center px-4 pointer-events-none">
        <div class="pointer-events-auto flex flex-wrap items-center gap-2 rounded-xl bg-surface-raised px-3 py-2 shadow-2xl ring-1 ring-neutral-300">
          <span class="px-1.5 text-sm font-medium text-neutral-900 tabular-nums whitespace-nowrap">
            {{ t('common.bulk_selected', { n: count }) }}
          </span>
          <span class="mx-1 h-5 w-px bg-neutral-300" aria-hidden="true"></span>

          <slot />

          <button type="button"
            class="cursor-pointer ml-1 inline-flex h-8 items-center gap-1 rounded-md px-2 text-xs text-neutral-500 transition-colors hover:text-neutral-900"
            @click="emit('clear')">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
            {{ t('common.bulk_clear') }}
          </button>
        </div>
      </div>
    </Transition>
  </WorkspaceTeleport>
</template>
