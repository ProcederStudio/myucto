import { ref, computed, onMounted } from 'vue'
import type { SortPref } from '@/api/preferences'
import { ensurePrefsLoaded, getPagePrefs, patchPagePrefs } from '@/composables/useUserPrefs'

export interface ColumnDef {
  key: string               // stabilní id — jde do user_preferences.payload.hidden
  labelKey: string          // i18n klíč (reuse existujících klíčů hlaviček)
  required?: boolean        // nelze skrýt (číslo dokladu, částka, akce)
  sortable?: boolean        // jen stránky se zapnutým sortem (§5.6)
  defaultHidden?: boolean   // doplňkový sloupec — dokud uživatel nesáhne na výběr, je skrytý
  available?: () => boolean // podmíněný sloupec, např. dimenze zapnuté pro firmu
}

export function useTablePrefs(pageKey: string, columns: ColumnDef[]) {
  const prefs = getPagePrefs(pageKey)
  const ready = ref(false)
  const columnLabelsDefault = ref(false)
  const showColumnLabels = computed(() => prefs.value.flags?.column_labels ?? columnLabelsDefault.value)
  const orderedColumns = computed(() => {
    const byKey = new Map(columns.map(column => [column.key, column]))
    const keys = Array.isArray(prefs.value.column_order) ? prefs.value.column_order : []
    const result: ColumnDef[] = []
    for (const key of keys) {
      const column = byKey.get(key)
      if (column) { result.push(column); byKey.delete(key) }
    }
    return [...result, ...byKey.values()]
  })
  function moveColumn(source: string, target: string, after = false): void {
    if (source === target || !columns.some(c => c.key === source) || !columns.some(c => c.key === target)) return
    const keys = orderedColumns.value.map(c => c.key).filter(key => key !== source)
    keys.splice(keys.indexOf(target) + (after ? 1 : 0), 0, source)
    patchPagePrefs(pageKey, { column_order: keys })
  }
  function resetColumnOrder(): void {
    patchPagePrefs(pageKey, { column_order: null })
  }

  onMounted(async () => { await ensurePrefsLoaded(); ready.value = true })

  // Doplňkové sloupce (defaultHidden) jsou skryté, dokud je uživatel EXPLICITNĚ neodkryje —
  // odkrytí se eviduje v prefs.shown. Efektivně skryté = (hidden ?? []) ∪ (defaultHidden − shown).
  // Díky tomu se nové doplňkové sloupce nikdy samy nezobrazí ani uživatelům s dříve uloženým
  // hidden polem (to nové klíče neobsahuje). resetColumns (hidden+shown: null) = default stránky.
  const defaultHiddenKeys = columns.filter(c => c.defaultHidden && !c.required).map(c => c.key)
  const hidden = computed<string[]>(() => {
    const shown = prefs.value.shown ?? []
    const set = new Set(prefs.value.hidden ?? [])
    for (const k of defaultHiddenKeys) {
      if (!shown.includes(k)) set.add(k)
    }
    return [...set]
  })

  // R9: isVisible(key) = required || !hidden.includes(key). Neznámý (nový) sloupec = viditelný,
  // pokud není defaultHidden.
  function isVisible(key: string): boolean {
    const col = columns.find(c => c.key === key)
    if (col?.available && !col.available()) return false
    if (col?.required) return true
    return !hidden.value.includes(key)
  }

  function visibleNonRequiredCount(): number {
    return columns.filter(c => !c.required && isVisible(c.key)).length
  }

  function toggleColumn(key: string): void {
    const col = columns.find(c => c.key === key)
    if (!col || col.required) return              // required = no-op
    const hiddenSet = new Set(hidden.value)
    const shownSet = new Set(prefs.value.shown ?? [])
    if (hiddenSet.has(key)) {
      hiddenSet.delete(key)
      if (defaultHiddenKeys.includes(key)) shownSet.add(key)
    } else {
      if (visibleNonRequiredCount() <= 1) return  // nedovol skrýt poslední viditelný ne-required
      hiddenSet.add(key)
      shownSet.delete(key)
    }
    patchPagePrefs(pageKey, { hidden: [...hiddenSet], shown: [...shownSet] })
  }

  function resetColumns(): void {
    patchPagePrefs(pageKey, { hidden: null, shown: null })
  }

  function setVisibleColumns(keys: string[]): void {
    const visible = new Set(keys)
    const hidden = columns.filter(c => !c.required && !visible.has(c.key)).map(c => c.key)
    const shown = columns.filter(c => c.defaultHidden && visible.has(c.key)).map(c => c.key)
    patchPagePrefs(pageKey, { hidden, shown })
  }

  /*
   * Dynamické sloupce (např. `component:PRIPLATEK_BOZP` v rychlém měsíčním
   * vstupu) nejsou v `columns` — jejich seznam přichází ze serveru a u každé
   * firmy je jiný. Ukládají se do TÝCHŽ polí `shown`/`hidden`: výslovně
   * zapnutý klíč je v `shown`, výslovně vypnutý v `hidden`, a co uživatel
   * nikdy nezměnil, řídí se výchozím stavem stránky. Předpona klíče (`component:`)
   * zajistí, že se nepotká s pevným sloupcem a ColumnPicker ho neukáže.
   */
  function isDynamicShown(key: string, defaultShown: boolean): boolean {
    if (prefs.value.shown?.includes(key)) return true
    if (prefs.value.hidden?.includes(key)) return false
    return defaultShown
  }
  function setDynamicShown(changes: Record<string, boolean>): void {
    const hiddenSet = new Set(prefs.value.hidden ?? [])
    const shownSet = new Set(prefs.value.shown ?? [])
    for (const [key, shown] of Object.entries(changes)) {
      if (shown) {
        shownSet.add(key)
        hiddenSet.delete(key)
      } else {
        hiddenSet.add(key)
        shownSet.delete(key)
      }
    }
    patchPagePrefs(pageKey, { hidden: [...hiddenSet], shown: [...shownSet] })
  }

  const density = computed<'comfortable' | 'compact'>(() => prefs.value.density ?? 'comfortable')
  function setDensity(d: 'comfortable' | 'compact'): void {
    patchPagePrefs(pageKey, { density: d })
  }
  const densityClass = computed(() => (density.value === 'compact' ? 'tbl-compact' : 'tbl-comfortable'))

  // Lepkavé přepínače pohledu (např. „rozpad po analytikách"). Čtou se až po
  // ensurePrefsLoaded() — stránka si je proto bere v onMounted PŘED prvním load().
  function flag(key: string, fallback = false): boolean {
    return prefs.value.flags?.[key] ?? fallback
  }
  function setFlag(key: string, value: boolean): void {
    patchPagePrefs(pageKey, { flags: { ...(prefs.value.flags ?? {}), [key]: value } })
  }
  function setColumnLabels(value: boolean): void {
    setFlag('column_labels', value)
  }
  function option<T>(key: string, fallback: T): T {
    return (prefs.value.options?.[key] as T | undefined) ?? fallback
  }
  function setOption(key: string, value: unknown): void {
    patchPagePrefs(pageKey, { options: { ...(prefs.value.options ?? {}), [key]: value } })
  }

  const sort = computed<SortPref | null>(() => prefs.value.sort ?? null)
  // Cyklus desc → asc → výchozí; persistuje do prefs.
  function toggleSort(key: string): void {
    const cur = sort.value
    let next: SortPref | null
    if (!cur || cur.key !== key) next = { key, dir: 'desc' }
    else if (cur.dir === 'desc') next = { key, dir: 'asc' }
    else next = null
    patchPagePrefs(pageKey, { sort: next })
  }
  function clearSort(): void {
    patchPagePrefs(pageKey, { sort: null })
  }

  return {
    columns,
    orderedColumns, moveColumn, resetColumnOrder,
    isVisible, toggleColumn, resetColumns, setVisibleColumns,
    isDynamicShown, setDynamicShown,
    density, setDensity, densityClass,
    sort, toggleSort, clearSort,
    flag, setFlag,
    option, setOption,
    columnLabelsDefault, showColumnLabels, setColumnLabels,
    ready,
  }
}

export type TablePrefsCtrl = ReturnType<typeof useTablePrefs>
