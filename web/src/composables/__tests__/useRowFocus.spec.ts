import { afterEach, describe, expect, it } from 'vitest'
import { focusRowField } from '../useRowFocus'

// Desktop tabulka i mobilní karty se renderují zároveň; skrytá varianta má
// offsetParent null. Helper musí trefit pole řádku v té viditelné.

function field(root: HTMLElement, marker: string, visible: boolean): HTMLInputElement {
  const el = document.createElement('input')
  el.setAttribute('data-row-qty', marker)
  Object.defineProperty(el, 'offsetParent', { get: () => (visible ? root : null) })
  root.appendChild(el)
  return el
}

afterEach(() => { document.body.replaceChildren() })

describe('focusRowField', () => {
  it('zaměří pole daného řádku ve viditelném rozložení', async () => {
    const root = document.createElement('div')
    document.body.appendChild(root)
    // Desktop (viditelný): řádky 0, 1, 2; mobil (skrytý): řádky 0, 1, 2.
    const desktop = [0, 1, 2].map(() => field(root, 'inv-item', true))
    ;[0, 1, 2].map(() => field(root, 'inv-item', false))

    expect(await focusRowField('[data-row-qty="inv-item"]', 1, root)).toBe(true)
    expect(document.activeElement).toBe(desktop[1])
  })

  it('vrátí false, když řádek s tím indexem neexistuje', async () => {
    const root = document.createElement('div')
    document.body.appendChild(root)
    field(root, 'inv-item', true)
    expect(await focusRowField('[data-row-qty="inv-item"]', 3, root)).toBe(false)
  })

  it('jiný marker (výkaz práce) se nepočítá', async () => {
    const root = document.createElement('div')
    document.body.appendChild(root)
    field(root, 'inv-wr', true)
    const item = field(root, 'inv-item', true)
    expect(await focusRowField('[data-row-qty="inv-item"]', 0, root)).toBe(true)
    expect(document.activeElement).toBe(item)
  })
})
