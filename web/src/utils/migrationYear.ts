/** Hranice účetního období firmy, které náhled převodu přidá u firmy s hospodářským rokem. */
export interface MigrationPeriodBounds {
  period_start?: string | null
  period_end?: string | null
}

function day(date: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date)
  return m ? `${Number(m[3])}. ${Number(m[2])}. ${m[1]}` : date
}

/**
 * Rok v průvodci převodu. Rok je vždy label účetního období firmy (rok začátku období);
 * u hospodářského roku s hranicemi období („2024 (1. 8. 2024 – 31. 7. 2025)"), u kalendářní
 * firmy jen rok, beze změny proti dřívějšku.
 */
export function migrationYearLabel(year: number | string, bounds?: MigrationPeriodBounds | null): string {
  return bounds?.period_start && bounds.period_end
    ? `${year} (${day(bounds.period_start)} – ${day(bounds.period_end)})`
    : String(year)
}
