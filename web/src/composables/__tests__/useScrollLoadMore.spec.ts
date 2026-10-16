import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import { useScrollLoadMore } from '../useScrollLoadMore'

afterEach(() => vi.restoreAllMocks())

it('does not load more desktop rows when collapsing filters scrolls past a hidden mobile sentinel', async () => {
  let y = 0
  vi.spyOn(window, 'scrollY', 'get').mockImplementation(() => y)
  const sentinel = document.createElement('div')
  vi.spyOn(sentinel, 'getClientRects').mockReturnValue([] as unknown as DOMRectList)
  vi.spyOn(sentinel, 'getBoundingClientRect').mockReturnValue({ top: 0 } as DOMRect)
  const load = vi.fn().mockResolvedValue(undefined)
  const wrapper = mount({ setup() { useScrollLoadMore(ref(sentinel), () => true, load); return () => null } })
  for (y = 10; y <= 200; y += 10) {
    window.dispatchEvent(new Event('scroll'))
    await Promise.resolve()
  }
  expect(load).not.toHaveBeenCalled()
  wrapper.unmount()
})

it('still loads the next mobile page when a visible sentinel approaches the viewport', async () => {
  let y = 0
  vi.spyOn(window, 'scrollY', 'get').mockImplementation(() => y)
  const sentinel = document.createElement('div')
  vi.spyOn(sentinel, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
  vi.spyOn(sentinel, 'getBoundingClientRect').mockReturnValue({ top: window.innerHeight + 100 } as DOMRect)
  const load = vi.fn().mockResolvedValue(undefined)
  const wrapper = mount({ setup() { useScrollLoadMore(ref(sentinel), () => true, load); return () => null } })
  y = 20
  window.dispatchEvent(new Event('scroll'))
  expect(load).toHaveBeenCalledTimes(1)
  wrapper.unmount()
})

it('ignores the one-pixel sentinel clipped for desktop while keeping filter collapse enabled', async () => {
  let y = 0
  vi.spyOn(window, 'scrollY', 'get').mockImplementation(() => y)
  const sentinel = document.createElement('div')
  sentinel.style.clipPath = 'inset(50%)'
  document.body.append(sentinel)
  vi.spyOn(sentinel, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
  vi.spyOn(sentinel, 'getBoundingClientRect').mockReturnValue({ top: window.innerHeight - 1, width: 1, height: 1 } as DOMRect)
  const load = vi.fn().mockResolvedValue(undefined)
  const wrapper = mount({ setup() { useScrollLoadMore(ref(sentinel), () => true, load); return () => null } })
  y = 180
  window.dispatchEvent(new Event('scroll'))
  expect(load).not.toHaveBeenCalled()
  wrapper.unmount()
  sentinel.remove()
})
