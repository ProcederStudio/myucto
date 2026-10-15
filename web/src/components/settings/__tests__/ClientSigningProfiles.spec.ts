import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
const m = vi.hoisted(() => ({
  listSigningProfiles: vi.fn(), getPdfSigningSettings: vi.fn(), createSigningProfile: vi.fn(),
  getSigningProfileCredential: vi.fn(), uploadSigningProfileCredential: vi.fn(), updatePdfSignatureOutputSettings: vi.fn(),
  success: vi.fn(), error: vi.fn(), warning: vi.fn(),
}))
vi.mock('@/api/settings', () => ({ settingsApi: m }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import ClientSigningProfiles from '../ClientSigningProfiles.vue'
const companyProfile = { id: 12, owner_user_id: null, name: 'Synthetic company', code: 'synthetic', allowed_usages: ['pdf', 'email_smime'], is_active: true }
const invoice = { output_type: 'invoice', usage: 'pdf', enabled: false, default_profile_id: null, backend: 'native', selection_source: 'admin_profile_settings', failure_policy: 'fail_closed', signature_config: {} }

describe('client signing profile settings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listSigningProfiles.mockResolvedValue([companyProfile])
    m.getPdfSigningSettings.mockResolvedValue({ output_settings: [{ ...invoice }], output_types: ['invoice'] })
    m.createSigningProfile.mockResolvedValue(companyProfile)
    m.getSigningProfileCredential.mockResolvedValue({ has_certificate: false })
    m.uploadSigningProfileCredential.mockResolvedValue({ has_certificate: true })
    m.updatePdfSignatureOutputSettings.mockResolvedValue({ output_settings: [{ ...invoice, enabled: true, default_profile_id: 12 }] })
  })
  it('creates a company native profile in client scope and enables certificate upload only after saving', async () => {
    const wrapper = mount(ClientSigningProfiles)
    await flushPromises()
    expect(m.listSigningProfiles).toHaveBeenCalledWith(true)
    expect(m.getPdfSigningSettings).toHaveBeenCalledWith(true)
    await wrapper.findAll('button').find(b => b.text() === 'settings.signing_profile_create')!.trigger('click')
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    const inputs = wrapper.find('form').findAll('input')
    await inputs[0]!.setValue('Synthetic company')
    await inputs[1]!.setValue('synthetic')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.createSigningProfile).toHaveBeenCalledWith(expect.objectContaining({ owner_user_id: null, default_backend: 'native', allowed_usages: ['pdf'] }), true)
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('personal_vault')
  })
  it('saves an output using only an eligible company profile and a fixed company selection source', async () => {
    m.listSigningProfiles.mockResolvedValue([companyProfile, { ...companyProfile, id: 13, owner_user_id: 8, name: 'Personal' }, { ...companyProfile, id: 14, is_active: false, name: 'Inactive' }])
    const wrapper = mount(ClientSigningProfiles)
    await flushPromises()
    const select = wrapper.find('select')
    expect(select.text()).toContain('Synthetic company')
    expect(select.text()).not.toContain('Personal')
    expect(select.text()).not.toContain('Inactive')
    await select.setValue('12')
    await wrapper.find('input[type="checkbox"]').setValue(true)
    await wrapper.findAll('button').find(b => b.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(m.updatePdfSignatureOutputSettings).toHaveBeenCalledWith([expect.objectContaining({ output_type: 'invoice', enabled: true, default_profile_id: 12, backend: 'native', selection_source: 'admin_profile_settings' })], true)
  })
  it('shows protected existing selection without exposing its profile metadata', async () => {
    m.getPdfSigningSettings.mockResolvedValue({ output_settings: [{ ...invoice, default_profile_id: 999 }], output_types: ['invoice'] })
    const wrapper = mount(ClientSigningProfiles)
    await flushPromises()
    expect(wrapper.find('select').find('option:disabled').text()).toBe('settings.client_signing_profile_unavailable')
    expect(wrapper.text()).toContain('settings.client_signing_profile_unavailable_hint')
  })
  it('uploads certificates only through the encrypted client credential route', async () => {
    const wrapper = mount(ClientSigningProfiles)
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'common.edit')!.trigger('click')
    await flushPromises()
    const input = wrapper.find('input[type="file"]')
    const file = new File(['synthetic'], 'synthetic.p12')
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await wrapper.findAll('input[type="password"]').at(-1)!.setValue('synthetic-passphrase')
    await wrapper.findAll('form')[1]!.trigger('submit')
    await flushPromises()
    expect(m.uploadSigningProfileCredential).toHaveBeenCalledWith(12, file, 'synthetic-passphrase', 'encrypted_store', null, true)
    expect((wrapper.findAll('input[type="password"]').at(-1)!.element as HTMLInputElement).value).toBe('')
  })

})
