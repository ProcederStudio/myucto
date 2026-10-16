import { describe, expect, it } from 'vitest'
import { invoiceIssuedBy } from '@/pages/invoices/invoiceIssuedBy'

describe('vystavitel dokladu v detailu faktury', () => {
  it('koncept vystavitele nemá, ani když má autora', () => {
    expect(invoiceIssuedBy({ status: 'draft', issued_by_name: null, created_by_name: 'Petr Autor' })).toBeNull()
  })

  it('vystavený doklad ukáže zapsaného vystavitele před autorem', () => {
    expect(invoiceIssuedBy({ status: 'issued', issued_by_name: 'Jana Vystavující', created_by_name: 'Petr Autor' }))
      .toBe('Jana Vystavující')
  })

  it('starší doklad bez zápisu ukáže autora jako PDF', () => {
    expect(invoiceIssuedBy({ status: 'paid', issued_by_name: null, created_by_name: 'Petr Autor' })).toBe('Petr Autor')
  })

  it('bez jména nic', () => {
    expect(invoiceIssuedBy({ status: 'sent', issued_by_name: '  ', created_by_name: undefined })).toBeNull()
  })
})
