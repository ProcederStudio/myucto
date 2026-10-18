import { onActivated, ref } from 'vue'
import { logbookApi, type TripTemplate } from '@/api/logbook'

/**
 * Pravidelné cesty pro výběr v knize jízd. Stránka drží záložky v <KeepAlive>, takže
 * po návratu ze záložky Pravidelné cesty by komponenta nabízela seznam z prvního
 * načtení. Při každém dalším zobrazení se proto načte znovu (spolu s `onReactivate`).
 */
export function useTripTemplates(onReactivate?: () => void) {
  const templates = ref<TripTemplate[]>([])

  async function loadTemplates() {
    try { templates.value = await logbookApi.listTemplates() } catch { templates.value = [] }
  }

  let firstActivation = true
  onActivated(() => {
    if (firstActivation) { firstActivation = false; return }
    void loadTemplates()
    onReactivate?.()
  })

  return { templates, loadTemplates }
}
