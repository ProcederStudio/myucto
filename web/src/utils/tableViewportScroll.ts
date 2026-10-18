export const TABLE_VIEWPORT_SCROLLBAR_HEIGHT = 16

export interface TableViewportScrollBar {
  id: string
  sourceId: string
  portalTarget?: HTMLElement
  left: number
  top: number
  width: number
  viewportWidth: number
  contentWidth: number
  scrollLeft: number
  maxScrollLeft: number
  zIndex: number
}

interface Entry {
  id: string
  source: HTMLElement
  tables: HTMLTableElement[]
  visible: boolean
  visibleTables: HTMLTableElement[]
  ancestors: { node: HTMLElement; clipX: boolean; clipY: boolean }[]
  footers: HTMLElement[]
  portal: HTMLElement | null
  container: HTMLElement | null
  rtl: boolean
  zIndex: number
}

const CONTROL = '[data-table-viewport-scrollbar]'
const MODAL = '[role="dialog"][aria-modal="true"], dialog[open], [class~="fixed"][class~="inset-0"]'
const clips = /^(auto|scroll|hidden|clip)$/
const scrolls = /^(auto|scroll)$/
let controllerSequence = 0

/** One controller per document: workspace iframes keep their own viewport and scroll state. */
export function installTableViewportScroll(options: {
  document?: Document
  onChange: (bars: TableViewportScrollBar[]) => void
}): { destroy: () => void; refresh: () => void; scrollTo: (id: string, position: number) => void } {
  const doc = options.document ?? document
  const view = doc.defaultView!
  let disposed = false
  let frame: number | null = null
  let scanTimer: number | null = null
  let needsScan = false
  const active = new Set<Entry>()
  const tables = new Set<HTMLTableElement>()
  const pendingTables = new Set<HTMLTableElement>()
  let modal: HTMLElement | undefined
  let appFooters: HTMLElement[] = []
  const styles = new Map<Element, CSSStyleDeclaration>()
  const widths = new Map<HTMLElement, { content: number; client: number }>()
  const dirty = new Set<Entry>()
  let sequence = 0
  const controllerId = ++controllerSequence
  const generatedIds = new Map<HTMLElement, string>()
  let entries = new Map<HTMLElement, Entry>()
  let observed = new Set<Element>()
  let previous = ''
  let previousPortals: (HTMLElement | undefined)[] = []
  const resize = typeof view.ResizeObserver === 'function' ? new view.ResizeObserver(onResize) : null
  const mutations = new view.MutationObserver(records => {
    let changed = false
    let moved = false
    for (const record of records) {
      if (record.target instanceof view.Element && record.target.closest(CONTROL)) continue
      if (record.type === 'attributes') {
        if (record.target instanceof view.Element && (record.target.matches(MODAL)
          || [...tables].some(table => record.target instanceof view.Element && record.target.contains(table)))) changed = true
        continue
      }
      for (const node of [...record.addedNodes, ...record.removedNodes]) {
        if (!(node instanceof view.Element) || node.matches(CONTROL) || node.closest(CONTROL)) continue
        collect(node, pendingTables)
        // DOM unrelated to tables needs no source discovery. A structural change can
        // still move an existing table, so settle its geometry once after the burst.
        moved = true
        changed = changed || pendingTables.size > 0
          || node.matches(MODAL + ', footer.sticky') || !!node.querySelector(MODAL + ', footer.sticky')
      }
      if (record.target instanceof view.Element && record.target.closest('table')) changed = true
    }
    if (changed) requestScan()
    else if (moved && active.size) requestWork()
  })
  const attributes = new view.MutationObserver(() => requestScan())

  function collect(root: Element, target: Set<HTMLTableElement>): void {
    if (root.matches('table')) target.add(root as HTMLTableElement)
    for (const table of root.querySelectorAll<HTMLTableElement>('table')) target.add(table)
  }

  function style(el: Element): CSSStyleDeclaration {
    let css = styles.get(el)
    if (!css) { css = view.getComputedStyle(el); styles.set(el, css) }
    return css
  }

  function hidden(el: HTMLElement): boolean {
    for (let node: HTMLElement | null = el; node; node = node.parentElement) {
      if (node.hidden || node.inert || node.getAttribute('aria-hidden') === 'true') return true
      const css = style(node)
      if (css.display === 'none' || css.visibility === 'hidden' || css.visibility === 'collapse') return true
    }
    return false
  }

  function overflows(node: HTMLElement): boolean {
    let width = widths.get(node)
    if (!width) {
      width = { content: node.scrollWidth, client: node.clientWidth }
      widths.set(node, width)
    }
    return width.content > width.client + 1
  }

  function sourceFor(table: HTMLTableElement, targets: Set<Element>): HTMLElement | null {
    let fallback: HTMLElement | null = null
    for (let node: HTMLElement | null = table; node && node !== doc.body && node !== doc.documentElement; node = node.parentElement) {
      const css = style(node)
      if (scrolls.test(css.overflowX)) {
        targets.add(node)
        fallback ??= node
        if (overflows(node)) return node
      }
      if (/^(hidden|clip)$/.test(css.overflowX) && overflows(node)) return null
    }
    // Keep a narrow source registered: content or a responsive resize may overflow later.
    return fallback
  }

  function scan(): void {
    if (disposed) return
    needsScan = false
    if (scanTimer !== null) view.clearTimeout(scanTimer)
    scanTimer = null
    styles.clear()
    widths.clear()
    for (const table of pendingTables) if (table.isConnected && !table.closest(CONTROL)) tables.add(table)
    pendingTables.clear()
    const next = new Map<HTMLElement, Entry>()
    const targets = new Set<Element>()
    attributes.disconnect()
    for (const table of tables) {
      if (!table.isConnected) { tables.delete(table); continue }
      // Table content can grow without resizing its overflow wrapper (scrollWidth
      // alone does not trigger ResizeObserver on the wrapper).
      targets.add(table)
      const source = sourceFor(table, targets)
      attributes.observe(table, { attributes: true, attributeFilter: ['class', 'style', 'hidden', 'width'] })
      if (!source) continue
      let entry = next.get(source)
      if (!entry) {
        entry = entries.get(source) ?? { id: `table-scroll-${++sequence}`, source, tables: [], visible: false, visibleTables: [],
          ancestors: [], footers: [], portal: null, container: null, rtl: false, zIndex: 21 }
        entry.tables = []
        next.set(source, entry)
      }
      entry.tables.push(table)
    }
    const stickyFooters = [...doc.querySelectorAll<HTMLElement>('footer.sticky')]
    appFooters = stickyFooters.filter(footer => !hidden(footer) && /^(sticky|fixed)$/.test(style(footer).position))
    for (const footer of stickyFooters) targets.add(footer)
    const modals = [...doc.querySelectorAll<HTMLElement>(MODAL)]
    modal = modals.filter(candidate => !hidden(candidate) && candidate.getBoundingClientRect().width > 0
      && (candidate.matches('[role="dialog"], dialog') || style(candidate).position === 'fixed' && layer(candidate) >= 40))
      .sort((a, b) => layer(a) - layer(b)).at(-1)
    for (const element of [...modals, ...stickyFooters]) {
      attributes.observe(element, { attributes: true, attributeFilter: ['class', 'style', 'hidden', 'inert', 'aria-hidden', 'aria-modal', 'open'] })
    }
    active.clear()
    for (const entry of next.values()) {
      entry.visibleTables = entry.tables.filter(table => !hidden(table))
      entry.visible = !hidden(entry.source) && (!modal || modal.contains(entry.source))
        && entry.visibleTables.length > 0
      if (entry.visible && overflows(entry.source)) active.add(entry)
      entry.ancestors = []
      for (let node = entry.source.parentElement; node && node !== doc.body && node !== doc.documentElement; node = node.parentElement) {
        const css = style(node)
        entry.ancestors.push({ node, clipX: clips.test(css.overflowX), clipY: clips.test(css.overflowY) })
      }
      const footers = entry.tables.flatMap(table => [...table.querySelectorAll<HTMLElement>('tfoot, .table-summary-row, .table-total-row')]
        .filter(footer => footer.closest('table') === table))
      entry.footers = footers.filter(footer => !hidden(footer))
      for (const footer of footers) attributes.observe(footer, { attributes: true, attributeFilter: ['class', 'style', 'hidden'] })
      entry.portal = entry.source.closest<HTMLElement>('[role="dialog"][aria-modal="true"], dialog[open]')
      entry.container = fixedContainer(entry.portal)
      entry.rtl = style(entry.source).direction === 'rtl'
      entry.zIndex = Math.max(21, layer(entry.source) + 1)
      attributes.observe(entry.source, { attributes: true,
        attributeFilter: ['class', 'style', 'hidden', 'inert', 'aria-hidden', 'colspan', 'width'] })
    }
    for (const target of observed) if (!targets.has(target)) resize?.unobserve(target)
    for (const target of targets) if (!observed.has(target)) resize?.observe(target)
    observed = targets
    entries = next
    for (const [source, id] of generatedIds) if (!next.has(source)) {
      if (source.id === id) source.removeAttribute('id')
      generatedIds.delete(source)
    }
    // Finish all style/size reads before assigning accessibility IDs; avoid
    // interleaving DOM writes with source discovery across multiple tables.
    for (const entry of entries.values()) if (!entry.source.id) {
      let id = `table-scroll-source-${controllerId}-${entry.id}`
      while (doc.getElementById(id)) id += '-source'
      entry.source.id = id
      generatedIds.set(entry.source, id)
    }
    styles.clear()
    widths.clear()
    invalidate()
  }

  function layer(el: HTMLElement): number {
    let level = 0
    for (let node: HTMLElement | null = el; node; node = node.parentElement) {
      const value = Number.parseInt(style(node).zIndex, 10)
      if (Number.isFinite(value)) level = Math.max(level, value)
    }
    return level
  }

  function clientBox(el: HTMLElement, rect = el.getBoundingClientRect()) {
    const scaleX = el.offsetWidth > 0 ? rect.width / el.offsetWidth : 1
    const scaleY = el.offsetHeight > 0 ? rect.height / el.offsetHeight : 1
    const left = rect.left + el.clientLeft * scaleX
    const top = rect.top + el.clientTop * scaleY
    return { left, top, right: left + el.clientWidth * scaleX, bottom: top + el.clientHeight * scaleY }
  }

  function fixedContainer(portal: HTMLElement | null) {
    for (let node = portal; node && node !== doc.documentElement; node = node.parentElement) {
      const css = style(node)
      if (!(css.transform && css.transform !== 'none') && !(css.perspective && css.perspective !== 'none')
        && !(css.filter && css.filter !== 'none') && !(css.backdropFilter && css.backdropFilter !== 'none')
        && !/(paint|layout|strict|content)/.test(css.contain) && !/(transform|perspective|filter)/.test(css.willChange)) continue
      return node
    }
    return null
  }

  function measure(entry: Entry, modal: HTMLElement | undefined): TableViewportScrollBar | null {
    const { source } = entry
    if (!entry.visible || source.scrollWidth <= source.clientWidth + 1) return null
    const sourceRect = source.getBoundingClientRect()
    const visual = view.visualViewport
    let left = visual?.offsetLeft ?? 0
    let right = left + (visual?.width ?? (doc.documentElement.clientWidth || view.innerWidth))
    let top = visual?.offsetTop ?? 0
    let bottom = top + (visual?.height ?? view.innerHeight)
    const sourceBox = clientBox(source, sourceRect)
    left = Math.max(left, sourceBox.left)
    right = Math.min(right, sourceBox.right)
    top = Math.max(top, sourceBox.top)
    for (const { node, clipX, clipY } of entry.ancestors) {
      if (!clipX && !clipY) continue
      const rect = clientBox(node)
      if (clipX) {
        left = Math.max(left, rect.left)
        right = Math.min(right, rect.right)
      }
      if (clipY) {
        top = Math.max(top, rect.top)
        bottom = Math.min(bottom, rect.bottom)
      }
    }
    if (!modal) {
      for (const footer of appFooters) {
        const rect = footer.getBoundingClientRect()
        if (rect.right > left && rect.left < right && rect.top > top) bottom = Math.min(bottom, rect.top)
      }
    }
    // Once its bottom is reachable, use the native scrollbar below the table footer.
    // Overlay scrollbars can also be visible without reserving a measurable gutter.
    if (sourceRect.bottom <= bottom + 1) return null
    bottom = Math.min(bottom, sourceBox.bottom)
    const height = TABLE_VIEWPORT_SCROLLBAR_HEIGHT
    const { portal } = entry
    const node = entry.container
    const containerRect = node?.getBoundingClientRect()
    const container = node && containerRect ? { node, rect: containerRect,
      scaleX: (node.offsetWidth > 0 ? containerRect.width / node.offsetWidth : 1) || 1,
      scaleY: (node.offsetHeight > 0 ? containerRect.height / node.offsetHeight : 1) || 1 } : null
    const physicalHeight = height * (container?.scaleY ?? 1)
    // Totals remain readable as a normal or sticky footer enters the viewport.
    // An existing blank gutter below the footer can still hold the control.
    const footers = entry.footers.map(footer => footer.getBoundingClientRect()).sort((a, b) => b.top - a.top)
    for (const footer of footers) {
      if (footer.right > left && footer.left < right && footer.top < bottom && footer.bottom > bottom - physicalHeight) bottom = footer.top
    }
    if (right - left < 32 || bottom - top < physicalHeight * 2) return null
    if (!entry.visibleTables.some(table => {
      const rect = table.getBoundingClientRect()
      return rect.bottom > top + physicalHeight && rect.top < bottom - physicalHeight && rect.right > left && rect.left < right
    })) return null
    const maxScrollLeft = source.scrollWidth - source.clientWidth
    if (maxScrollLeft <= 1) return null
    const raw = entry.rtl ? maxScrollLeft + source.scrollLeft : source.scrollLeft
    const bar: TableViewportScrollBar = {
      id: entry.id, sourceId: source.id, left, top: bottom - physicalHeight, width: right - left,
      viewportWidth: source.clientWidth, contentWidth: source.scrollWidth,
      scrollLeft: Math.max(0, Math.min(maxScrollLeft, raw)), maxScrollLeft,
      zIndex: entry.zIndex,
    }
    // Keep modal controls in its accessible subtree. A transformed/filtering ancestor
    // changes the fixed-position containing block, so translate viewport geometry there.
    if (portal) {
      bar.portalTarget = portal
      if (container) {
        const { node, rect, scaleX, scaleY } = container
        bar.left = (bar.left - rect.left) / scaleX - node.clientLeft + node.scrollLeft
        bar.top = (bar.top - rect.top) / scaleY - node.clientTop + node.scrollTop
        bar.width /= scaleX
      }
    }
    return bar
  }

  function update(): void {
    frame = null
    if (disposed) return
    for (const entry of dirty) {
      if (entries.get(entry.source) !== entry) continue
      const bar = measure(entry, modal)
      if (bar) measured.set(entry, bar)
      else measured.delete(entry)
    }
    dirty.clear()
    for (const entry of measured.keys()) if (entries.get(entry.source) !== entry) measured.delete(entry)
    const bars = [...entries.values()].flatMap(entry => measured.has(entry) ? [measured.get(entry)!] : [])
    const serialized = JSON.stringify(bars.map(({ portalTarget: _, ...bar }) => bar))
    if (serialized !== previous || bars.some((bar, index) => bar.portalTarget !== previousPortals[index])) {
      previous = serialized
      previousPortals = bars.map(bar => bar.portalTarget)
      options.onChange(bars)
    }
  }

  function schedule(): void {
    if (!disposed && frame === null) frame = view.requestAnimationFrame(update)
  }

  function invalidate(): void {
    for (const entry of active) dirty.add(entry)
    for (const entry of measured.keys()) dirty.add(entry)
    if (entries.size || measured.size) schedule()
  }

  function requestWork(): void {
    if (!disposed && scanTimer === null) scanTimer = view.setTimeout(() => {
      scanTimer = null
      if (needsScan) scan()
      else invalidate()
    }, 120)
  }

  function requestScan(): void {
    needsScan = true
    requestWork()
  }

  function onResize(): void { requestScan() }

  function onScroll(event: Event): void {
    const target = event.target
    const viewport = target === doc || target === view || target === view.visualViewport
    for (const entry of active) {
      if (viewport || target instanceof view.Element && (target === entry.source || target.contains(entry.source))) dirty.add(entry)
    }
    if (dirty.size) schedule()
  }

  function onTransition(event: Event): void {
    if (event.target instanceof view.Element && !event.target.closest(CONTROL)
      && [...tables].some(table => event.target instanceof view.Element && event.target.contains(table))) {
      scan()
    }
  }

  const measured = new Map<Entry, TableViewportScrollBar>()
  doc.addEventListener('scroll', onScroll, { capture: true, passive: true })
  doc.addEventListener('transitionend', onTransition, true)
  doc.addEventListener('animationend', onTransition, true)
  view.addEventListener('resize', onResize, { passive: true })
  view.visualViewport?.addEventListener('resize', onResize, { passive: true })
  view.visualViewport?.addEventListener('scroll', onScroll, { passive: true })
  mutations.observe(doc.body, { subtree: true, childList: true, attributes: true,
    attributeFilter: ['hidden', 'inert', 'aria-hidden', 'aria-modal', 'open', 'role'] })
  collect(doc.body, pendingTables)
  scan()

  return {
    refresh: scan,
    scrollTo(id, position) {
      const entry = [...entries.values()].find(candidate => candidate.id === id)
      if (!entry || !Number.isFinite(position)) return
      const max = entry.source.scrollWidth - entry.source.clientWidth
      const value = Math.max(0, Math.min(max, position))
      entry.source.scrollLeft = entry.rtl ? value - max : value
      dirty.add(entry)
      schedule()
    },
    destroy() {
      if (disposed) return
      disposed = true
      if (frame !== null) view.cancelAnimationFrame(frame)
      if (scanTimer !== null) view.clearTimeout(scanTimer)
      resize?.disconnect()
      attributes.disconnect()
      mutations.disconnect()
      doc.removeEventListener('scroll', onScroll, true)
      doc.removeEventListener('transitionend', onTransition, true)
      doc.removeEventListener('animationend', onTransition, true)
      view.removeEventListener('resize', onResize)
      view.visualViewport?.removeEventListener('resize', onResize)
      view.visualViewport?.removeEventListener('scroll', onScroll)
      entries.clear()
      active.clear()
      tables.clear()
      pendingTables.clear()
      dirty.clear()
      measured.clear()
      observed.clear()
      for (const [source, id] of generatedIds) if (source.id === id) source.removeAttribute('id')
      generatedIds.clear()
      options.onChange([])
    },
  }
}
