import { api, getData } from './api'
import type {
  AttendanceStatusCode,
  ClubEvent,
  EventParticipant,
  EventStatusCode,
  EventTally,
  Paginated,
  SportCode,
} from '../types/api'

/**
 * Accès à l'API des événements. Miroir de `web/src/lib/events.ts` : le web et le mobile
 * consomment la MÊME API, et le contrat ne doit diverger nulle part.
 *
 * Rappel du contrat : `planned_distance_m` est en mètres. La conversion en
 * kilomètres appartient à l'affichage — voir `format.ts`.
 */

export interface EventFilters {
  /** `upcoming` par défaut : un membre cherche la prochaine sortie. */
  scope?: 'upcoming' | 'past' | 'all'
  sport?: SportCode | ''
  status?: EventStatusCode | ''
  /** Restreint aux sorties où le membre connecté est inscrit. */
  mine?: boolean
  page?: number
  per_page?: number
}

function cleanParams(filters: EventFilters): Record<string, string | number> {
  const params: Record<string, string | number> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === undefined || value === null || value === false) {
      continue
    }

    params[key] = typeof value === 'boolean' ? 1 : (value as string | number)
  }

  return params
}

export async function fetchEvents(filters: EventFilters): Promise<Paginated<ClubEvent>> {
  const response = await api.get<Paginated<ClubEvent>>('/events', {
    params: cleanParams(filters),
  })

  return response.data
}

export function fetchEvent(uuid: string): Promise<ClubEvent> {
  return getData<ClubEvent>(`/events/${uuid}`)
}

export interface EventInput {
  title: string
  description?: string | null
  sport: SportCode
  starts_at: string
  ends_at?: string | null
  location_name: string
  planned_distance_m?: number | null
  difficulty?: string | null
  max_participants?: number | null
  status?: Extract<EventStatusCode, 'DRAFT' | 'PUBLISHED'>
}

export async function createEvent(values: EventInput): Promise<ClubEvent> {
  const response = await api.post<{ data: ClubEvent }>('/events', values)

  return response.data.data
}

export async function updateEvent(
  uuid: string,
  values: Partial<EventInput>,
): Promise<ClubEvent> {
  const response = await api.patch<{ data: ClubEvent }>(`/events/${uuid}`, values)

  return response.data.data
}

/**
 * Publier, démarrer, clore ou annuler.
 *
 * Route distincte de la modification : ce sont des actes soumis à des
 * transitions, pas des champs que l'on modifie au passage.
 */
export async function updateEventStatus(
  uuid: string,
  status: EventStatusCode,
): Promise<ClubEvent> {
  const response = await api.patch<{ data: ClubEvent }>(`/events/${uuid}/status`, { status })

  return response.data.data
}

export async function deleteEvent(uuid: string): Promise<void> {
  await api.delete(`/events/${uuid}`)
}

/* -------------------------------------------------------------------------- */
/* Inscriptions                                                               */
/* -------------------------------------------------------------------------- */

/** Réponse commune aux mouvements d'inscription : la ligne et les compteurs. */
interface ParticipationResult {
  participant: EventParticipant
  tally: EventTally
}

export async function registerToEvent(uuid: string): Promise<ParticipationResult> {
  const response = await api.post<{ data: EventParticipant; meta: EventTally }>(
    `/events/${uuid}/register`,
  )

  return { participant: response.data.data, tally: response.data.meta }
}

export async function cancelRegistration(uuid: string): Promise<ParticipationResult> {
  const response = await api.delete<{ data: EventParticipant; meta: EventTally }>(
    `/events/${uuid}/register`,
  )

  return { participant: response.data.data, tally: response.data.meta }
}

export async function fetchParticipants(uuid: string): Promise<{
  participants: EventParticipant[]
  tally: EventTally
}> {
  const response = await api.get<{ data: EventParticipant[]; meta: EventTally }>(
    `/events/${uuid}/participants`,
  )

  return { participants: response.data.data, tally: response.data.meta }
}

export async function markAttendance(
  uuid: string,
  member: string,
  status: AttendanceStatusCode,
): Promise<ParticipationResult> {
  const response = await api.post<{ data: EventParticipant; meta: EventTally }>(
    `/events/${uuid}/attendance`,
    { member, status },
  )

  return { participant: response.data.data, tally: response.data.meta }
}
