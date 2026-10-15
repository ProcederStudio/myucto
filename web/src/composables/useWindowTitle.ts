import { inject, onBeforeUnmount, toValue, watchEffect, type MaybeRefOrGetter } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'

export function useWindowTitle(
  title: MaybeRefOrGetter<string | null>,
  subtitle: MaybeRefOrGetter<string | null> = null,
): void {
  const context = inject(windowContextKey, null)
  if (!context?.setTitle) return
  watchEffect(() => context.setTitle?.(toValue(title), toValue(subtitle)))
  onBeforeUnmount(() => context.setTitle?.(null, null))
}
