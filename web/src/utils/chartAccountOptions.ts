// Pořadí účtů v našeptávači účtové osnovy (`<datalist>`).
//
// Prohlížeč nabídku nepřerovnává — po napsání „311" projde options v pořadí DOM
// a ukáže ty, které se shodují. Kdo řadí jen podle kódu, dostane nahoře syntetiku
// (`311`) a analytiky až pod ní. U firmy převedené na analytické účty je to
// obráceně: účtovatelný je LIST (`311.100`), syntetika je jen hlavička, na kterou
// se ručně účtovat nemá — a nabídka, která ji dává první, tiše vrací doklady zpět
// na syntetiku.
//
// Proto: syntetika, která má v osnově aktivní analytiky, jde AŽ ZA ně. Firmy bez
// analytik se nemění — syntetika bez dětí zůstává tam, kde ji řazení podle kódu
// nechá.

import type { ChartAccount } from '@/api/accounting'

/**
 * Aktivní účty seřazené pro našeptávač: analytiky před svou syntetikou, jinak
 * podle kódu.
 *
 * @param accounts syrový seznam z `accountingApi.listAccounts()`
 * @param filter   volitelné zúžení (např. jen bankovní strana `221*`); aplikuje
 *                 se PŘED řazením, takže odfiltrovaná syntetika svoje analytiky
 *                 z nabídky nestáhne
 */
export function accountPickerOptions(
  accounts: readonly ChartAccount[],
  filter?: (a: ChartAccount) => boolean,
): ChartAccount[] {
  const active = accounts
    .filter(a => a.is_active && (filter === undefined || filter(a)))
    .sort((a, b) => a.account_code.localeCompare(b.account_code))

  const childrenOf = new Map<number, ChartAccount[]>()
  for (const a of active) {
    if (a.parent_id === null) continue
    const bucket = childrenOf.get(a.parent_id)
    if (bucket) bucket.push(a)
    else childrenOf.set(a.parent_id, [a])
  }
  if (childrenOf.size === 0) return active

  const out: ChartAccount[] = []
  const emitted = new Set<number>()
  const emit = (a: ChartAccount): void => {
    if (emitted.has(a.id)) return
    emitted.add(a.id)
    // Vnořená analytika (analytika analytiky) se drží svého rodiče stejně jako
    // analytika syntetiky — rekurze pokrývá libovolnou hloubku osnovy.
    for (const kid of childrenOf.get(a.id) ?? []) emit(kid)
    out.push(a)
  }
  for (const a of active) emit(a)
  return out
}

export function purchaseExpenseAccountOptions(
  accounts: readonly ChartAccount[],
  expenseKind?: string | null,
): ChartAccount[] {
  return accountPickerOptions(accounts, account =>
    expenseKind === 'fixed_asset' ? account.account_code.startsWith('0') : account.account_code.startsWith('5'),
  )
}
