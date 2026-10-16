<script lang="ts">
import { defineComponent, h, inject, Teleport, type TeleportProps } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'

export default defineComponent({
  name: 'WorkspaceTeleport',
  props: { disabled: Boolean },
  setup(props, { slots }) {
    const context = inject(windowContextKey, null)
    return () => h(Teleport as unknown as { new(): { $props: TeleportProps } }, { to: context?.portal ?? 'body', disabled: props.disabled }, slots.default?.())
  },
})
</script>
