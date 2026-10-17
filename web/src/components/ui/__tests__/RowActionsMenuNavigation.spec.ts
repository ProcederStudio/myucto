import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import RowActionsMenu from '../RowActionsMenu.vue'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

describe('RowActionsMenu — vlastní navigace odkazu', () => {
  it('capture převezme skutečný RouterLink a ponechá modifikované kliknutí prohlížeči', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: { template: '<div />' } }, { path: '/journal', component: { template: '<div />' } }],
    })
    await router.push('/')
    const openBeside = vi.fn()
    const onNavigate = (event: MouseEvent) => {
      if (event.ctrlKey) return
      event.preventDefault()
      openBeside()
    }
    const wrapper = mount(RowActionsMenu, {
      props: { actions: [{ key: 'journal', label: 'Deník', to: '/journal', menuOnly: true, onNavigate }], inlineCount: 1 },
      global: { plugins: [router], stubs: { WorkspaceTeleport: { template: '<slot />' } } },
    })
    await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    expect(wrapper.get('a').attributes('href')).toBe('/journal')
    await wrapper.get('a').trigger('click', { button: 0 })
    await flushPromises()
    expect(openBeside).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.path).toBe('/')

    await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    const event = new MouseEvent('click', { ctrlKey: true, button: 0, bubbles: true, cancelable: true })
    wrapper.get('a').element.dispatchEvent(event)
    await flushPromises()
    expect(event.defaultPrevented).toBe(false)
    expect(openBeside).toHaveBeenCalledOnce()
    expect(router.currentRoute.value.path).toBe('/')
    wrapper.unmount()
  })
})
