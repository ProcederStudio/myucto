import { api } from './client'

/**
 * Nahrávání velkého souboru po částech pro průvodce převodem agendy (Money S3, POHODA).
 * Server pod `{baseUrl}/uploads/…` přijme init, jednotlivé části a dokončení; zpracování
 * souboru pak běží na pozadí a stav se čte zvlášť.
 */

const CHUNK_ATTEMPTS = 5
const MAX_RETRY_WAIT_MS = 60_000

function wait(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms))
}

function isRetryable(status: number): boolean {
  return status === 0 || status === 408 || status === 429 || status >= 500
}

/**
 * Pauza před dalším pokusem. U 429 podle `Retry-After`: server ví, kdy se okno
 * limitu uvolní, a pevná krátká pauza by padla do téhož okna.
 */
export function retryDelay(error: any, attempt: number): number {
  const retryAfter = Number(error?.response?.headers?.['retry-after'])
  if (Number(error?.response?.status) === 429 && Number.isFinite(retryAfter) && retryAfter > 0) {
    return Math.min(retryAfter * 1000, MAX_RETRY_WAIT_MS)
  }
  return 1000 * attempt
}

export type ChunkedUploadProgress = (sent: number, total: number) => void

/**
 * Soubor má stovky megabajtů až gigabajty, víc, než PHP a webserver přijmou
 * jedním požadavkem. Posílá se proto po částech; každá část se při výpadku nebo
 * limitu požadavků zopakuje a 409 od serveru říká, kolik dat už má, takže se naváže
 * bez duplicit. `complete` je na serveru idempotentní, takže se smí zopakovat taky.
 */
export async function uploadChunked(
  baseUrl: string,
  file: File,
  onProgress?: ChunkedUploadProgress,
  onStarted?: (token: string) => void,
  initOptions?: Record<string, unknown>,
): Promise<{ token: string; job_id: number | null }> {
  const init = (await api.post<{ token: string; chunk_size: number }>(`${baseUrl}/uploads/chunked`, { ...initOptions, file_name: file.name, size: file.size })).data
  onStarted?.(init.token)
  let offset = 0
  onProgress?.(0, file.size)
  while (offset < file.size) {
    const start = offset
    const end = Math.min(file.size, start + init.chunk_size)
    for (let attempt = 1; ; attempt++) {
      try {
        const fd = new FormData()
        fd.append('offset', String(start))
        fd.append('chunk', file.slice(start, end), file.name)
        offset = (await api.post<{ received: number }>(`${baseUrl}/uploads/${init.token}/chunks`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })).data.received
        break
      } catch (error: any) {
        const status = Number(error?.response?.status ?? 0)
        const received = Number(error?.response?.data?.error?.received)
        if (status === 409 && Number.isFinite(received) && received !== start) {
          offset = received
          break
        }
        if (!isRetryable(status) || attempt >= CHUNK_ATTEMPTS) throw error
        await wait(retryDelay(error, attempt))
      }
    }
    onProgress?.(offset, file.size)
  }
  for (let attempt = 1; ; attempt++) {
    try {
      return (await api.post<{ token: string; job_id: number | null }>(`${baseUrl}/uploads/${init.token}/complete`, {})).data
    } catch (error: any) {
      if (!isRetryable(Number(error?.response?.status ?? 0)) || attempt >= CHUNK_ATTEMPTS) throw error
      await wait(retryDelay(error, attempt))
    }
  }
}
