import { requestSupplierId } from '@/workspace/supplierContext'
import { api } from './client'

/**
 * Archiv OSS podání, rekonciliace a evidence § 110f ZDPH.
 *
 * Vlastní modul, ne rozšíření `reports.ts`: archiv/rekonciliace/evidence jsou tři
 * samostatné obrazovky nad jedním obdobím a v `reports.ts` by se ztratily mezi DPH,
 * KH a SH. Vlastní `OssPreview` zůstává v `reports.ts` — ten se nestěhuje.
 */

export type OssSubmissionStatus =
  | 'draft' | 'generated' | 'downloaded' | 'submitted' | 'accepted' | 'rejected'

export interface OssArchivedSubmission {
  id: number
  form_code: string
  period_year: number
  period_quarter: number | null
  period_month: number | null
  form_variant: string
  status: OssSubmissionStatus
  validation_status: 'passed' | 'failed' | 'skipped'
  validation_errors: string[]
  xml_size_bytes: number
  xml_sha256: string
  generated_at: string
  submitted_at: string | null
  submission_ref: string | null
  notes: string | null
  summary?: Record<string, unknown> | null
}

export interface OssArchive {
  form_code: string
  submissions: OssArchivedSubmission[]
}

export interface OssReconciliationBasis {
  submission_id: number
  status: OssSubmissionStatus
  /** false = jen vygenerovaný/stažený snapshot; stažení podáním NENÍ. */
  is_proven_filing: boolean
  form_variant: string
  validation_status: string
  generated_at: string
  submitted_at: string | null
  submission_ref: string | null
  xml_sha256: string
  fingerprint?: string
  totals: {
    base: number | null
    vat: number | null
    corrections: number | null
    payable: number | null
  }
}

export interface OssTotalDifference {
  key: 'base' | 'vat' | 'corrections' | 'payable'
  filed: number
  current: number
  delta: number
}

export interface OssKeyedDifference {
  change: 'added' | 'removed' | 'changed'
  key: string
  filed: Record<string, unknown> | null
  current: Record<string, unknown> | null
  amounts?: Record<string, { filed: number; current: number; delta: number }>
}

export interface OssDocumentDifference {
  change: 'added' | 'removed' | 'changed'
  invoice_id: number
  item_id: number
  doc_number: string | null
  country: string
  tax_date: string | null
  rate: number
  base: number
  vat: number
  adjusted_period: string | null
  status: string | null
  updated_at: string | null
  filed?: Record<string, unknown>
}

export interface OssReconciliation {
  period: { year: number; quarter: number; start: string; end: string; label: string }
  has_filing: boolean
  /** false = archiv je starší než snapshot podání → porovnat NELZE (ne „souhlasí"). */
  snapshot_available: boolean
  basis: OssReconciliationBasis | null
  current?: {
    return_currency: string
    totals: { base: number; vat: number; corrections: number; payable: number }
    fingerprint: string
  }
  /** null = nebylo s čím porovnávat. */
  in_sync: boolean | null
  differences: {
    totals: OssTotalDifference[]
    rows: OssKeyedDifference[]
    corrections: OssKeyedDifference[]
    documents: OssDocumentDifference[]
  }
}

export interface OssEvidencePayment {
  paid_on: string
  amount: number
  currency: string
}

export interface OssEvidenceRecord {
  id: number
  seq: number
  consumption_country: string
  supply_type: 'goods' | 'services' | null
  supply_description: string
  supply_quantity: string | null
  supply_unit: string | null
  supply_date: string
  taxable_amount: string
  taxable_currency: string
  taxable_amount_return: string
  return_currency: string
  exchange_rate: string | null
  exchange_rate_date: string | null
  adjusted_period: string | null
  vat_rate: string
  vat_rate_type: string | null
  vat_amount: string
  vat_amount_return: string
  payments: OssEvidencePayment[]
  invoice_id: number | null
  invoice_item_id: number | null
  invoice_snapshot: Record<string, unknown>
  customer_name: string | null
  place_evidence: Record<string, unknown>
  /** Body čl. 63c, které se u záznamu naplnit nepodařilo (kód → důvod). */
  completeness: Record<string, string>
  retain_until: string
  captured_at: string
}

export interface OssEvidence {
  legal_basis: string
  retention_years: number
  unsupported: Record<string, string>
  available: boolean
  submission_id?: number
  records: OssEvidenceRecord[]
}

function supplierParam(params: URLSearchParams): URLSearchParams {
  const sid = requestSupplierId()
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
  return params
}

export const ossFilingApi = {
  archive: () =>
    api.get<OssArchive>('/reports/oss/submissions').then(r => r.data),

  reconciliation: (year: number, quarter: number) =>
    api.get<OssReconciliation>('/reports/oss/reconciliation', { params: { year, quarter } }).then(r => r.data),

  evidence: (year: number, quarter: number) =>
    api.get<OssEvidence>('/reports/oss/evidence', { params: { year, quarter } }).then(r => r.data),

  /** Stažení jde přes URL (nikoli axios), aby prohlížeč nabídl soubor. */
  evidenceExportUrl: (year: number, quarter: number, format: 'csv' | 'json') => {
    const params = supplierParam(new URLSearchParams({
      year: String(year), quarter: String(quarter), format,
    }))
    return `/api/reports/oss/evidence/export?${params.toString()}`
  },

  archivedXmlUrl: (submissionId: number) => {
    const params = supplierParam(new URLSearchParams())
    const query = params.toString()
    return `/api/reports/submissions/${submissionId}/xml${query ? '?' + query : ''}`
  },
}
