import { api } from './client'

export interface CompanyEmailTemplateItem {
  code: string
  locale: string
  source: 'supplier' | 'global' | 'default'
  resolved_locale: string
  has_override: boolean
  updated_at: string | null
}
export interface EmailTemplateContent {
  subject: string
  body_html: string
  body_text: string
}
export interface CompanyEmailTemplate extends CompanyEmailTemplateItem, EmailTemplateContent {
  variables: Record<string, string>
}

export function companyEmailTemplatesApi(clientScoped: boolean, supplierId: number) {
  const base = clientScoped ? '/settings/client/email-templates' : '/settings/email-templates'
  const config = { headers: { 'X-Supplier-Id': String(supplierId) } }
  const path = (code: string, locale: string) => `${base}/${encodeURIComponent(code)}/${encodeURIComponent(locale)}`
  return {
    list: () => api.get<{ data: CompanyEmailTemplateItem[]; supplier_id: number }>(base, config).then(r => r.data),
    get: (code: string, locale: string) => api.get<CompanyEmailTemplate>(path(code, locale), config).then(r => r.data),
    save: (code: string, locale: string, payload: EmailTemplateContent) => api.put(path(code, locale), payload, config),
    reset: (code: string, locale: string) => api.delete(path(code, locale), config),
    preview: (code: string, locale: string, payload: EmailTemplateContent) => api.post<EmailTemplateContent>(`${path(code, locale)}/preview`, payload, config).then(r => r.data),
  }
}
