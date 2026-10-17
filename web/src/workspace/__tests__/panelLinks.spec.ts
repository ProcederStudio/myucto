import { describe, expect, it } from 'vitest'
import { internalRouteFromAnchor, internalRouteFromElement, paneForBeside, paneForCtrlClick, rightPanePlan } from '@/workspace/panelLinks'

describe('panel links', () => {
  it('pro Shift klik volí bezprostřední pravý panel a případně rozšíří rozložení', () => {
    const primary = { id: 'primary' as const }
    const secondary = { id: 'secondary-2' as const }
    const third = { id: 'secondary-3' as const }
    expect(rightPanePlan('primary', [primary], 2)).toEqual({ paneId: 'secondary-2', count: 2 })
    expect(rightPanePlan('primary', [primary, secondary, third], 3)).toEqual({ paneId: 'secondary-2', count: 3 })
    expect(rightPanePlan('secondary-2', [primary, secondary], 3)).toEqual({ paneId: 'secondary-3', count: 3 })
    expect(rightPanePlan('primary', [primary], 1)).toBeNull()
    expect(rightPanePlan('secondary-2', [primary, secondary], 2)).toBeNull()
    expect(rightPanePlan('secondary-3', [primary, secondary, third], 3)).toBeNull()
    expect(rightPanePlan('secondary-2', [primary], 3)).toBeNull()
  })

  it('detail z prostředního panelu otevře vpravo i při prázdném panelu vlevo', () => {
    expect(paneForBeside('secondary-2', [
      { id: 'primary', fullPath: null },
      { id: 'secondary-2', fullPath: '/bank/1' },
      { id: 'secondary-3', fullPath: '/previous-detail' },
    ])).toBe('secondary-3')
  })

  it('plní prázdné panely zleva doprava a po zaplnění používá panel +1', () => {
    const panes = [
      { id: 'primary' as const, fullPath: '/invoices' },
      { id: 'secondary-2' as const, fullPath: null },
      { id: 'secondary-3' as const, fullPath: null },
    ]

    expect(paneForCtrlClick('primary', panes)).toBe('secondary-2')
    panes[1].fullPath = '/invoices/1'
    expect(paneForCtrlClick('primary', panes)).toBe('secondary-3')
    panes[2].fullPath = '/invoices/2'
    expect(paneForCtrlClick('primary', panes)).toBe('secondary-2')
    expect(paneForCtrlClick('secondary-2', panes)).toBe('secondary-3')
    expect(paneForCtrlClick('secondary-3', panes)).toBeNull()
  })

  it('převede interní odkaz na cestu routeru', () => {
    const anchor = document.createElement('a')
    anchor.href = 'https://dev.myucto.cz/invoices/42?tab=payments#detail'

    expect(internalRouteFromAnchor(anchor, 'https://dev.myucto.cz')).toBe('/invoices/42?tab=payments#detail')
  })

  it('odmítne externí odkazy, downloady a odkazy do nového okna', () => {
    const anchor = document.createElement('a')
    anchor.href = 'https://example.com/invoices'
    expect(internalRouteFromAnchor(anchor, 'https://dev.myucto.cz')).toBeNull()

    anchor.href = 'https://dev.myucto.cz/export'
    anchor.download = 'export.csv'
    expect(internalRouteFromAnchor(anchor, 'https://dev.myucto.cz')).toBeNull()

    anchor.removeAttribute('download')
    anchor.target = '_blank'
    expect(internalRouteFromAnchor(anchor, 'https://dev.myucto.cz')).toBeNull()
  })

  it('přečte routu z klikatelného řádku, ale ne z jeho ovládacích prvků', () => {
    const row = document.createElement('div')
    row.dataset.workspaceRoute = '/invoices/42'
    const text = document.createElement('span')
    const checkbox = document.createElement('input')
    row.append(text, checkbox)

    expect(internalRouteFromElement(text)).toBe('/invoices/42')
    expect(internalRouteFromElement(checkbox)).toBeNull()
  })
})
