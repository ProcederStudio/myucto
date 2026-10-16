import { computed, ref } from 'vue'
import { getWindowSupplierScope } from '@/workspace/supplierContext'
import { postWindowFrameMessage } from '@/workspace/frameProtocol'

/**
 * Stav diskové kvóty instalace (H-10), jak ho hlásí backend v hlavičkách
 * `X-Storage-Quota-*` na každé odpovědi.
 *
 * Proč hlavičkami a ne vlastním endpointem: admin se o blížícím se zámku musí
 * dozvědět DŘÍV, než mu přestane jít uložit doklad. Kdyby si stav tahala jedna
 * stránka vlastním dotazem, uvidí ho jen ten, kdo na ni náhodou zajde. Takhle
 * ho nese každá odpověď, kterou aplikace stejně dostává.
 *
 * ⚠️ `null` znamená „nevím / neměřeno", NIKDY nulu. Nezměřená instance se nesmí
 * ukázat jako „0 %, vše v pořádku" — prázdná a nezměřená instance vypadají
 * v datech skoro stejně, ale znamenají opak. Backend proto posílá hlavičky jen
 * tehdy, když je co hlásit, a prázdnou hodnotu čteme jako `null`.
 */
export type StorageQuotaBannerState = 'warning' | 'exhausted'

const state = ref<StorageQuotaBannerState | null>(null)
const percent = ref<number | null>(null)
const usedBytes = ref<number | null>(null)
const limitBytes = ref<number | null>(null)

/**
 * Vyčerpaná kvóta zvlášť — a LEPKAVĚ.
 *
 * `state` výše je momentka poslední odpovědi a smí zmizet, jakmile server
 * přestane hlásit. Blokující stav se ale takhle chovat NESMÍ: zápisy jsou
 * zastavené a jediná linka, která to uživateli říká, se nemá schovat jen proto,
 * že další požadavek spadl na 401, 500 nebo timeout. „Nevím" není „v pořádku".
 *
 * Proto se ruší jen na DŮVĚRYHODNOU odpověď (2xx z interceptoru úspěchu), kde
 * chybějící hlavička opravdu znamená, že už není co hlásit. Chybová odpověď smí
 * stav nastavit (507 nese hlavičky), ale nikdy ho nesmí zhasnout.
 */
const criticalExhausted = ref(false)

/** Hlavičky z axiosu umí být plain objekt i AxiosHeaders — čti obojí. */
function header(headers: unknown, name: string): string | null {
  if (!headers || typeof headers !== 'object') return null
  const bag = headers as Record<string, unknown> & { get?: (key: string) => unknown }
  const raw = typeof bag.get === 'function' ? bag.get(name) : (bag[name] ?? bag[name.toLowerCase()])
  if (raw === null || raw === undefined) return null
  const value = String(raw).trim()
  return value === '' ? null : value
}

function numberOrNull(value: string | null): number | null {
  if (value === null) return null
  const parsed = Number(value)
  // NaN ani nekonečno se nesmí propsat jako číslo — raději „nevím".
  return Number.isFinite(parsed) ? parsed : null
}

/**
 * Přečte stav z odpovědi. Volá se z interceptoru pro úspěch i pro chybu:
 * odmítnutý zápis (507) je právě ta odpověď, u které stav zajímá nejvíc.
 *
 * `trusted` = odpověď, jejíž MLČENÍ se smí číst jako „všechno v pořádku".
 * Splňuje to jen úspěšná odpověď; chybová (401, 500, 507) nese hlavičky jen
 * někdy, takže z ní nejde usoudit nic než to, co v ní opravdu je.
 */
export function readStorageQuotaHeaders(headers: unknown, options: { trusted?: boolean } = {}): void {
  const trusted = options.trusted !== false
  const scope = getWindowSupplierScope()
  if (scope) {
    const names = ['state', 'percent', 'used-bytes', 'limit-bytes'].map(name => `x-storage-quota-${name}`)
    postWindowFrameMessage({ type: 'myucto-window-quota', id: scope.windowId, trusted,
      headers: Object.fromEntries(names.map(name => [name, header(headers, name)])) })
  }
  const next = header(headers, 'x-storage-quota-state')
  if (next !== 'warning' && next !== 'exhausted') {
    // Nedůvěryhodná odpověď bez hlaviček nic neříká — poslední známý stav
    // zůstává. Schovat linku kvůli spadlému požadavku je horší chyba než ji
    // ukázat o pár vteřin déle.
    if (!trusted) return

    // Backend hlavičky neposlal = není co hlásit (zdravá instalace, vypnutý
    // režim, nebo NEZMĚŘENÁ spotřeba). Ve všech třech případech banner zmizí.
    state.value = null
    percent.value = null
    usedBytes.value = null
    limitBytes.value = null
    criticalExhausted.value = false
    return
  }

  state.value = next
  criticalExhausted.value = next === 'exhausted'
  percent.value = numberOrNull(header(headers, 'x-storage-quota-percent'))
  usedBytes.value = numberOrNull(header(headers, 'x-storage-quota-used-bytes'))
  limitBytes.value = numberOrNull(header(headers, 'x-storage-quota-limit-bytes'))
}

export const storageQuota = {
  state: computed(() => state.value),
  percent: computed(() => percent.value),
  usedBytes: computed(() => usedBytes.value),
  limitBytes: computed(() => limitBytes.value),
  isWarning: computed(() => state.value === 'warning'),
  isExhausted: computed(() => state.value === 'exhausted'),
  /**
   * Poslední ZNÁMÝ blokující stav. Na rozdíl od `isExhausted` nezhasne kvůli
   * odpovědi, ze které se stav nedal přečíst — viz `criticalExhausted`.
   */
  isCriticallyExhausted: computed(() => criticalExhausted.value),
}

/** Formát „1,4 GB" / „820 MB“ pro banner. Null zůstává null, ne nula. */
export function formatQuotaBytes(bytes: number | null): string | null {
  if (bytes === null) return null
  if (bytes >= 1024 * 1024 * 1024) {
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
  }
  return `${Math.round(bytes / (1024 * 1024))} MB`
}
