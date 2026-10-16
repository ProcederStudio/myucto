/**
 * Tisk PDF z aplikace jedním klikem.
 *
 * PDF se stáhne jako blob a vytiskne ze skrytého iframe přes vestavěný prohlížeč
 * PDF. Odpověď API nese X-Frame-Options, ta se ale na blob URL nevztahuje, proto
 * nejde iframe nasměrovat rovnou na URL API. Prohlížeč bez vestavěného prohlížeče
 * PDF (typicky mobil, `navigator.pdfViewerEnabled === false`) by v iframe vytiskl
 * prázdnou stránku: dostane PDF hned v nové záložce (ještě v rámci kliknutí, ať
 * ho nezastaví blokování oken) a vytiskne ho odtud. Když tisk z iframe selže,
 * otevře se stažené PDF v nové záložce.
 */

export type PrintPdfResult = 'printed' | 'opened'

let current: { frame: HTMLIFrameElement; blobUrl: string } | null = null

/** Předchozí tiskový iframe necháváme žít až do dalšího tisku: tiskový dialog běží
 *  asynchronně a předčasně uvolněný blob by ve Firefoxu vytiskl prázdnou stránku. */
function cleanup(): void {
  if (!current) return
  current.frame.remove()
  URL.revokeObjectURL(current.blobUrl)
  current = null
}

export async function printPdf(url: string): Promise<PrintPdfResult> {
  if (typeof navigator !== 'undefined' && navigator.pdfViewerEnabled === false) {
    window.open(url, '_blank', 'noopener')
    return 'opened'
  }

  const response = await fetch(url, { credentials: 'same-origin' })
  if (!response.ok) throw new Error(`PDF download failed (${response.status})`)
  const blob = await response.blob()

  cleanup()
  const blobUrl = URL.createObjectURL(blob)
  const frame = document.createElement('iframe')
  frame.setAttribute('data-print-pdf', '')
  frame.setAttribute('aria-hidden', 'true')
  frame.tabIndex = -1
  // Mimo obrazovku, ale s rozměrem: iframe s display:none nebo nulovou velikostí
  // některé prohlížeče nevykreslí a tisk zůstane prázdný.
  frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:800px;height:600px;border:0;'
  current = { frame, blobUrl }

  const loaded = new Promise<void>(resolve => frame.addEventListener('load', () => resolve(), { once: true }))
  frame.src = blobUrl
  document.body.appendChild(frame)
  await loaded

  try {
    const win = frame.contentWindow
    if (!win) throw new Error('print frame without window')
    win.focus()
    win.print()
    return 'printed'
  } catch {
    window.open(blobUrl, '_blank', 'noopener')
    return 'opened'
  }
}
