import { requestSupplierId } from '@/workspace/supplierContext'
import { useAuthStore } from '@/stores/auth'

/**
 * Pohodlnost jednoho uživatele v tomto prohlížeči (rozbalená sekce apod.).
 * Klíč nese uživatele i firmu, aby si dva lidé na jednom počítači nepřepisovali
 * volbu. Úložiště může chybět nebo házet (anonymní okno, zablokovaná data),
 * proto každé čtení i zápis tiše selže a stránka funguje i bez něj.
 */
export function usePerUserFlag(name: string) {
  function storageKey(): string {
    let userId = '0'
    let supplierId = '0'
    try {
      userId = String(useAuthStore().user?.id ?? '0')
    } catch {
      userId = '0'
    }
    try {
      supplierId = requestSupplierId() ?? '0'
    } catch {
      supplierId = '0'
    }
    return `myinvoice.ui.${name}.${userId}.${supplierId}`
  }

  function read(): boolean | null {
    try {
      const raw = localStorage.getItem(storageKey())
      return raw === null ? null : raw === '1'
    } catch {
      return null
    }
  }

  function write(value: boolean): void {
    try {
      localStorage.setItem(storageKey(), value ? '1' : '0')
    } catch {
      // Volba se jen nezapamatuje.
    }
  }

  return { read, write }
}
