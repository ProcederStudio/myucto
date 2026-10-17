import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  getSupplier: vi.fn(),
  getClientBranding: vi.fn(),
  updateSupplier: vi.fn(),
  updateClientBranding: vi.fn(),
  emailPreviewHtml: vi.fn().mockResolvedValue('<p>preview</p>'),
  success: vi.fn(),
  error: vi.fn(),
}))
vi.mock('@/api/settings', () => ({ settingsApi: m }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Branding from '../Branding.vue'

function supplier() {
  return {
    id: 3, company_name: 'Syntetická firma', display_name: null, email: null, phone: null, web: null, tagline: null,
    email_branding_enabled: false, email_accent_color: '#3B2D83', pdf_logo_show_name: false,
    invoice_pdf_monochrome: false, branding_profiles_enabled: false, logo_path: null, has_email_logo: false,
  }
}

async function mountBranding(clientScoped: boolean) {
  const wrapper = mount(Branding, {
    props: { clientScoped },
    global: { stubs: { BrandingProfilesSettings: true } },
  })
  await flushPromises()
  await new Promise(resolve => setTimeout(resolve, 0))
  return wrapper
}

describe('úsporná černobílá faktura v Brandingu', () => {
  it('jde zapnout i bez brandingu a ukládá se do nastavení firmy', async () => {
    m.getSupplier.mockResolvedValue(supplier())
    m.updateSupplier.mockImplementation(async (payload: object) => ({ ...supplier(), ...payload }))
    const wrapper = await mountBranding(false)

    const toggle = wrapper.get('[data-testid="branding-invoice-pdf-monochrome"]')
    expect((toggle.element as HTMLInputElement).checked).toBe(false)
    expect(toggle.attributes('disabled')).toBeUndefined()
    await toggle.setValue(true)
    await flushPromises()

    expect(m.updateSupplier).toHaveBeenCalledWith(expect.objectContaining({ invoice_pdf_monochrome: true }))
  })

  it('klientská role ji ukládá přes brandingový výřez', async () => {
    m.getClientBranding.mockResolvedValue(supplier())
    m.updateClientBranding.mockImplementation(async (payload: object) => ({ ...supplier(), ...payload }))
    const wrapper = await mountBranding(true)

    await wrapper.get('[data-testid="branding-invoice-pdf-monochrome"]').setValue(true)
    await flushPromises()

    expect(m.updateClientBranding).toHaveBeenCalledWith(expect.objectContaining({ invoice_pdf_monochrome: true }))
  })
})
