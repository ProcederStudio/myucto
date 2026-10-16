import { computed, nextTick, ref } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import { bindColumnLabels, type ColumnLabelsController } from '@/directives/columnLabels'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const cleanups: (() => void)[] = []
afterEach(() => {
  cleanups.splice(0).forEach(dispose => dispose())
  document.body.replaceChildren()
})

function controller(saved?: boolean) {
  const preference = ref(saved)
  const columnLabelsDefault = ref(false)
  const ctrl: ColumnLabelsController = {
    columns: [{ key: 'actions', labelKey: 'document.open' }],
    columnLabelsDefault,
    showColumnLabels: computed(() => preference.value ?? columnLabelsDefault.value),
  }
  return { ctrl, preference }
}

function table(markup: string) {
  const element = document.createElement('table')
  element.innerHTML = markup
  document.body.append(element)
  return element
}

function bind(element: HTMLTableElement, ctrl: ColumnLabelsController) {
  const dispose = bindColumnLabels(element, ctrl, key => ({
    'common.actions': 'Actions', 'common.preview': 'Preview', 'common.show': 'Show', 'document.open': 'Open',
  })[key] ?? key)
  cleanups.push(dispose)
  return dispose
}

async function settle() {
  await nextTick()
  await Promise.resolve()
  await Promise.resolve()
  await nextTick()
}

