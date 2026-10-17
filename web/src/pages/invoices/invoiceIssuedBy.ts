/**
 * Kdo doklad vystavil (řádek „Vystavil" v detailu faktury): jen jméno zapsané
 * při vystavení. Na rozdíl od PDF (`InvoicePdfRenderer::issuedByLabel()`) bez
 * náhrady autorem dokladu, protože u automaticky vystavených dokladů (cron,
 * pravidelná fakturace) by ukázal autora šablony. Koncept vystavený ještě není.
 */
export interface InvoiceIssuedBySource {
  status: string
  issued_by_name?: string | null
}

export function invoiceIssuedBy(invoice: InvoiceIssuedBySource): string | null {
  if (invoice.status === 'draft') return null
  const issued = (invoice.issued_by_name ?? '').trim()
  return issued !== '' ? issued : null
}
