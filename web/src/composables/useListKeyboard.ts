import { ref, onMounted, onBeforeUnmount, type Ref } from 'vue'
import { usePaneActivity, usePaneId } from '@/workspace/paneActivity'
import { usePaneDom } from '@/composables/usePaneDom'

/**
 * Klávesové ovládání seznamů: j/k pohyb, Enter otevřít, x označit, Esc zrušit výběr.
 *
 * Why: účetní projede za den stovky řádků a mezi klávesnicí a myší přepíná u každého.
 * Vzor j/k/Enter je zavedený (mail klienti, issue trackery) a nevyžaduje modifikátory,
 * takže nekoliduje s prohlížečem.
 *
 * Zkratky se ignorují, když uživatel píše do pole nebo je otevřený dialog —
 * jinak by „x" v hledání označovalo řádky. Bez toho je klávesové ovládání
 * v aplikaci plné formulářů nepoužitelné.
 */

interface Options {
  /** Počet řádků; při změně se index ořízne, aby neukazoval mimo seznam. */
  count: () => number
  /** Otevřít aktuální řádek (Enter). */
  open: (index: number) => void
  /** Přepnout výběr aktuálního řádku (x). Volitelné. */
  toggle?: (index: number) => void
  /** Zrušit výběr (Esc). Volitelné. */
  clear?: () => void
}

export function useListKeyboard(opts: Options): { activeIndex: Ref<number> } {
  const activeIndex = ref(-1)
  const paneActive = usePaneActivity()
  const paneId = usePaneId()
  const paneDom = usePaneDom()

  function isTyping(): boolean {
    const el = document.activeElement as HTMLElement | null
    if (!el) return false
    if (el.isContentEditable) return true
    const tag = el.tagName
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
  }

  /** Dialogy si klávesnici řídí samy (Esc zavírá, šipky vybírají v seznamu). */
  function inDialog(): boolean {
    if ([...document.querySelectorAll('[role="dialog"][aria-modal="true"]')]
      .some(dialog => !dialog.closest('[hidden], [aria-hidden="true"]'))) return true
    if (paneDom.querySelector('[role="dialog"], [aria-modal="true"]')) return true
    return paneId.value !== null && document.querySelector(
      `[data-workspace-pane="${paneId.value}"][role="dialog"], [data-workspace-pane="${paneId.value}"] [role="dialog"], [data-workspace-pane="${paneId.value}"] [aria-modal="true"]`,
    ) !== null
  }

  function scrollActiveIntoView(): void {
    paneDom.querySelector('[data-row-active="true"]')?.scrollIntoView({ block: 'nearest' })
  }

  function move(delta: number): void {
    const n = opts.count()
    if (n === 0) return
    const next = activeIndex.value < 0
      ? (delta > 0 ? 0 : n - 1)
      : Math.min(Math.max(activeIndex.value + delta, 0), n - 1)
    activeIndex.value = next
    requestAnimationFrame(scrollActiveIntoView)
  }

  function onKey(e: KeyboardEvent): void {
    if (!paneActive.value || e.defaultPrevented) return
    if (e.ctrlKey || e.metaKey || e.altKey) return
    if (isTyping() || inDialog()) return

    switch (e.key) {
      case 'j':
      case 'ArrowDown':
        e.preventDefault()
        move(1)
        break
      case 'k':
      case 'ArrowUp':
        e.preventDefault()
        move(-1)
        break
      case 'Enter':
        if (activeIndex.value >= 0) {
          e.preventDefault()
          opts.open(activeIndex.value)
        }
        break
      case 'x':
        if (activeIndex.value >= 0 && opts.toggle) {
          e.preventDefault()
          opts.toggle(activeIndex.value)
        }
        break
      case 'Escape':
        // Kurzor v seznamu se ruší VŽDY, i když stránka nemá výběr (klienti).
        // Dokud to bylo schované za `if (opts.clear)`, na takovém seznamu Esc
        // nedělal nic a zvýrazněný řádek už nešlo zhasnout.
        if (activeIndex.value >= 0 || opts.clear) {
          e.preventDefault()
          activeIndex.value = -1
          opts.clear?.()
        }
        break
    }
  }

  onMounted(() => window.addEventListener('keydown', onKey))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

  return { activeIndex }
}
