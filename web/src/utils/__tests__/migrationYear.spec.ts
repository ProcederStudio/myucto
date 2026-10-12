import { describe, expect, it } from 'vitest'
import { migrationYearLabel } from '../migrationYear'

describe('migrationYearLabel', () => {
  it('hospodářský rok firmy: label s hranicemi období', () => {
    expect(migrationYearLabel(2024, { period_start: '2024-08-01', period_end: '2025-07-31' })).toBe('2024 (1. 8. 2024 – 31. 7. 2025)')
  })

  it('anglické rozhraní: hranice v anglickém formátu data', () => {
    expect(migrationYearLabel(2024, { period_start: '2024-08-01', period_end: '2025-07-31' }, 'en')).toBe('2024 (8/1/2024 – 7/31/2025)')
  })

  it('kalendářní firma bez hranic: jen rok', () => {
    expect(migrationYearLabel(2025)).toBe('2025')
    expect(migrationYearLabel(2025, {})).toBe('2025')
    expect(migrationYearLabel(2025, { period_start: '2025-01-01' })).toBe('2025')
  })
})
