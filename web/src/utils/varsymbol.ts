// Klient-side render varsymbol templatu — zrcadlí PHP VarsymbolGenerator::render().
// Používá se pro live preview v Settings; v editoru faktury preferujeme volání
// /api/invoices/preview-varsymbol (zná aktuální hodnotu counteru z DB).
//
// Placeholdery: {YYYY}, {YY}, {MM}, {C+} (variabilní padding 1..6 znaků),
// {PP} = daňový prefix přijaté faktury (jen náhled — dosadí se vzorek "PF").
//
// Datumové tokeny umí volitelný posun: {YY+30}, {MM-1}, {YYYY+1}. Rok se posouvá po
// letech, měsíc po měsících včetně přetečení roku; posun je čistě zobrazovací a
// neovlivňuje, kdy se čítač resetuje. Musí zůstat 1:1 s PHP InvoiceNumberFormat.

/** Regex datumového tokenu — zrcadlí InvoiceNumberFormat::DATE_TOKEN_RE. */
const DATE_TOKEN_RE = /\{(YYYY|YY|MM)([+-]\d{1,3})?\}/g

function dateTokenValue(token: string, offset: number, date: Date): string {
  if (token === 'MM') {
    // Kotvení na 1. den měsíce — jinak by 31. 1. s {MM+1} přeteklo na březen.
    return String(new Date(date.getFullYear(), date.getMonth() + offset, 1).getMonth() + 1)
      .padStart(2, '0')
  }
  const shifted = String(date.getFullYear() + offset).padStart(4, '0')
  return token === 'YYYY' ? shifted : shifted.slice(-2)
}

export function renderVarsymbolTemplate(
  template: string | null | undefined,
  date: Date,
  counter: number,
): string {
  if (!template) return ''
  let out = template
    .replaceAll('{PP}', 'PF') // náhledový vzorek; reálný prefix dle daňového typu dokladu
    .replace(DATE_TOKEN_RE, (_match, token: string, offset?: string) =>
      dateTokenValue(token, offset ? Number(offset) : 0, date))
  out = out.replace(/\{(C+)\}/g, (_match, cs: string) => {
    return String(counter).padStart(cs.length, '0')
  })
  return out
}

/**
 * True pokud template obsahuje counter placeholder {C+}. Bez něj generator selže
 * (resp. dva inserty se stejným varsymbolem narazí na unique constraint).
 */
export function hasCounterPlaceholder(template: string | null | undefined): boolean {
  if (!template) return false
  return /\{C+\}/.test(template)
}

// Featura G (private/REAL_data_followup_UX.md) — client-side zrcadlo
// VarsymbolSeriesCollisionChecker::digitSkeleton()/templatesCollide() (PHP), pro
// OKAMŽITÉ varování při psaní do formuláře (backend kontrola se přepočítá až po
// uložení). Musí zůstat 1:1 v souladu s PHP verzí — testováno v obou (PHPUnit +
// Vitest) na stejných příkladech.
//
// Kolize = po zahození nečíselných znaků (přesně to, co dělá bankovní matcher při
// párování VS) šablony vyprodukují STEJNOU strukturu číslic → pro shodné datum
// a počítadlo IDENTICKÝ VS.
export function digitSkeleton(template: string): string {
  const parts = template.split(/(\{(?:YYYY|YY|MM)(?:[+-]\d{1,3})?\}|\{C+\}|\{PP\})/)
  const tokens: string[] = []
  for (const part of parts) {
    if (part === '') continue
    // Posun je součástí identity tokenu: {YY} a {YY+30} mají stejnou šířku, ale
    // nikdy stejnou hodnotu, takže o kolizi nejde a nesmí se sloučit.
    const dateMatch = /^\{(YYYY|YY|MM)([+-]\d{1,3})?\}$/.exec(part)
    if (dateMatch) {
      const offset = dateMatch[2] ? Number(dateMatch[2]) : 0
      const base = dateMatch[1] === 'YYYY' ? 'Y4' : dateMatch[1] === 'YY' ? 'Y2' : 'M2'
      tokens.push(offset !== 0 ? `${base}${offset > 0 ? '+' : ''}${offset}` : base)
      continue
    }
    if (part === '{PP}') continue // vždy písmena — normalizace na číslice je odstraní
    const counterMatch = /^\{(C+)\}$/.exec(part)
    if (counterMatch) { tokens.push('C' + counterMatch[1].length); continue }
    const digits = part.replace(/\D+/g, '')
    if (digits !== '') tokens.push('L' + digits)
  }
  return tokens.join('|')
}

export function templatesCollide(a: string | null | undefined, b: string | null | undefined): boolean {
  const ta = (a ?? '').trim()
  const tb = (b ?? '').trim()
  if (ta === '' || tb === '') return false
  if (ta === tb) return true
  return digitSkeleton(ta) === digitSkeleton(tb)
}

/**
 * Řada, ze které doklad bere číslo, zrcadlo PHP VarsymbolGenerator::seriesTypeFor().
 * Zjednodušený daňový doklad (§ 30 ZDPH) je faktura s příznakem; může mít vlastní
 * řadu firmy. Bez ní ho backend čísluje řadou faktur, náhled to zohlední sám.
 */
export function varsymbolSeriesType(invoiceType: string, isSimplified: boolean | null | undefined): string {
  return invoiceType === 'invoice' && isSimplified === true ? 'simplified' : invoiceType
}
