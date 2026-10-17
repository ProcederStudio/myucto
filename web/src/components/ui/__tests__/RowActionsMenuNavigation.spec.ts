import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { internalAnchorNavigation } from '@/workspace/frameNavigation'
import RowActionsMenu, { type RowAction } from '../RowActionsMenu.vue'

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

async function setup(inlineCount = 0) {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/source', component: { template: '<div />' } },
    { path: '/target', component: { template: '<div />' } },
  ] })
  await router.push('/source')
  const navigate = vi.fn((event: MouseEvent) => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
    event.preventDefault()
  })
  const actions: RowAction[] = [{ key: 'book', label: 'Book', to: '/target', onNavigate: navigate, workspaceOpen: 'beside' }]
  const wrapper = mount(RowActionsMenu, { attachTo: document.body, props: { actions, inlineCount }, global: {
    plugins: [router], stubs: { WorkspaceTeleport: { template: '<div><slot /></div>' } },
  } })
  async function openLink() {
    if (inlineCount === 0) await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    return wrapper.get('a')
  }
  return { wrapper, router, navigate, openLink }
}

describe('RowActionsMenu custom navigation with a real RouterLink', () => {
  for (const inlineCount of [0, 1]) {
    it(`intercepts before RouterLink navigation (inlineCount=${inlineCount})`, async () => {
      const { wrapper, router, navigate, openLink } = await setup(inlineCount)
      const link = await openLink()
      expect(link.attributes('href')).toBe('/target')
      expect(link.attributes('data-workspace-open')).toBe('beside')
      await link.trigger('click')
      await flushPromises()
      expect(navigate).toHaveBeenCalledOnce()
      expect(router.currentRoute.value.path).toBe('/source')
      wrapper.unmount()
    })
  }
  it('dismisses its menu before the workspace frame intercepts a beside link', async () => {
    const { wrapper, router, navigate, openLink } = await setup()
    const frameNavigate = vi.fn()
    const intercept = (event: MouseEvent) => {
      const navigation = internalAnchorNavigation(router, event, true)
      if (!navigation) return
      event.preventDefault()
      event.stopImmediatePropagation()
      frameNavigate(navigation)
    }
    document.addEventListener('click', intercept, true)
    try {
      const link = await openLink()
      expect(wrapper.find('[role="menu"]').exists()).toBe(true)
      await link.trigger('click')
      await flushPromises()
      expect(frameNavigate).toHaveBeenCalledExactlyOnceWith({ to: '/target', placement: 'beside' })
      expect(navigate).not.toHaveBeenCalled()
      expect(router.currentRoute.value.path).toBe('/source')
      expect(wrapper.find('[role="menu"]').exists()).toBe(false)
      expect(wrapper.find('.fixed.inset-0').exists()).toBe(false)
      expect(wrapper.get('button[aria-haspopup="menu"]').attributes('aria-expanded')).toBe('false')
    } finally {
      document.removeEventListener('click', intercept, true)
      wrapper.unmount()
    }
  })

  it('keeps disabled items and links outside its own menu from dismissing it', async () => {
    const { wrapper, navigate } = await setup()
    await wrapper.setProps({ actions: [{ ...wrapper.props('actions')[0]!, disabled: true }] })
    await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    await wrapper.get('[aria-disabled="true"]').trigger('click')
    expect(wrapper.find('[role="menu"]').exists()).toBe(true)
    expect(navigate).not.toHaveBeenCalled()
    const outside = document.createElement('a')
    outside.href = '/target'
    outside.dataset.menuItem = ''
    outside.addEventListener('click', event => event.preventDefault())
    document.body.append(outside)
    try {
      outside.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      await flushPromises()
      expect(wrapper.find('[role="menu"]').exists()).toBe(true)
    } finally {
      outside.remove()
      wrapper.unmount()
    }
  })

  it('dismisses on a middle-button auxiliary click without invoking navigation', async () => {
    const { wrapper, navigate, openLink } = await setup()
    const link = await openLink()
    await link.trigger('auxclick', { button: 1 })
    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    expect(navigate).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('leaves modifier and middle clicks to browser behavior', async () => {
    const { wrapper, router, navigate, openLink } = await setup()
    for (const options of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { shiftKey: true }, { button: 1 }]) {
      const link = await openLink()
      const event = new MouseEvent('click', { bubbles: true, cancelable: true, ...options })
      link.element.dispatchEvent(event)
      await flushPromises()
      expect(event.defaultPrevented).toBe(false)
      expect(router.currentRoute.value.path).toBe('/source')
    }
    expect(navigate).toHaveBeenCalledTimes(5)
    wrapper.unmount()
  })
})
