import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Tisk PDF bez opuštění aplikace: PDF se stáhne přes API klienta (interceptory:
// přihlášení, firma) jako blob a vytiskne ze skrytého iframe (X-Frame-Options
// odpovědi API se blob URL netýká). Prohlížeč bez vestavěného prohlížeče PDF
// (mobil) dostane PDF v nové záložce hned v rámci kliknutí.

const m = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/api/client', () => ({ api: { get: m.get } }))

import { printPdf } from '../usePrintPdf'

const originalPdfViewer = Object.getOwnPropertyDescriptor(Navigator.prototype, 'pdfViewerEnabled')

function setPdfViewer(enabled: boolean | undefined) {
  Object.defineProperty(navigator, 'pdfViewerEnabled', { configurable: true, get: () => enabled })
}

function frames(): HTMLIFrameElement[] {
  return Array.from(document.querySelectorAll<HTMLIFrameElement>('iframe[data-print-pdf]'))
}

/** Počká na nový tiskový iframe, podstrčí mu okno a ohlásí načtení. */
async function loadFrame(win: { print: () => void; focus: () => void }, previous: HTMLIFrameElement | null = null) {
  await vi.waitFor(() => expect(frames().find(f => f !== previous)).toBeDefined())
  const frame = frames().find(f => f !== previous)!
  Object.defineProperty(frame, 'contentWindow', { configurable: true, get: () => win })
  frame.dispatchEvent(new Event('load'))
  return frame
}

beforeEach(() => {
  m.get.mockResolvedValue({ data: new Blob(['%PDF-1.7'], { type: 'application/pdf' }) })
  URL.createObjectURL = vi.fn(() => 'blob:pdf-1')
  URL.revokeObjectURL = vi.fn()
})

afterEach(() => {
  vi.clearAllMocks()
  vi.restoreAllMocks()
  vi.useRealTimers()
  delete (navigator as unknown as Record<string, unknown>).pdfViewerEnabled
  if (originalPdfViewer) Object.defineProperty(Navigator.prototype, 'pdfViewerEnabled', originalPdfViewer)
  document.body.replaceChildren()
})

describe('printPdf', () => {
  it('stáhne PDF přes API klienta, vloží ho do skrytého iframe a po krátkém odkladu tiskne', async () => {
    setPdfViewer(true)
    const print = vi.fn()
    const result = printPdf('/invoices/9/pdf', '/api/invoices/9/pdf?supplier_id=1')
    const frame = await loadFrame({ print, focus: vi.fn() })
    expect(m.get).toHaveBeenCalledWith('/invoices/9/pdf', { responseType: 'blob' })
    expect(frame.getAttribute('src')).toBe('blob:pdf-1')
    expect(frame.getAttribute('aria-hidden')).toBe('true')
    expect(print).not.toHaveBeenCalled()
    await expect(result).resolves.toBe('printed')
    expect(print).toHaveBeenCalledTimes(1)
  })

  it('druhé kliknutí během běžícího tisku se ignoruje a nezruší rámec prvního', async () => {
    setPdfViewer(true)
    const first = printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')
    await vi.waitFor(() => expect(frames()).toHaveLength(1))
    const frame = frames()[0]
    await expect(printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')).resolves.toBe('busy')
    expect(frame.isConnected).toBe(true)
    expect(m.get).toHaveBeenCalledTimes(1)
    Object.defineProperty(frame, 'contentWindow', { configurable: true, get: () => ({ print: vi.fn(), focus: vi.fn() }) })
    frame.dispatchEvent(new Event('load'))
    await expect(first).resolves.toBe('printed')
  })

  it('další tisk po dokončení uklidí předchozí iframe a uvolní jeho blob', async () => {
    setPdfViewer(true)
    const one = printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')
    const prev = await loadFrame({ print: vi.fn(), focus: vi.fn() })
    await one
    const two = printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')
    await loadFrame({ print: vi.fn(), focus: vi.fn() }, prev)
    await two
    expect(frames()).toHaveLength(1)
    expect(prev.isConnected).toBe(false)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:pdf-1')
  })

  it('bez vestavěného prohlížeče PDF otevře PDF v nové záložce hned (bez stahování)', async () => {
    setPdfViewer(false)
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    await expect(printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')).resolves.toBe('opened')
    expect(open).toHaveBeenCalledWith('/api/invoices/9/pdf', '_blank', 'noopener')
    expect(m.get).not.toHaveBeenCalled()
  })

  it('selhání tisku z iframe propíše volajícímu a nové okno už neotevírá (blokátor)', async () => {
    setPdfViewer(true)
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const result = printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')
    await loadFrame({ focus: vi.fn(), print: () => { throw new Error('blocked') } })
    await expect(result).rejects.toThrow('blocked')
    expect(open).not.toHaveBeenCalled()
  })

  it('iframe, který se nenačte do 10 s, skončí chybou a uvolní zámek tisku', async () => {
    setPdfViewer(true)
    vi.useFakeTimers()
    const result = printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')
    const settled = expect(result).rejects.toThrow('timeout')
    await vi.waitFor(() => expect(frames()).toHaveLength(1))
    await vi.advanceTimersByTimeAsync(10_000)
    await settled
    vi.useRealTimers()
    const stale = frames()[0] ?? null
    const next = printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')
    await loadFrame({ print: vi.fn(), focus: vi.fn() }, stale)
    await expect(next).resolves.toBe('printed')
  })

  it('chyba stažení se propíše volajícímu a iframe nevznikne', async () => {
    setPdfViewer(true)
    m.get.mockRejectedValue(new Error('Request failed with status code 403'))
    await expect(printPdf('/invoices/9/pdf', '/api/invoices/9/pdf')).rejects.toThrow('403')
    expect(frames()).toHaveLength(0)
  })
})
