/** Hranice účetního období firmy, které náhled převodu přidá u firmy s hospodářským rokem. */
export interface MigrationPeriodBounds {
  period_start?: string | null
  period_end?: string | null
}

function day(date: string, locale: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date)
  if (!m) return date
  return new Intl.DateTimeFormat(locale === 'en' ? 'en-US' : 'cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
    .format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])))
}

/**
 * Rok v průvodci převodu. Rok je vždy label účetního období firmy (rok začátku období);
 * u hospodářského roku s hranicemi období („2024 (1. 8. 2024 – 31. 7. 2025)"), u kalendářní
 * firmy jen rok, beze změny proti dřívějšku.
 */
export function migrationYearLabel(year: number | string, bounds?: MigrationPeriodBounds | null, locale = 'cs'): string {
  return bounds?.period_start && bounds.period_end
    ? `${year} (${day(bounds.period_start, locale)} – ${day(bounds.period_end, locale)})`
    : String(year)
}
