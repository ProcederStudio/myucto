import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'

export function reportYearFromQuery(value: unknown): number | null {
  return typeof value === 'string' && /^(20|21)\d{2}$/.test(value) ? Number(value) : null
}

function monthFromQuery(value: unknown): number | null {
  return typeof value === 'string' && /^(0?[1-9]|1[0-2])$/.test(value) ? Number(value) : null
}

function periodFromQuery(value: unknown): 'monthly' | 'quarterly' | '' {
  return value === 'monthly' || value === 'quarterly' ? value : ''
}

/** Explicitní periodicita v odkazu má přednost před výchozím nastavením firmy. */
export function useReportPeriodQuery() {
  const route = useRoute()
  const now = new Date()
  const year = ref(reportYearFromQuery(route.query.year) ?? now.getFullYear())
  const month = ref(monthFromQuery(route.query.month) ?? now.getMonth() + 1)
  const periodOverride = ref(periodFromQuery(route.query.period))

  watch(() => [route.query.year, route.query.month, route.query.period], ([requestedYear, requestedMonth, requestedPeriod]) => {
    if (requestedYear === undefined && requestedMonth === undefined && requestedPeriod === undefined) {
      const today = new Date()
      year.value = today.getFullYear()
      month.value = today.getMonth() + 1
      periodOverride.value = ''
      return
    }
    const y = reportYearFromQuery(requestedYear)
    const m = monthFromQuery(requestedMonth)
    if (y !== null) year.value = y
    if (m !== null) month.value = m
    periodOverride.value = periodFromQuery(requestedPeriod)
  })

  return { year, month, periodOverride }
}
