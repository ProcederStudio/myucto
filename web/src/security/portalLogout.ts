// localStorage je sdílené mezi kartami, ale izolované na origin vlastní domény.
const KEY = 'myinvoice.portal_logged_out'

export function markPortalLoggedOut(): void {
  localStorage.setItem(KEY, '1')
}

export function isPortalLoggedOut(): boolean {
  return localStorage.getItem(KEY) === '1'
}

export function clearPortalLoggedOut(): void {
  localStorage.removeItem(KEY)
}
