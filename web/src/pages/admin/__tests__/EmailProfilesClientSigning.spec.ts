import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
const m = vi.hoisted(() => ({ listEmailProfiles: vi.fn().mockResolvedValue([]), listSigningProfiles: vi.fn(), createEmailProfile: vi.fn().mockResolvedValue({}), success: vi.fn(), error: vi.fn() }))
vi.mock('@/api/settings', () => ({ settingsApi: m }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isManagedInstallation: false }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import EmailProfiles from '../EmailProfiles.vue'

describe('client e-mail signing selection', () => {
  it('loads client signing profiles, offers only active company S/MIME profiles, and saves the selection', async () => {
    const company = { id: 12, owner_user_id: null, is_active: true, name: 'Company S/MIME', code: 'synthetic', allowed_usages: ['email_smime'] }
    m.listSigningProfiles.mockResolvedValue([company, { ...company, id: 13, owner_user_id: 7, name: 'Personal' }, { ...company, id: 14, allowed_usages: ['pdf'], name: 'PDF only' }])
    const wrapper = mount(EmailProfiles, { props: { clientScoped: true } })
    await flushPromises()
    expect(m.listSigningProfiles).toHaveBeenCalledWith(true)
    const create = wrapper.findAll('button').find(b => b.text().includes('settings.email_profiles_new'))!
    await create.trigger('click')
    const signing = wrapper.findAll('label').find(l => l.text().includes('settings.email_profile_signing_profile'))!.find('select')
    expect(signing.text()).toContain('Company S/MIME')
    expect(signing.text()).not.toContain('Personal')
    expect(signing.text()).not.toContain('PDF only')
    expect(wrapper.findAll('option').some(option => option.attributes('value') === 'sendmail')).toBe(false)
    await signing.setValue('12')
    const name = wrapper.findAll('label').find(l => l.text().startsWith('settings.email_profile_name'))!.find('input')
    await name.setValue('Synthetic sender')
    const code = wrapper.findAll('label').find(l => l.text().startsWith('settings.email_profile_code'))!.find('input')
    await code.setValue('synthetic')
    const from = wrapper.findAll('label').find(l => l.text().startsWith('settings.email_profile_from_email'))!.find('input')
    await from.setValue('sender@example.invalid')
    await wrapper.findAll('button').find(b => b.text().includes('common.save'))!.trigger('click')
    await flushPromises()
    expect(m.createEmailProfile).toHaveBeenCalledWith(expect.objectContaining({ signing_profile_id: 12 }), true)
    expect(m.createEmailProfile.mock.calls[0]![0]).not.toHaveProperty('sendmail_command')
  })
})
