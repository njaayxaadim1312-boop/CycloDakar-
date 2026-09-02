import { api, getData } from '@/lib/api'
import type {
  CashDashboard,
  Expense,
  ExpenseAttachment,
  ExpenseInput,
  ExpenseStatusCode,
  IncomeInput,
  LedgerCategory,
  LedgerEntry,
  Paginated,
} from '@/types/api'

/**
 * Accès à l'API de la caisse : dépenses, journal, recettes manuelles.
 *
 * **Tous les montants sont des entiers de FCFA.** La seule mise en forme est
 * `formatFcfa`, à l'affichage.
 *
 * TROIS CHOSES QUE CE CLIENT NE FAIT PAS
 *
 * Il n'envoie aucun statut de dépense : une dépense naît toujours en attente,
 * et c'est le serveur qui décide si elle passe seule sous le seuil. Le
 * proposer ici ouvrirait une porte que le seuil est censé fermer.
 *
 * Il n'envoie aucun solde, et il n'existe aucune route pour cela (règle I1).
 *
 * Il ne supprime pas une dépense : on la refuse, avec un motif, et la ligne
 * reste. Le bureau doit pouvoir expliquer pourquoi une dépense n'a pas été
 * engagée.
 */

/* ------------------------------------------------------------- dépenses --- */

export interface ExpenseFilters {
  status?: ExpenseStatusCode | ''
  scope?: 'pending' | 'all'
  category?: string
  event?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}

function cleanParams(filters: object): Record<string, string | number> {
  const params: Record<string, string | number> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === undefined || value === null) continue

    params[key] = value as string | number
  }

  return params
}

export async function fetchExpenses(filters: ExpenseFilters = {}): Promise<Paginated<Expense>> {
  const response = await api.get<Paginated<Expense>>('/expenses', {
    params: cleanParams(filters),
  })

  return response.data
}

export function fetchExpense(uuid: string): Promise<Expense> {
  return getData<Expense>(`/expenses/${uuid}`)
}

export async function createExpense(input: ExpenseInput): Promise<Expense> {
  const response = await api.post<{ data: Expense }>('/expenses', input)

  return response.data.data
}

/** Approuve : c'est ici que l'argent sort réellement de la caisse. */
export async function approveExpense(uuid: string): Promise<Expense> {
  const response = await api.post<{ data: Expense }>(`/expenses/${uuid}/approve`)

  return response.data.data
}

/** Refuse. Aucune écriture, et la ligne reste — avec son motif. */
export async function rejectExpense(uuid: string, reason: string): Promise<Expense> {
  const response = await api.post<{ data: Expense }>(`/expenses/${uuid}/reject`, { reason })

  return response.data.data
}

/**
 * Joint un justificatif.
 *
 * `multipart/form-data` est laissé à la charge du navigateur : fixer
 * l'en-tête à la main casse la limite (`boundary`) qu'il génère lui-même.
 */
export async function attachToExpense(uuid: string, file: File): Promise<ExpenseAttachment> {
  const body = new FormData()
  body.append('file', file)

  const response = await api.post<{ data: ExpenseAttachment }>(
    `/expenses/${uuid}/attachments`,
    body,
  )

  return response.data.data
}

export async function detachFromExpense(
  expenseUuid: string,
  attachmentUuid: string,
): Promise<void> {
  await api.delete(`/expenses/${expenseUuid}/attachments/${attachmentUuid}`)
}

/* --------------------------------------------------------------- caisse --- */

export interface LedgerFilters {
  from?: string
  to?: string
  direction?: 'IN' | 'OUT' | ''
  category?: string
  event?: string
  page?: number
  per_page?: number
}

export async function fetchLedger(filters: LedgerFilters = {}): Promise<Paginated<LedgerEntry>> {
  const response = await api.get<Paginated<LedgerEntry>>('/finance/transactions', {
    params: cleanParams(filters),
  })

  return response.data
}

export async function fetchDashboard(range: { from?: string; to?: string } = {}): Promise<{
  dashboard: CashDashboard
  period: { from: string; to: string }
}> {
  const response = await api.get<{ data: CashDashboard; meta: { from: string; to: string } }>(
    '/finance/dashboard',
    { params: cleanParams(range) },
  )

  return { dashboard: response.data.data, period: response.data.meta }
}

/** Les postes du grand livre, avec leur SENS — indispensable aux formulaires. */
export function fetchLedgerCategories(): Promise<LedgerCategory[]> {
  return getData<LedgerCategory[]>('/finance/categories')
}

/** Recette manuelle : don, sponsoring, vente. Entre directement au grand livre. */
export async function createIncome(input: IncomeInput): Promise<LedgerEntry> {
  const response = await api.post<{ data: LedgerEntry }>('/finance/income', input)

  return response.data.data
}
