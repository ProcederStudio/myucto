import { describe, expect, it, vi } from 'vitest'
const m = vi.hoisted(() => ({ get: vi.fn().mockResolvedValue({ data: {} }), put: vi.fn().mockResolvedValue({}), post: vi.fn().mockResolvedValue({ data: {} }), delete: vi.fn().mockResolvedValue({}) }))
vi.mock('../client', () => ({ api: m }))
import { companyEmailTemplatesApi } from '../companyEmailTemplates'

describe('company email template API', () => {
  it('pins every operation to the captured supplier and uses the client alias', async () => {
    const api = companyEmailTemplatesApi(true, 12)
    const config = { headers: { 'X-Supplier-Id': '12' } }
    const payload = { subject: 'Synthetic', body_html: '<p>Synthetic</p>', body_text: 'Synthetic' }
    await api.list()
    await api.get('invoice_send', 'en')
    await api.save('invoice_send', 'en', payload)
    await api.reset('invoice_send', 'en')
    await api.preview('invoice_send', 'en', payload)
    const base = '/settings/client/email-templates'
    expect(m.get).toHaveBeenCalledWith(base, config)
    expect(m.get).toHaveBeenCalledWith(`${base}/invoice_send/en`, config)
    expect(m.put).toHaveBeenCalledWith(`${base}/invoice_send/en`, payload, config)
    expect(m.delete).toHaveBeenCalledWith(`${base}/invoice_send/en`, config)
    expect(m.post).toHaveBeenCalledWith(`${base}/invoice_send/en/preview`, payload, config)
  })
  it('uses the staff company endpoint without touching the global editor', async () => {
    await companyEmailTemplatesApi(false, 14).get('invoice_send', 'cs')
    expect(m.get).toHaveBeenCalledWith('/settings/email-templates/invoice_send/cs', { headers: { 'X-Supplier-Id': '14' } })
  })
})
