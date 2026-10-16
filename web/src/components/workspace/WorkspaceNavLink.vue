<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useWorkspaceStore } from '@/stores/workspace'

const props = withDefaults(defineProps<{
  to: RouteLocationRaw
  external?: boolean
  target?: string
  fresh?: boolean
}>(), {
  external: false,
  target: undefined,
  fresh: false,
})

const globalRouter = useRouter()
const workspace = useWorkspaceStore()
const navigation = useWorkspaceNavigation()
const href = computed(() => props.external && typeof props.to === 'string'
  ? props.to
  : globalRouter.resolve(props.to).href)
const active = computed(() => {
  if (props.external) return false
  const target = globalRouter.resolve(props.to)
  const path = workspace.activeFullPath.split(/[?#]/, 1)[0]
  return target.path === '/' ? path === '/' : path === target.path || path.startsWith(`${target.path}/`)
})

function onClick(event: MouseEvent): void {
  if (props.external || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  void navigation.navigate(props.to, props.fresh ? { fresh: true } : undefined)
}

function onDragStart(event: DragEvent): void {
  if (props.external || !event.dataTransfer) return
  const url = new URL(href.value, window.location.origin).href
  event.dataTransfer.setData('text/uri-list', url)
  event.dataTransfer.setData('text/plain', url)
  event.dataTransfer.setData('application/x-myucto-route', url)
  event.dataTransfer.effectAllowed = 'link'
}
</script>

<template>
  <a
    :href="href"
    :target="external ? (target ?? '_blank') : target"
    :rel="external ? 'noopener' : undefined"
    :aria-current="active ? 'page' : undefined"
    @click="onClick"
    @dragstart="onDragStart"
  ><slot /></a>
</template>
