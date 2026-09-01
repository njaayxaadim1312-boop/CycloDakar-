import { api, getData } from '@/lib/api'
import type {
  Paginated,
  Participation,
  ParticipationLine,
  ParticipationStatusCode,
} from '@/types/api'

/**
 * Accès à l'API des campagnes de collecte.
 *
 * **Tous les montants sont des entiers de FCFA**, à l'entrée comme à la
 * sortie. Il n'y a rien à convertir : le franc CFA n'a pas de subdivision en
 * usage. La seule mise en forme est `formatFcfa`, à l'affichage.
 *
 * Ce que ce client n'expose PAS est aussi important : ni `paid_amount`, ni
 * `status` de ligne. Ces champs sont dérivés des paiements réels côté serveur
 * et refusés en entrée — les proposer ici laisserait croire qu'on peut les
 * écrire.
 */

export interface ParticipationFilters {
  /** `open` par défaut : ce qui demande une action. */
  scope?: 'open' | 'all'
  status?: ParticipationStatusCode | ''
  page?: number
  per_page?: number
}

function cleanParams(filters: ParticipationFilters): Record<string, string | number> {
  const params: Record<string, string | number> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === undefined || value === null) continue

    params[key] = value as string | number
  }

  return params
}

export async function fetchParticipations(
  filters: ParticipationFilters,
): Promise<Paginated<Participation>> {
  const response = await api.get<Paginated<Participation>>('/participations', {
    params: cleanParams(filters),
  })

  return response.data
}

export function fetchParticipation(uuid: string): Promise<Participation> {
  return getData<Participation>(`/participations/${uuid}`)
}

export interface ParticipationInput {
  name: string
  description?: string | null
  /** En FCFA, entier. */
  expected_amount: number
  starts_on: string
  due_on: string
  event_id?: string | null
  status?: 'DRAFT' | 'OPEN'
}

export async function createParticipation(
  values: ParticipationInput,
): Promise<Participation> {
  const response = await api.post<{ data: Participation }>('/participations', values)

  return response.data.data
}

export async function updateParticipation(
  uuid: string,
  values: Partial<ParticipationInput>,
): Promise<Participation> {
  const response = await api.patch<{ data: Participation }>(`/participations/${uuid}`, values)

  return response.data.data
}

/** Ouvrir, clôturer, annuler — des actes, pas des champs. */
export async function updateParticipationStatus(
  uuid: string,
  status: ParticipationStatusCode,
): Promise<Participation> {
  const response = await api.patch<{ data: Participation }>(
    `/participations/${uuid}/status`,
    { status },
  )

  return response.data.data
}

export async function deleteParticipation(uuid: string): Promise<void> {
  await api.delete(`/participations/${uuid}`)
}

/* -------------------------------------------------------------------------- */
/* Affectation                                                                */
/* -------------------------------------------------------------------------- */

export interface AssignInput {
  /** Omis = tous les membres actifs. */
  members?: string[]
  /** Montant individualisé, en FCFA. */
  amount?: number
  collector?: string
}

export async function assignMembers(
  uuid: string,
  input: AssignInput = {},
): Promise<{ participation: Participation; created: number; skipped: number }> {
  const response = await api.post<{
    data: Participation
    meta: { created: number; skipped: number }
  }>(`/participations/${uuid}/members`, input)

  return {
    participation: response.data.data,
    created: response.data.meta.created,
    skipped: response.data.meta.skipped,
  }
}

export interface LineInput {
  expected_amount?: number
  collector?: string | null
  exempt?: boolean
  note?: string | null
}

export async function updateLine(
  uuid: string,
  lineId: number,
  values: LineInput,
): Promise<ParticipationLine> {
  const response = await api.patch<{ data: ParticipationLine }>(
    `/participations/${uuid}/members/${lineId}`,
    values,
  )

  return response.data.data
}

export async function removeLine(
  uuid: string,
  lineId: number,
): Promise<{ outcome: 'deleted' | 'cancelled'; message: string }> {
  const response = await api.delete<{
    data: { outcome: 'deleted' | 'cancelled'; message: string }
  }>(`/participations/${uuid}/members/${lineId}`)

  return response.data.data
}

/** Ce qu'un collecteur doit aller chercher, toutes collectes confondues. */
export async function fetchMyAssignments(): Promise<{
  lines: ParticipationLine[]
  count: number
  remaining_amount: number
}> {
  const response = await api.get<{
    data: ParticipationLine[]
    meta: { lines: number; remaining_amount: number }
  }>('/participations/mine')

  return {
    lines: response.data.data,
    count: response.data.meta.lines,
    remaining_amount: response.data.meta.remaining_amount,
  }
}
