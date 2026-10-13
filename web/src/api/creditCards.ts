import { api } from './client'

export type CreditCardIssuer = 'kb' | 'rb' | 'csob' | 'erste' | 'other'
export type CreditCardTransactionKind = 'purchase' | 'refund' | 'repayment' | 'interest' | 'fee' | 'cash' | 'reward'

export const CREDIT_CARD_KINDS: CreditCardTransactionKind[] = ['purchase', 'refund', 'repayment', 'interest', 'fee', 'cash', 'reward']

export interface CreditCardStatement {
  id: number
  statement_date: string
  statement_number: string
  prev_balance: number
  curr_balance: number
  credit_total: number
  debit_total: number
  transaction_count: number
  file_name: string
  has_pdf: boolean
  /** Jen v detailu: zůstatek 231.x ke dni výpisu, rozdíl proti výpisu a počet nevyřešených pohybů. */
  ledger_balance?: number | null
  difference?: number | null
  todo_count?: number
}

/** Režim nákupů: mezičlen 378.x (zaúčtovat hned, vypořádat dokladem), nebo bez mezičlenu. */
export type CreditCardPurchaseMode = 'clearing' | 'direct'
export const CREDIT_CARD_PURCHASE_MODES: CreditCardPurchaseMode[] = ['clearing', 'direct']

/** Stav pohybu z pohledu účetní - co s ním zbývá udělat. */
export type CreditCardTransactionState = 'unposted' | 'suggested' | 'clearing_open' | 'settled' | 'posted' | 'ignored'
export const CREDIT_CARD_TODO_STATES = ['unposted', 'suggested', 'clearing_open'] as const
export type CreditCardTodoState = typeof CREDIT_CARD_TODO_STATES[number]

export interface CreditCardTodoRow {
  count: number
  amount: number
  first_statement_id: number | null
  first_tx_id: number | null
}

export interface CreditCardClearingInfo {
  /** Účinný režim (vlastní, jinak výchozí firmy). */
  mode: CreditCardPurchaseMode
  /** Režim nastavený u účtu, null = výchozí firmy. */
  account_mode: CreditCardPurchaseMode | null
  default_mode: CreditCardPurchaseMode
  synthetic: string
  /** Analytika mezičlenu účtu, null = vznikne u prvního nákupu. */
  account_code: string | null
  /** Zůstatek mezičlenu = nákupy, ke kterým chybí doklad. */
  balance: number
  options: Array<{ id: number; account_code: string; name: string; card_id: number | null; credit_card_account_id: number | null }>
}

export interface CreditCardOpening {
  account_code: string | null
  statement: { id: number; statement_date: string; statement_number: string; prev_balance: number } | null
  entry_date: string | null
  contra_account_code: string
  contra_account_id: number | null
  /** Už zaúčtovaný zápis počátečního dluhu. */
  posted_entry_id: number | null
  /** Co na 231.x leží mimo pohyby výpisů (dříve převzatý zůstatek). */
  other_balance: number
  /** Částka k zaúčtování se znaménkem výpisu (dluh záporný). */
  amount: number
  needed: boolean
}

export interface CreditCardTransaction {
  id: number
  statement_id: number
  posted_at: string
  amount: number
  currency: string
  description: string | null
  counterparty_name: string | null
  counterparty_account: string | null
  card_last4: string | null
  kind: CreditCardTransactionKind
  match_status: string
  /** Živý bankovní zápis pohybu (zaúčtováno), jinak null. */
  entry_id: number | null
  /** Otevřený návrh zaúčtování ve frontě, jinak null. */
  suggestion_id: number | null
  ignore_note?: string | null
  /** Analytika mezičlenu, na které nákup leží (null = bez mezičlenu). */
  clearing_code?: string | null
  /** Ke pohybu je spárovaný přijatý doklad. */
  has_document?: boolean
  state: CreditCardTransactionState
}

/** Úvěrový účet kreditní karty (účtuje se na 231.xxx). */
export interface CreditCardAccount {
  id: number
  bank_account_id: number
  issuer: CreditCardIssuer
  label: string
  account_number: string
  bank_code: string | null
  currency: string
  credit_limit: number | null
  analytic_suffix: string | null
  purchase_mode: CreditCardPurchaseMode | null
  clearing_suffix: string | null
  opening_entry_id: number | null
  repayment_account: string | null
  repayment_bank_code: string | null
  repayment_vs: string | null
  /** false = účet založil import výpisu; čeká na kontrolu. */
  is_verified: boolean
  archived: boolean
  archived_at: string | null
  note: string | null
  created_at: string
  /** Kód analytiky 231.xxx, null = zatím nepřidělená. */
  account_code: string | null
  /** Zůstatek analytiky v účetnictví (MD − D; dluh je záporný jako na výpisu). */
  ledger_balance: number | null
  last_statement: CreditCardStatement | null
  statement_count: number
  unposted_count: number
  /** Účetnictví k datu posledního výpisu − konečný zůstatek výpisu. */
  balance_difference: number | null
}

