import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
const m = vi.hoisted(() => ({ route: undefined as any, replace: vi.fn() }))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({ replace: m.replace }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/pages/admin/EmailProfiles.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/admin/Branding.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/settings/ClientPaymentQrSettings.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/settings/CompanyEmailTemplates.vue', () => ({ default: { props: { clientScoped: Boolean }, template: '<div data-test="company-templates">{{ clientScoped }}</div>' } }))
import ClientCompanySettings from './ClientCompanySettings.vue'

describe('client company email template tab', () => {
  it('supports a direct link on the existing custom-domain settings route', () => {
    m.route = reactive({ query: { tab: 'email-templates' } })
    const wrapper = mount(ClientCompanySettings)
    expect(wrapper.get('[data-test="company-templates"]').text()).toBe('true')
    wrapper.unmount()
  })
  it('retains other query parameters when selecting the template tab', async () => {
    m.route = reactive({ query: { tab: 'branding', supplier_id: '12' } })
    const wrapper = mount(ClientCompanySettings)
    await wrapper.findAll('nav button').find(b => b.text() === 'users.email_templates_title')!.trigger('click')
    expect(m.replace).toHaveBeenCalledWith({ query: { tab: 'email-templates', supplier_id: '12' } })
    expect(wrapper.get('[data-test="company-templates"]').text()).toBe('true')
    wrapper.unmount()
  })
})
