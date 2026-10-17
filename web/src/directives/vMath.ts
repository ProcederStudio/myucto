import type { Directive } from 'vue'
import { selectOnFocus } from './vSelectOnFocus'

/**
 * Safe math expression evaluator (CSP-safe, žádný new Function()).
 *
 * Povolené znaky: digits, `+`, `-`, `*`, `/`, `(`, `)`, `.`, `,` (CZ desetinná čárka).
 * Algoritmus: tokenize → Shunting-yard → RPN evaluation.
 *
 * Examples:
 *   "400-100"   → 300
 *   "400+100"   → 500
 *   "400/2"     → 200
 *   "12*1.21"   → 14.52
 *   "(100+50)*2" → 300
 *   "1234,56"   → 1234.56
 *   "-5+3"      → -2
 *   "abc"       → null
 *   "1/0"       → null (zero div)
 */
export function evalMath(input: string, decimals = 2): number | null {
  if (input === '' || input === null || input === undefined) return null
  const s = String(input).replace(/\s/g, '').replace(',', '.')
  if (s === '') return null

  // Whitelist znaků — defense in depth
  if (!/^[\d+\-*/.()]+$/.test(s)) return null

  // Pokud je to už jen číslo, rychlá cesta
  if (/^-?\d+(\.\d+)?$/.test(s)) return parseFloat(s)

  try {
    const tokens = tokenize(s)
    if (tokens === null) return null
    const rpn = shuntingYard(tokens)
    if (rpn === null) return null
    const r = evalRpn(rpn)
    if (r === null || !isFinite(r)) return null
    // Zaokrouhlení výsledku výrazu na `decimals` míst (částky 2, sazby 6, množství viz mathDecimals)
    const scale = 10 ** decimals
    return Math.round(r * scale) / scale
  } catch {
    return null
  }
}

type Token =
  | { type: 'num'; value: number }
  | { type: 'op'; value: '+' | '-' | '*' | '/' }
  | { type: 'paren'; value: '(' | ')' }

function tokenize(s: string): Token[] | null {
  const tokens: Token[] = []
  let i = 0
  while (i < s.length) {
    const c = s[i]
    if (c === '(' || c === ')') {
      tokens.push({ type: 'paren', value: c })
      i++
    } else if (c === '+' || c === '*' || c === '/') {
      tokens.push({ type: 'op', value: c })
      i++
    } else if (c === '-') {
      // Unární minus: na začátku nebo po operátoru / levé závorce
      const prev = tokens[tokens.length - 1]
      if (!prev || prev.type === 'op' || (prev.type === 'paren' && prev.value === '(')) {
        let j = i + 1
        while (j < s.length && /[\d.]/.test(s[j])) j++
        if (j === i + 1) return null
        const num = parseFloat(s.slice(i, j))
        if (!isFinite(num)) return null
        tokens.push({ type: 'num', value: num })
        i = j
      } else {
        tokens.push({ type: 'op', value: '-' })
        i++
      }
    } else if (/[\d.]/.test(c)) {
      let j = i
      while (j < s.length && /[\d.]/.test(s[j])) j++
      const num = parseFloat(s.slice(i, j))
      if (!isFinite(num)) return null
      tokens.push({ type: 'num', value: num })
      i = j
    } else {
      return null
    }
  }
  return tokens
}

function shuntingYard(tokens: Token[]): Token[] | null {
  const out: Token[] = []
  const ops: Token[] = []
  const precedence: Record<string, number> = { '+': 1, '-': 1, '*': 2, '/': 2 }
  for (const t of tokens) {
    if (t.type === 'num') {
      out.push(t)
    } else if (t.type === 'op') {
      while (ops.length > 0) {
        const top = ops[ops.length - 1]
        if (top.type === 'op' && precedence[top.value] >= precedence[t.value]) {
          out.push(ops.pop()!)
        } else break
      }
      ops.push(t)
    } else if (t.value === '(') {
      ops.push(t)
    } else {
      // ')'
      let found = false
      while (ops.length > 0) {
        const top = ops.pop()!
        if (top.type === 'paren' && top.value === '(') { found = true; break }
        out.push(top)
      }
      if (!found) return null // unbalanced parens
    }
  }
  while (ops.length > 0) {
    const top = ops.pop()!
    if (top.type === 'paren') return null // unbalanced
    out.push(top)
  }
  return out
}

function evalRpn(rpn: Token[]): number | null {
  const stack: number[] = []
  for (const t of rpn) {
    if (t.type === 'num') {
      stack.push(t.value)
    } else if (t.type === 'op') {
      const b = stack.pop(), a = stack.pop()
      if (a === undefined || b === undefined) return null
      switch (t.value) {
        case '+': stack.push(a + b); break
        case '-': stack.push(a - b); break
        case '*': stack.push(a * b); break
        case '/':
          if (b === 0) return null
          stack.push(a / b)
          break
      }
    }
  }
  return stack.length === 1 ? stack[0] : null
}

/**
 * Počet míst výsledku výrazu z hodnoty direktivy: celé číslo 0 až 6, jinak 2.
 * Pole množství posílá 3, když má jednotka nastavená desetinná místa
 * (viz useUnitDecimals); částky dál 2, sazby 6.
 */
function mathDecimals(value: unknown): number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 6 ? value : 2
}

export const vMath: Directive<HTMLInputElement> = {
  mounted(el, binding) {
    ;(el as any).__mathDecimals = mathDecimals(binding.value)
    const evaluate = () => {
      const r = evalMath(el.value, (el as any).__mathDecimals)
      if (r === null) return
      const formatted = String(r)
      if (el.value === formatted) return
      el.value = formatted
      el.dispatchEvent(new Event('input', { bubbles: true }))
      el.dispatchEvent(new Event('change', { bubbles: true }))
    }

    // 1) Blur — primární trigger (Tab i mouseclick mimo pole)
    el.addEventListener('blur', evaluate)

    // 2) Enter / Tab keydown — power-user shortcut
    el.addEventListener('keydown', (e) => {
      const ev = e as KeyboardEvent
      if (ev.key === 'Enter' || ev.key === 'Tab') evaluate()
    })

    // 3) Debounced input — 800ms po posledním keystroke (jen pokud obsah obsahuje operátor)
    let timeout: ReturnType<typeof setTimeout> | null = null
    el.addEventListener('input', () => {
      if (timeout) clearTimeout(timeout)
      if (!/[+\-*/]/.test(el.value)) return
      timeout = setTimeout(evaluate, 800)
    })

    // 4) Vstup do pole označí celou hodnotu, napsané číslo ji přepíše.
    selectOnFocus(el)

    ;(el as any).__mathHandler = evaluate
  },
  updated(el, binding) {
    ;(el as any).__mathDecimals = mathDecimals(binding.value)
  },
  beforeUnmount(el) {
    const h = (el as any).__mathHandler
    if (h) el.removeEventListener('blur', h)
  },
}
