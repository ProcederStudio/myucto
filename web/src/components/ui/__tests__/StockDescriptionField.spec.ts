import { afterEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import StockDescriptionField from '../StockDescriptionField.vue'

// Nabídka skladových karet v popisu řádku: stav skladu u každé karty (nula = varování)
// a Shift+Enter, který vždy zalomí řádek popisu místo výběru karty.

let wrapper: VueWrapper | null = null
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

const options = [
  { value: 1, label: 'A-1 - Šroub', secondary: 'ks', badge: 'skladem 12 ks', badgeTone: 'neutral' as const },
  { value: 2, label: 'A-2 - Matice', secondary: 'ks', badge: 'skladem 0 ks', badgeTone: 'warning' as const },
  { value: 3, label: 'A-3 - Montáž', secondary: 'h' },
]

async function openMenu() {
  wrapper = mount(StockDescriptionField, {
    attachTo: document.body,
    props: { description: 'A-', stockItemId: null, stockEnabled: true, multiline: true, options },
  })
  await wrapper.find('textarea').trigger('focus')
  await nextTick()
  return wrapper
}

function optionButtons(): HTMLElement[] {
  return Array.from(document.body.querySelectorAll<HTMLElement>('[role="option"]'))
}

describe('StockDescriptionField', () => {
  it('ukáže u karty stav skladu a nulový stav zvýrazní jako varování', async () => {
    await openMenu()
    const [first, second, third] = optionButtons()
    const ok = first.querySelector('[data-test="stock-option-badge"]')!
    const zero = second.querySelector('[data-test="stock-option-badge"]')!
    expect(ok.textContent).toContain('skladem 12 ks')
    expect(ok.className).not.toContain('warning')
    expect(zero.textContent).toContain('skladem 0 ks')
    expect(zero.className).toContain('warning')
    expect(third.querySelector('[data-test="stock-option-badge"]')).toBeNull()
  })

  it('Enter vybere zvýrazněnou kartu, Shift+Enter ji nevybere a nechá zalomení řádku', async () => {
    const w = await openMenu()
    const textarea = w.find('textarea')

    const shiftEnter = new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, cancelable: true, bubbles: true })
    textarea.element.dispatchEvent(shiftEnter)
    await nextTick()
    expect(shiftEnter.defaultPrevented).toBe(false)
    expect(w.emitted('select')).toBeUndefined()

    const enter = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true, bubbles: true })
    textarea.element.dispatchEvent(enter)
    await nextTick()
    expect(enter.defaultPrevented).toBe(true)
    expect(w.emitted('select')).toEqual([[1]])
  })
})
