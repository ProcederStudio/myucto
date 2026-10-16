/**
 * Kdo doklad vystavil (řádek „Vystavil" v detailu faktury). Zrcadlo
 * `InvoicePdfRenderer::issuedByLabel()`: jméno zapsané při vystavení, u starších
 * dokladů bez zápisu autor dokladu. Koncept vystavený ještě není.
 */
export interface InvoiceIssuedBySource {
  status: string
  issued_by_name?: string | null
  created_by_name?: string | null
}

export function invoiceIssuedBy(invoice: InvoiceIssuedBySource): string | null {
  if (invoice.status === 'draft') return null
  const issued = (invoice.issued_by_name ?? '').trim()
  if (issued !== '') return issued
  const author = (invoice.created_by_name ?? '').trim()
  return author !== '' ? author : null
}
