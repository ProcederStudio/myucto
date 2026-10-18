import { nextTick } from 'vue'

/**
 * Po přidání nového řádku zaměří jeho první textový vstup (popis).
 *
 * Zaměří POSLEDNÍ VIDITELNÝ prvek odpovídající selektoru — desktop i mobilní
 * varianta řádku se renderují obě zároveň, ta skrytá (Tailwind `hidden`/`md:hidden`)
 * má `offsetParent === null`, takže ji přeskočíme a trefíme tu na aktuální šířce.
 *
 * Selektor cílí na `[data-row-input="<marker>"]`; každý kontext má vlastní marker,
 * aby se např. modal výkazu práce netrefil do inputů editoru pod ním.
 */
export function focusLastRow(selector: string, root: ParentNode = document): void {
  void nextTick(() => {
    const els = Array.from(root.querySelectorAll<HTMLElement>(selector))
      .filter(el => el.offsetParent !== null)
    els[els.length - 1]?.focus()
  })
}

/**
 * Zaměří pole řádku `index` (pořadí ve viditelném rozložení) a vrátí, zda existuje.
 * Rychlé zadávání položek: výběr karty → množství, Enter v množství → popis dalšího
 * řádku. Každý řádek má pole s daným markerem právě jednou v každém rozložení,
 * takže pořadí viditelných polí = pořadí řádků. Textové pole se zároveň označí,
 * aby napsané číslo přepsalo předvyplněnou hodnotu.
 */
export async function focusRowField(selector: string, index: number, root: ParentNode = document): Promise<boolean> {
  await nextTick()
  const el = Array.from(root.querySelectorAll<HTMLElement>(selector))
    .filter(e => e.offsetParent !== null)[index]
  if (!el) return false
  el.focus()
  if (el instanceof HTMLInputElement) el.select()
  return true
}