describe('table column labels', () => {
  it.each([['cs', cs], ['en', en]] as const)('nevyvolá nouzové načtení překladů chybějícím pomocným popiskem v %s', (_locale, messages) => {
    const common = messages.common as Record<string, unknown>
    const element = table(`<thead><tr><th>${common.show ?? ''}</th></tr></thead><tbody><tr><td>Open</td></tr></tbody>`)
    const { ctrl } = controller(true)
    ctrl.columns = []
    const dispose = bindColumnLabels(element, ctrl, key => {
      const value = common[key.slice('common.'.length)]
      expect(typeof value, key).toBe('string')
      return value as string
    })
    cleanups.push(dispose)
    expect(element.querySelector('td')!.hasAttribute('data-column-label')).toBe(false)
  })

  it('labels only data cells and excludes selectors, actions, details and footer totals', () => {
    const element = table(`<thead><tr>
      <th><input type="checkbox" aria-label="Select all"></th>
      <th>Number <span aria-hidden="true">▲ 2</span><svg><title>Sort</title></svg></th>
      <th>Amount</th><th>Open</th><th>Preview</th><th></th>
      </tr></thead><tbody>
      <tr><td><input type="checkbox"></td><td>TEST-1</td><td>100</td><td>Button</td><td>Eye</td><td>Menu</td></tr>
      <tr class="table-detail-row"><td colspan="6" data-column-label="stale">Nested detail</td></tr>
      <tr><td></td><td colspan="5">Expanded content</td></tr>
      <tr class="table-summary-row"><td></td><td>Total</td><td>100</td><td></td><td></td><td></td></tr>
      </tbody><tfoot><tr><td></td><td data-column-label="stale">Total</td><td>100</td><td></td><td></td><td></td></tr></tfoot>`)
    const { ctrl } = controller(true)
    bind(element, ctrl)
    const cells = Array.from(element.tBodies[0].rows[0].cells)
    expect(cells.map(cell => cell.dataset.columnLabel)).toEqual([undefined, 'Number', 'Amount', undefined, undefined, undefined])
    expect(element.tBodies[0].rows[1].querySelector('[data-column-label]')).toBeNull()
    expect(element.tBodies[0].rows[2].querySelector('[data-column-label]')).toBeNull()
    expect(element.tBodies[0].rows[3].querySelector('[data-column-label]')).toBeNull()
    expect(element.tFoot?.querySelector('[data-column-label]')).toBeNull()
    expect(element.classList.contains('column-labels')).toBe(true)
  })

  it('follows multiline classes but an explicit user choice overrides automatic changes', async () => {
    const element = table('<thead><tr><th>Number</th></tr></thead><tbody><tr><td>TEST-1</td></tr></tbody>')
    const { ctrl, preference } = controller()
    bind(element, ctrl)
    expect(element.classList.contains('column-labels')).toBe(false)
    element.classList.add('adaptive-table')
    await settle()
    expect(ctrl.columnLabelsDefault.value).toBe(true)
    expect(element.classList.contains('column-labels')).toBe(true)
    preference.value = false
    await settle()
    expect(element.classList.contains('column-labels')).toBe(false)
    element.classList.remove('adaptive-table')
    element.classList.add('multirow-table')
    await settle()
    expect(element.classList.contains('column-labels')).toBe(false)
    preference.value = true
    element.classList.remove('multirow-table')
    await settle()
    expect(ctrl.columnLabelsDefault.value).toBe(false)
    expect(element.classList.contains('column-labels')).toBe(true)
  })

  it('refreshes labels after header and row changes, clears stale cells and stops on disposal', async () => {
    const element = table('<thead><tr><th>Number</th><th>Amount</th></tr></thead><tbody><tr><td>TEST-1</td><td>100</td></tr></tbody>')
    const { ctrl } = controller(true)
    const dispose = bind(element, ctrl)
    element.tHead!.rows[0].cells[0].textContent = 'Document'
    const row = element.tBodies[0].insertRow()
    row.insertCell().textContent = 'TEST-2'
    row.insertCell().textContent = '200'
    await settle()
    expect(row.cells[0].dataset.columnLabel).toBe('Document')
    expect(row.cells[1].dataset.columnLabel).toBe('Amount')
    row.cells[0].colSpan = 2
    await settle()
    expect(row.querySelector('[data-column-label]')).toBeNull()
    dispose()
    expect(element.querySelector('[data-column-label]')).toBeNull()
    element.tHead!.rows[0].cells[0].textContent = 'Ignored'
    await settle()
    expect(element.querySelector('[data-column-label]')).toBeNull()
  })

  it('does not map grouped headers or nested tables to outer data cells', () => {
    const element = table('<thead><tr><th colspan="2">Group</th></tr><tr><th>Number</th><th>Amount</th></tr></thead><tbody><tr><td>TEST-1</td><td>100</td></tr><tr><td colspan="2"><table><thead><tr><th>Nested</th></tr></thead><tbody><tr><td data-column-label="Nested">Detail</td></tr></tbody></table></td></tr></tbody>')
    const { ctrl } = controller(true)
    const dispose = bind(element, ctrl)
    expect(element.tBodies[0].rows[0].querySelector('[data-column-label]')).toBeNull()
    expect(element.querySelector('td > table td')?.getAttribute('data-column-label')).toBe('Nested')
    dispose()
    expect(element.querySelector('td > table td')?.getAttribute('data-column-label')).toBe('Nested')
  })

  it('shares the automatic default across multiple tables using one preference', async () => {
    const first = table('<thead><tr><th>Number</th></tr></thead><tbody><tr><td>TEST-1</td></tr></tbody>')
    const second = table('<thead><tr><th>Number</th></tr></thead><tbody><tr><td>TEST-2</td></tr></tbody>')
    second.classList.add('multirow-table')
    const { ctrl } = controller()
    bind(first, ctrl)
    const disposeSecond = bind(second, ctrl)
    await settle()
    expect(first.classList.contains('column-labels')).toBe(true)
    expect(second.classList.contains('column-labels')).toBe(true)
    disposeSecond()
    await settle()
    expect(first.classList.contains('column-labels')).toBe(false)
  })

  it('settles after its own class updates without generating a mutation loop', async () => {
    const element = table('<thead><tr><th>Number</th></tr></thead><tbody><tr><td>TEST-1</td></tr></tbody>')
    const { ctrl, preference } = controller()
    let mutations = 0
    const observer = new MutationObserver(records => { mutations += records.length })
    observer.observe(element, { attributes: true, subtree: true })
    cleanups.push(() => observer.disconnect())
    bind(element, ctrl)
    preference.value = true
    await settle()
    const settled = mutations
    await settle()
    expect(mutations).toBe(settled)
    expect(element.classList.contains('column-labels')).toBe(true)
  })
})
