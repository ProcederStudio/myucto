import { describe, expect, it } from 'vitest'
import { vMath } from '../vMath'

function field(binding: unknown): HTMLInputElement {
  const el = document.createElement('input')
  document.body.appendChild(el)
  const { mounted } = vMath as { mounted: (el: HTMLInputElement, binding: unknown) => void }
  mounted(el, { value: binding })
  return el
}

function typeAndBlur(el: HTMLInputElement, value: string): string {
  el.value = value
  el.dispatchEvent(new Event('blur'))
  return el.value
}

describe('v-math: počet míst výsledku výrazu', () => {
  it('bez hodnoty a s 2 zaokrouhlí výsledek výrazu na 2 místa jako dosud', () => {
    expect(typeAndBlur(field(undefined), '1/3')).toBe('0.33')
    expect(typeAndBlur(field(2), '1/3')).toBe('0.33')
  })

  it('6 nechá přesnou sazbu', () => {
    expect(typeAndBlur(field(6), '1/3')).toBe('0.333333')
  })

  // Množství s jednotkou, která má nastavená desetinná místa: kalkulačka drží
  // přesnost sloupce (3 místa) a o zbytku rozhodne upozornění, ne tiché zaokrouhlení.
  it('3 drží tři místa', () => {
    expect(typeAndBlur(field(3), '1/3')).toBe('0.333')
  })

  it('0 počítá na celá čísla a neplatnou hodnotu bere jako 2', () => {
    expect(typeAndBlur(field(0), '10/4')).toBe('3')
    expect(typeAndBlur(field('x'), '1/3')).toBe('0.33')
    expect(typeAndBlur(field(9), '1/3')).toBe('0.33')
  })

  it('samotné číslo nezaokrouhluje nikdy', () => {
    expect(typeAndBlur(field(0), '1.25')).toBe('1.25')
  })
})