export interface CreditCardDetail extends CreditCardAccount {
  statements: CreditCardStatement[]
  transactions: CreditCardTransaction[]
  todo: Record<CreditCardTodoState, CreditCardTodoRow>
  clearing: CreditCardClearingInfo
  opening: CreditCardOpening
  analytic_options: Array<{ id: number; account_code: string; name: string; credit_card_account_id: number | null }>
}

export interface CreditCardPostPendingResult {
  total: number
  posted: number
  suggested: number
  skipped: number
  reasons: Record<string, number>
}

export interface CreditCardPayload {
  label: string
  credit_limit: number | null
  repayment_account: string | null
  repayment_bank_code: string | null
  repayment_vs: string | null
  note: string | null
}

export type CreditCardSettingsField =
  | 'interest' | 'fee' | 'repayment' | 'cash' | 'reward'
  | 'writeoff_tax' | 'writeoff_nontax' | 'private' | 'opening'
export const CREDIT_CARD_SETTINGS_FIELDS: CreditCardSettingsField[] = [
  'interest', 'fee', 'repayment', 'cash', 'reward', 'writeoff_tax', 'writeoff_nontax', 'private', 'opening',
]

export type CreditCardSettings = {
  configured: boolean
  purchase_mode: CreditCardPurchaseMode
} & Record<`${CreditCardSettingsField}_account_id`, number | null>
  & Record<`${CreditCardSettingsField}_account_code`, string | null>

export interface CreditCardAccountOption {
  id: number
  account_code: string
  name: string
  is_synthetic: boolean
  non_deductible: boolean
}

export interface CreditCardSettingsResponse {
  double_entry: boolean
  settings: CreditCardSettings
  defaults: Record<CreditCardSettingsField, string>
  modes: CreditCardPurchaseMode[]
  default_mode: CreditCardPurchaseMode
  /** Syntetika mezičlenu z nastavení platebních karet (378 / 261 / 395). */
  clearing_synthetic: string
  allowed_prefixes: Record<CreditCardSettingsField, string[]>
  account_options: CreditCardAccountOption[]
}

export type CreditCardSettingsPayload = { purchase_mode?: CreditCardPurchaseMode }
  & Partial<Record<`${CreditCardSettingsField}_account_id`, number | null>>

export interface CreditCardImportResult {
  statement_id: number
  transactions: number
  matched: number
  duplicate: boolean
  credit_card_account_id: number | null
  skipped_duplicates?: number
  /** Úvěrový účet eviduje jiná firma uživatele: výpis se naimportoval do ní. */
  target_supplier_id?: number
  target_supplier_name?: string | null
}

export const creditCardsApi = {
  list: (includeArchived = false) =>
    api.get<{ accounts: CreditCardAccount[] }>('/credit-cards', { params: includeArchived ? { include_archived: 1 } : {} })
      .then(r => r.data.accounts),
  get: (id: number) => api.get<CreditCardDetail>(`/credit-cards/${id}`).then(r => r.data),
  update: (id: number, payload: CreditCardPayload) => api.put<CreditCardDetail>(`/credit-cards/${id}`, payload).then(r => r.data),
  setAnalytic: (id: number, accountCode: string) =>
    api.put<CreditCardDetail>(`/credit-cards/${id}/analytic`, { account_code: accountCode }).then(r => r.data),
  archive: (id: number) => api.post<CreditCardDetail>(`/credit-cards/${id}/archive`).then(r => r.data),
  restore: (id: number) => api.post<CreditCardDetail>(`/credit-cards/${id}/restore`).then(r => r.data),
  importStatement: (file: File, creditCardAccountId: number | null = null) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    if (creditCardAccountId !== null) fd.append('credit_card_account_id', String(creditCardAccountId))
    return api.post<CreditCardImportResult>('/credit-cards/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000,
    }).then(r => r.data)
  },
  convert: (bankAccountId: number) =>
    api.post<{ credit_card_account_id: number; reposted: number }>('/credit-cards/convert', { bank_account_id: bankAccountId })
      .then(r => r.data),
  settings: () => api.get<CreditCardSettingsResponse>('/credit-cards/settings').then(r => r.data),
  saveSettings: (payload: CreditCardSettingsPayload) =>
    api.put<CreditCardSettingsResponse>('/credit-cards/settings', payload).then(r => r.data),
  setPurchaseMode: (id: number, mode: CreditCardPurchaseMode | null) =>
    api.put<CreditCardDetail>(`/credit-cards/${id}/purchase-mode`, { purchase_mode: mode }).then(r => r.data),
  setClearingAnalytic: (id: number, accountCode: string | null, confirm = false) =>
    api.put<CreditCardDetail>(`/credit-cards/${id}/clearing-analytic`, { account_code: accountCode, confirm }).then(r => r.data),
  postPending: (id: number) =>
    api.post<{ result: CreditCardPostPendingResult; detail: CreditCardDetail }>(`/credit-cards/${id}/post-pending`, {}, { timeout: 300000 })
      .then(r => r.data),
  postOpening: (id: number, payload: { contra_account_code: string | null; entry_date: string | null }) =>
    api.post<{ result: { entry_id: number; amount: number; contra_account_code: string; entry_date: string }; detail: CreditCardDetail }>(
      `/credit-cards/${id}/opening`, payload,
    ).then(r => r.data),
}
