import { api } from './client'
import type { SupplierUrgencyInfo } from './suppliers'

/** Nejbližší termín DPH/KH/SH napříč typy (CrmAggregationService::nextTaxDeadline). */
export interface PortfolioDeadline {
  label: string
  date: string
  days: number
  shv_pending: boolean
}

export interface PortfolioPeriodStatus {
  fiscal_year: number
  status: 'open' | 'closing' | 'closed'
}

/** Rozpad „k doúčtování" po typech (UnbookedDocumentsCounter) — každý typ má vlastní cíl prokliku. */
export interface PortfolioUnbookedPart {
  key: 'invoices' | 'purchase_invoices' | 'bank'
  count: number
  link: string
}

/** Objem dat firmy (PortfolioVolumeCounter) — počty dokladů a zápisů. */
export interface PortfolioVolume {
  issued_invoices: number
  purchase_invoices: number
  bank_statements: number
  bank_transactions: number
  cash_documents: number
  journal_entries: number
}

export interface PortfolioCompany {
  supplier_id: number
  company_name: string
  ic: string | null
  is_vat_payer: boolean
  accounting_mode: 'tax_evidence' | 'double_entry'
  next_deadline: PortfolioDeadline | null
  unbooked_documents: number
  unbooked_breakdown: PortfolioUnbookedPart[]
  unmatched_bank_transactions: number
  purchase_drafts: number
  /** Doklady v příchozích dokladech čekající na vytěžení. */
  inbox_pending: number
  period_status: PortfolioPeriodStatus | null
  last_bank_import_at: string | null
  volume: PortfolioVolume
  /** Táž urgence jako ve správě firem (BE SupplierDirectory). */
  urgency: SupplierUrgencyInfo | null
  last_invoice_date: string | null
  last_activity_at: string | null
}

export interface PortfolioOverview {
  companies: PortfolioCompany[]
  total: number
  generated_at: string
}

/** Jedna neúspěšná kontrola z měsíční kontroly firmy. */
export interface PortfolioCheckFinding {
  key: string
  severity: 'error' | 'warning'
  count: number
}

/**
 * Souhrn měsíční kontroly firmy (kurátorovaná podmnožina kontrol, viz
 * PortfolioCheckService::KEYS). Nálezy samotné se sem NEPOSÍLAJÍ — na ně
 * vede proklik do měsíční kontroly té firmy.
 */
export interface PortfolioCheckSummary {
  supplier_id: number
  period: { id: number; fiscal_year: number }
  range_from: string
  range_to: string
  errors: number
  warnings: number
  findings: PortfolioCheckFinding[]
}

export const portfolioApi = {
  overview: () => api.get<PortfolioOverview>('/portfolio/overview').then(r => r.data),
  /** null = firma nevede podvojné účetnictví nebo nemá založené účetní období. */
  monthlyCheck: (supplierId: number) =>
    api.get<{ summary: PortfolioCheckSummary | null }>(`/portfolio/monthly-check/${supplierId}`)
      .then(r => r.data.summary),
}
