import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { printPdf } from '../usePrintPdf'

// Tisk PDF bez opuštění aplikace: PDF se stáhne jako blob a vytiskne ze skrytého
// iframe (X-Frame-Options odpovědi API se blob URL netýká). Prohlížeč bez vestavěného
// prohlížeče PDF (mobil) dostane PDF v nové záložce a vytiskne ho odtud.

const originalPdfViewer = Object.getOwnPropertyDescriptor(Navigator.prototype, 'pdfViewerEnabled')

function setPdfViewer(enabled: boolean | undefined) {
  Object.defineProperty(navigator, 'pdfViewerEnabled', { configurable: true, get: () => enabled })
}

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(new Blob(['%PDF-1.7'], { type: 'application/pdf' }), { status: 200 })))
  URL.createObjectURL = vi.fn(() => 'blob:pdf-1')
  URL.revokeObjectURL = vi.fn()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  vi.useRealTimers()
  delete (navigator as unknown as Record<string, unknown>).pdfViewerEnabled
  if (originalPdfViewer) Object.defineProperty(Navigator.prototype, 'pdfViewerEnabled', originalPdfViewer)
  document.body.replaceChildren()
})

describe('printPdf', () => {
  it('stáhne PDF, vloží ho do skrytého iframe a zavolá tisk', async () => {
    setPdfViewer(true)
    const result = printPdf('/api/invoices/9/pdf?supplier_id=1')
    await vi.waitFor(() => expect(document.querySelector('iframe[data-print-pdf]')).not.toBeNull())
    const frame = document.querySelector<HTMLIFrameElement>('iframe[data-print-pdf]')!
    expect(fetch).toHaveBeenCalledWith('/api/invoices/9/pdf?supplier_id=1', { credentials: 'same-origin' })
    expect(frame.getAttribute('src')).toBe('blob:pdf-1')
    expect(frame.getAttribute('aria-hidden')).toBe('true')

    const print = vi.fn()
    const focus = vi.fn()
    Object.defineProperty(frame, 'contentWindow', { configurable: true, get: () => ({ print, focus }) })
    frame.dispatchEvent(new Event('load'))

    await expect(result).resolves.toBe('printed')
    expect(print).toHaveBeenCalledTimes(1)
  })

  it('další tisk uklidí předchozí iframe a uvolní jeho blob', async () => {
    setPdfViewer(true)
    let previous: HTMLIFrameElement | null = null
    for (let i = 0; i < 2; i++) {
      const result = printPdf('/api/invoices/9/pdf')
      await vi.waitFor(() => expect(document.querySelector('iframe[data-print-pdf]')).not.toBe(previous))
      const frame = document.querySelector<HTMLIFrameElement>('iframe[data-print-pdf]')!
      expect(frame).not.toBeNull()
      previous = frame
      Object.defineProperty(frame, 'contentWindow', { configurable: true, get: () => ({ print: vi.fn(), focus: vi.fn() }) })
      frame.dispatchEvent(new Event('load'))
      await result
    }
    expect(document.querySelectorAll('iframe[data-print-pdf]')).toHaveLength(1)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:pdf-1')
  })

  it('bez vestavěného prohlížeče PDF otevře PDF v nové záložce hned (bez stahování)', async () => {
    setPdfViewer(false)
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    await expect(printPdf('/api/invoices/9/pdf')).resolves.toBe('opened')
    expect(open).toHaveBeenCalledWith('/api/invoices/9/pdf', '_blank', 'noopener')
    expect(fetch).not.toHaveBeenCalled()
  })

  it('když tisk z iframe selže, otevře stažené PDF v nové záložce', async () => {
    setPdfViewer(true)
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const result = printPdf('/api/invoices/9/pdf')
    await vi.waitFor(() => expect(document.querySelector('iframe[data-print-pdf]')).not.toBeNull())
    const frame = document.querySelector<HTMLIFrameElement>('iframe[data-print-pdf]')!
    Object.defineProperty(frame, 'contentWindow', { configurable: true, get: () => ({ focus: vi.fn(), print: () => { throw new Error('blocked') } }) })
    frame.dispatchEvent(new Event('load'))
    await expect(result).resolves.toBe('opened')
    expect(open).toHaveBeenCalledWith('blob:pdf-1', '_blank', 'noopener')
  })

  it('chyba stažení se propíše volajícímu (ten ukáže hlášku)', async () => {
    setPdfViewer(true)
    vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 403 })))
    await expect(printPdf('/api/invoices/9/pdf')).rejects.toThrow('403')
    expect(document.querySelector('iframe[data-print-pdf]')).toBeNull()
  })
})
