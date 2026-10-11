import { api } from './client'

export interface AiAssistantStatus {
  available: boolean
  reason: string | null
  provider: string | null
  model: string | null
  supports_files: boolean
}

export interface AiJournalLine {
  account_code: string
  account_name: string | null
  side: 'debit' | 'credit'
  amount: number
  note: string | null
}

export interface AiJournalEntry {
  entry_date: string
  description: string
  document_no: string | null
  lines: AiJournalLine[]
  balanced: boolean
  unknown_accounts: string[]
}

export type AiAttachmentRef = { kind: 'file'; index: number } | { kind: 'document'; id: number }

export interface AiDocumentSuggestion {
  attachment: AiAttachmentRef
  folder_id: number | null
  folder_path: string | null
  new_folder_name: string | null
  title: string | null
}

export interface AiHistoryTurn {
  role: 'user' | 'assistant'
  content: string
}

export interface AiAskResponse {
  answer: string
  journal_entries: AiJournalEntry[]
  document_suggestions: AiDocumentSuggestion[]
  warnings: string[]
  provider: string
  model: string
  usage: { input_tokens: number; output_tokens: number }
}

/** Koncept pro ruční zápis (history.state.aiDraft) — jen JSON-klonovatelná data. */
export interface AiManualDraft {
  entry_date: string
  description: string
  document_no: string
  lines: { account_code: string; side: 'debit' | 'credit'; amount: number }[]
  document_ids: number[]
}

export interface AiAskInput {
  question: string
  history: AiHistoryTurn[]
  files: File[]
  documentIds: number[]
  includeChart: boolean
}

export interface AiDocumentSummary {
  ai_summary_short: string | null
  ai_summary_long: string | null
  ai_summary_at: string | null
}

export interface AiDocumentAnalysis {
  detail: 'short' | 'long'
  accounting: boolean
  answer: string
  fields: { label: string; value: string }[]
  journal_entries: AiJournalEntry[]
  warnings: string[]
  provider: string | null
  model: string | null
  created_at: string | null
}

export function buildAskForm(input: AiAskInput): FormData {
  const fd = new FormData()
  fd.append('question', input.question)
  fd.append('history', JSON.stringify(input.history.slice(-10)))
  for (const f of input.files) fd.append('files[]', f, f.name)
  for (const id of input.documentIds) fd.append('document_ids[]', String(id))
  fd.append('include_chart', input.includeChart ? '1' : '0')
  return fd
}

export const aiAssistantApi = {
  status: () => api.get<AiAssistantStatus>('/accounting/ai-assistant/status').then(r => r.data),

  ask: (input: AiAskInput) =>
    api.post<AiAskResponse>('/accounting/ai-assistant/ask', buildAskForm(input), {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data),

  documentSummary: (id: number, length: 'short' | 'long') =>
    api.post<AiDocumentSummary>(`/documents/${id}/ai/summary`, { length }).then(r => r.data),

  documentAnalysis: (id: number, detail: 'short' | 'long', accounting: boolean) =>
    api.post<{ analysis: AiDocumentAnalysis }>(`/documents/${id}/ai/analysis`, { detail, accounting })
      .then(r => r.data.analysis),
}

/** Zpráva chyby z Json::error (`error.message`), jinak záložní text. */
export function aiErrorMessage(e: unknown, fallback: string): string {
  const msg = (e as { response?: { data?: { error?: { message?: unknown } } } })?.response?.data?.error?.message
  return typeof msg === 'string' && msg.trim() !== '' ? msg : fallback
}
