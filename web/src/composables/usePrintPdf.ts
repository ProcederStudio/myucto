import { api } from '@/api/client'

/**
 * Tisk PDF z aplikace jedním klikem.
 *
 * PDF se stáhne přes API klienta (platí interceptory: firma v hlavičce, odhlášení
 * vede na přihlášení) jako blob a vytiskne ze skrytého iframe přes vestavěný
 * prohlížeč PDF. Odpověď API nese X-Frame-Options, ta se ale na blob URL
 * nevztahuje, proto nejde iframe nasměrovat rovnou na URL API.
 *
 * Prohlížeč bez vestavěného prohlížeče PDF (typicky mobil, `navigator.pdfViewerEnabled
 * === false`) by v iframe vytiskl prázdnou stránku: dostane PDF hned v nové záložce,
 * ještě v rámci kliknutí, ať ho nezastaví blokování oken. Po stažení už nové okno
 * neotvíráme (blokátor by ho zastavil); selhání se propíše volajícímu, který nabídne
 * odkaz na zobrazení PDF.
 */

export type PrintPdfResult = 'printed' | 'opened' | 'busy'

/** Jak dlouho čekat na načtení PDF v iframe. */
const LOAD_TIMEOUT_MS = 10_000
/** Odklad tisku po načtení: Safari a Firefox jinak občas vytisknou prázdnou stránku. */
const PRINT_DELAY_MS = 300

let current: { frame: HTMLIFrameElement; blobUrl: string } | null = null
let running = false

/** Předchozí tiskový iframe necháváme žít až do dalšího tisku: tiskový dialog běží
 *  asynchronně a předčasně uvolněný blob by ve Firefoxu vytiskl prázdnou stránku. */
function cleanup(): void {
  if (!current) return
  current.frame.remove()
  URL.revokeObjectURL(current.blobUrl)
  current = null
}

function waitForLoad(frame: HTMLIFrameElement): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('print frame load timeout')), LOAD_TIMEOUT_MS)
    frame.addEventListener('load', () => { clearTimeout(timer); resolve() }, { once: true })
  })
}

/**
 * @param path cesta pro API klienta (bez `/api`), např. `/invoices/9/pdf`
 * @param viewUrl přímá URL PDF pro novou záložku (stejná jako „Zobrazit PDF")
 * @returns `busy`, když předchozí tisk ještě běží (druhé kliknutí se ignoruje)
 */
export async function printPdf(path: string, viewUrl: string): Promise<PrintPdfResult> {
  if (running) return 'busy'
  if (typeof navigator !== 'undefined' && navigator.pdfViewerEnabled === false) {
    window.open(viewUrl, '_blank', 'noopener')
    return 'opened'
  }

  running = true
  try {
    const { data } = await api.get<Blob>(path, { responseType: 'blob' })

    cleanup()
    const blobUrl = URL.createObjectURL(data)
    const frame = document.createElement('iframe')
    frame.setAttribute('data-print-pdf', '')
    frame.setAttribute('aria-hidden', 'true')
    frame.tabIndex = -1
    // Mimo obrazovku, ale s rozměrem: iframe s display:none nebo nulovou velikostí
    // některé prohlížeče nevykreslí a tisk zůstane prázdný.
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:800px;height:600px;border:0;'
    current = { frame, blobUrl }

    const loaded = waitForLoad(frame)
    frame.src = blobUrl
    document.body.appendChild(frame)
    await loaded
    await new Promise(resolve => setTimeout(resolve, PRINT_DELAY_MS))

    const win = frame.contentWindow
    if (!win) throw new Error('print frame without window')
    win.focus()
    win.print()
    return 'printed'
  } finally {
    running = false
  }
}
