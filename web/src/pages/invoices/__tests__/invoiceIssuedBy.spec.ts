import { describe, expect, it } from 'vitest'
import { invoiceIssuedBy } from '@/pages/invoices/invoiceIssuedBy'

function issuedBy(status: string, issued_by_name: string | null, created_by_name: string | null): string | null {
  const invoice = { status, issued_by_name, created_by_name }
  return invoiceIssuedBy(invoice)
}

describe('vystavitel dokladu v detailu faktury', () => {
  it('koncept vystavitele nemá, ani když má autora', () => {
    expect(issuedBy('draft', null, 'Petr Autor')).toBeNull()
  })

  it('vystavený doklad ukáže zapsaného vystavitele před autorem', () => {
    expect(issuedBy('issued', 'Jana Vystavující', 'Petr Autor')).toBe('Jana Vystavující')
  })

  it('doklad bez zapsaného vystavitele nepodstrčí autora', () => {
    expect(issuedBy('paid', null, 'Petr Autor')).toBeNull()
  })

  it('automaticky vystavený doklad neukáže autora šablony', () => {
    expect(issuedBy('issued', '  ', 'Autor Šablony')).toBeNull()
  })
})
