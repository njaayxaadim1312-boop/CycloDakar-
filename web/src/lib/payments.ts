import { api, getData } from '@/lib/api'
import type {
  CashState,
  CollectorTally,
  MyDues,
  MyDuesTotals,
  Paginated,
  ParticipationLine,
  Payment,
  PaymentInput,
} from '@/types/api'

/**
 * Accès à l'API des encaissements.
 *
 * **Tous les montants sont des entiers de FCFA**, à l'entrée comme à la
 * sortie. Rien à convertir : le franc CFA n'a pas de subdivision en usage. La
 * seule mise en forme est `formatFcfa`, à l'affichage.
 *
 * DEUX CHOSES QUE CE CLIENT NE FAIT PAS, ET NE DOIT JAMAIS FAIRE
 *
 * Il n'envoie ni `collected_by`, ni `paid_amount`, ni `status`, ni le moindre
 * solde. Le serveur les détermine seul (docs/finance.md, règle I3) ; les
 * exposer ici laisserait croire qu'on peut les écrire.
 *
 * Il ne SUPPRIME rien. Une erreur s'annule — ce qui écrit une contre-passation
 * au grand livre et conserve le reçu, marqué annulé. C'est pourquoi
 * `cancelPayment` est un POST : un `DELETE` laisserait croire le contraire à
 * quiconque lit ce fichier.
 */

/**
 * Fabrique une clé d'idempotence.
 *
 * À appeler UNE FOIS par saisie, et à réutiliser telle quelle sur chaque
 * tentative d'envoi. C'est ce qui protège le membre d'un double débit quand le
 * réseau lâche entre la requête et la réponse — le cas courant sur la route du
 * Lac Rose. En fabriquer une nouvelle à chaque tentative reviendrait à ne pas
 * en avoir.
 */
export function newIdempotencyKey(): string {
  return crypto.randomUUID()
}

/**
 * Enregistre un encaissement.
 *
 * `replayed` distingue « c'est enregistré » de « c'était déjà enregistré » :
 * l'interface peut alors dire au collecteur que sa reprise a bien retrouvé le
 * paiement, au lieu de lui laisser croire qu'il vient d'en créer un second.
 */
export async function collectPayment(
  participationUuid: string,
  input: PaymentInput,
): Promise<{ payment: Payment; replayed: boolean; line: ParticipationLine }> {
  const response = await api.post<{
    data: Payment
    meta: { replayed: boolean; line: ParticipationLine }
  }>(`/participations/${participationUuid}/payments`, input)

  return {
    payment: response.data.data,
    replayed: response.data.meta.replayed,
    line: response.data.meta.line,
  }
}

export interface PaymentFilters {
  member?: string
  method?: string
  /** Masquées par défaut, mais atteignables : les cacher tout à fait
   *  empêcherait de comprendre un écart de caisse. */
  include_cancelled?: boolean
  page?: number
  per_page?: number
}

export async function fetchPayments(
  participationUuid: string,
  filters: PaymentFilters = {},
): Promise<Paginated<Payment>> {
  const params: Record<string, string | number> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === undefined || value === null || value === false) continue

    params[key] = value === true ? 1 : (value as string | number)
  }

  const response = await api.get<Paginated<Payment>>(
    `/participations/${participationUuid}/payments`,
    { params },
  )

  return response.data
}

export function fetchPayment(uuid: string): Promise<Payment> {
  return getData<Payment>(`/payments/${uuid}`)
}

/**
 * Annule un encaissement — par contre-passation, jamais par suppression.
 *
 * Le motif est obligatoire et sera lu en assemblée générale. « erreur »
 * n'explique rien ; le serveur exige dix caractères au minimum.
 */
export async function cancelPayment(
  uuid: string,
  reason: string,
): Promise<{ payment: Payment; line: ParticipationLine }> {
  const response = await api.post<{
    data: Payment
    meta: { line: ParticipationLine }
  }>(`/payments/${uuid}/cancel`, { reason })

  return { payment: response.data.data, line: response.data.meta.line }
}

/** Ce que JE dois et ce que J'AI payé. La seule route financière d'un membre. */
export async function fetchMyDues(): Promise<{ dues: MyDues; totals: MyDuesTotals }> {
  const response = await api.get<{ data: MyDues; meta: MyDuesTotals }>('/payments/mine')

  return { dues: response.data.data, totals: response.data.meta }
}

export interface CollectionsFilters {
  from?: string
  to?: string
}

/** Le contrôle : qui a encaissé combien, et combien d'annulations. */
export async function fetchCollections(filters: CollectionsFilters = {}): Promise<{
  collectors: CollectorTally[]
  totals: {
    from: string
    to: string
    total_amount: number
    total_count: number
    cancelled_amount: number
    cancelled_count: number
  }
}> {
  const response = await api.get<{
    data: CollectorTally[]
    meta: {
      from: string
      to: string
      total_amount: number
      total_count: number
      cancelled_amount: number
      cancelled_count: number
    }
  }>('/finance/collections', { params: filters })

  return { collectors: response.data.data, totals: response.data.meta }
}

export function fetchCashState(): Promise<CashState> {
  return getData<CashState>('/finance/cash')
}
