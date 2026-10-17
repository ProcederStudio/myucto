import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { reportsApi, type DphSettings } from '@/api/reports'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { getWindowSupplierScope } from '@/workspace/supplierContext'
import { appIsoDate } from '@/utils/date'
import { reportYearFromQuery } from './useReportPeriodQuery'
import {
  readVatReportSelection, writeVatReportSelection,
  type VatReportPeriod, type VatReportSelection,
} from '@/utils/vatReportPeriodSession'

type VatReportKind = 'vat' | 'kh' | 'sh' | 'oss' | 'monthly'

export function lastClosedVatPeriod(period: VatReportPeriod, now = new Date()): Pick<VatReportSelection, 'year' | 'month'> {
  const date = appIsoDate(now)
  const year = Number(date.slice(0, 4))
  const zeroBasedMonth = Number(date.slice(5, 7)) - 1
  const month = period === 'quarterly' ? Math.floor(zeroBasedMonth / 3) * 3 : zeroBasedMonth
  return month === 0 ? { year: year - 1, month: 12 } : { year, month }
}

function queryMonth(value: unknown): number | null {
  return typeof value === 'string' && /^(0?[1-9]|1[0-2])$/.test(value) ? Number(value) : null
}

function queryPeriod(value: unknown): VatReportPeriod | '' {
  return value === 'monthly' || value === 'quarterly' ? value : ''
}

/** Společný výběr DPH se čte při navigaci; otevřená podání si zachovávají vlastní období. */
export function useVatReportPeriod(kind: VatReportKind = 'vat') {
  const route = useRoute()
  const router = useRouter()
  const auth = useAuthStore()
  const supplier = useSupplierStore()
  const initial = lastClosedVatPeriod('monthly')
  const year = ref(initial.year)
  const month = ref(initial.month)
  const periodOverride = ref<VatReportPeriod | ''>('')
  const settings = ref<DphSettings | null>(null)
  const ready = ref(false)
  const settingsError = shallowRef<unknown>(null)
  const forcedPeriod = computed<VatReportPeriod | ''>(() => {
    if (kind === 'oss') return 'quarterly'
    if (kind === 'monthly' || (kind === 'kh' && settings.value?.taxpayer_type === 'po')) return 'monthly'
    return ''
  })
  // SH se podle § 102 řídí druhem plnění, ne periodicitou DPH: výchozí je měsíc a vlastní volba se nesdílí.
  const companyPeriod = computed<VatReportPeriod>(() => kind !== 'sh' && settings.value?.vat_period === 'quarterly' ? 'quarterly' : 'monthly')
  const effectivePeriod = computed<VatReportPeriod>(() => forcedPeriod.value || periodOverride.value || companyPeriod.value)
  const group = computed(() => forcedPeriod.value ? (kind === 'kh' ? 'kh-monthly' : kind) : (kind === 'sh' ? 'sh' : 'vat'))
  const ownGroup = computed(() => group.value !== 'vat')
  const identity = computed(() => {
    const userId = Number(auth.user?.id)
    const supplierId = Number(getWindowSupplierScope()?.supplierId ?? supplier.currentSupplierId)
    return Number.isSafeInteger(userId) && userId > 0 && Number.isSafeInteger(supplierId) && supplierId > 0
      ? `${userId}.${supplierId}` : ''
  })
  let applying = false
  let started = false
  let disposed = false
  let generation = 0
  let activeIdentity = ''
  let pendingQuerySync = false

  // Rozpis z výkazu (remember=0) ukáže období odkazu, ale nemění zapamatovanou volbu ostatních výkazů.
  const linkOnly = () => route.query.remember === '0'

  function persist() {
    if (!ready.value || applying || activeIdentity !== identity.value) return
    writeVatReportSelection(activeIdentity, group.value, {
      year: year.value, month: month.value, periodOverride: forcedPeriod.value ? '' : periodOverride.value,
    })
  }

  function applySelection() {
    pendingQuerySync = false
    const own = readVatReportSelection(activeIdentity, group.value)
    const common = ownGroup.value && !own ? readVatReportSelection(activeIdentity, 'vat') : null
    const remembered = own ?? common
    const requestedYear = reportYearFromQuery(route.query.year)
    const requestedMonth = queryMonth(route.query.month)
    const requestedPeriod = queryPeriod(route.query.period)
    const fallback = lastClosedVatPeriod(forcedPeriod.value || requestedPeriod || companyPeriod.value)
    applying = true
    year.value = requestedYear ?? remembered?.year ?? fallback.year
    month.value = requestedMonth ?? remembered?.month ?? fallback.month
    periodOverride.value = requestedPeriod || (forcedPeriod.value ? '' : own?.periodOverride ?? '')
    applying = false
    // Proklik z kalendáře je také výběr uživatele; pouhé otevření nuceného výkazu není.
    if (!linkOnly() && (requestedYear !== null || requestedMonth !== null || requestedPeriod)) persist()
  }

  async function initialize(): Promise<boolean> {
    started = true
    const seq = ++generation
    const scope = identity.value
    ready.value = false
    settings.value = null
    settingsError.value = null
    try {
      const result = await reportsApi.dphSettings()
      if (disposed || seq !== generation || scope !== identity.value) return false
      settings.value = result
      activeIdentity = scope
      applySelection()
      ready.value = true
      if (!linkOnly() && (!forcedPeriod.value || reportYearFromQuery(route.query.year) !== null || queryMonth(route.query.month) !== null || queryPeriod(route.query.period))) persist()
      return true
    } catch (error) {
      if (!disposed && seq === generation && scope === identity.value) settingsError.value = error
      return false
    }
  }

  watch([year, month, periodOverride], () => {
    persist()
    if (ready.value && !applying && activeIdentity === identity.value) pendingQuerySync = true
  }, { flush: 'sync' })
  watch([year, month, periodOverride], () => {
    if (!pendingQuerySync) return
    pendingQuerySync = false
    if (!ready.value || disposed || activeIdentity !== identity.value) return
    if (route.query.year === undefined && route.query.month === undefined && route.query.period === undefined) return
    const selected = { year: String(year.value), month: String(month.value), period: effectivePeriod.value }
    if (route.query.year === selected.year && route.query.month === selected.month && route.query.period === selected.period) return
    // Ruční výběr nahradí původní explicitní odkaz, aby obnovení stránky nevrátilo staré období.
    void router.replace({ query: { ...route.query, ...selected } }).catch(() => {})
  }, { flush: 'post' })
  watch([() => route.query.year, () => route.query.month, () => route.query.period], () => {
    if (ready.value) applySelection()
  })
  watch(identity, () => {
    if (started) void initialize()
  }, { flush: 'sync' })
  onScopeDispose(() => { disposed = true; generation++ })

  return { year, month, periodOverride, effectivePeriod, settings, ready, settingsError, initialize }
}
