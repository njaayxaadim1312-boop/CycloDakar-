import { api, getData } from '@/lib/api'
import type {
  Badge,
  Challenge,
  ChallengeInput,
  ChallengeMetricCode,
  ChallengeStanding,
  LeaderboardEntry,
  LeaderboardMeta,
  LeaderboardPeriod,
  SportCode,
} from '@/types/api'

/**
 * Accès à l'API des classements et des défis.
 *
 * **Les valeurs circulent en unité SI** — mètres, secondes, nombre de sorties.
 * La conversion vers les kilomètres se fait à l'affichage, comme pour les
 * activités : un champ qui contiendrait tantôt des mètres, tantôt des
 * kilomètres finirait par produire un défi mille fois trop court.
 *
 * Ce client ne décide RIEN de ce qui entre dans un classement : la règle « une
 * sortie privée ne classe jamais son auteur » vit côté serveur, en un seul
 * endroit. La rejouer ici en donnerait deux versions à tenir d'accord.
 */

export interface LeaderboardFilters {
  period?: LeaderboardPeriod
  metric?: ChallengeMetricCode
  sport?: SportCode | ''
  /** Une période passée : `2026-08`, `2026-W35`. */
  key?: string
}

export async function fetchLeaderboard(filters: LeaderboardFilters = {}): Promise<{
  entries: LeaderboardEntry[]
  meta: LeaderboardMeta
}> {
  const params: Record<string, string> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === undefined || value === null) continue

    params[key] = value as string
  }

  const response = await api.get<{ data: LeaderboardEntry[]; meta: LeaderboardMeta }>(
    '/leaderboard',
    { params },
  )

  return { entries: response.data.data, meta: response.data.meta }
}

/* -------------------------------------------------------------------- défis --- */

export type ChallengeScope = 'running' | 'upcoming' | 'past' | 'all'

export function fetchChallenges(scope: ChallengeScope = 'running'): Promise<Challenge[]> {
  return getData<Challenge[]>('/challenges', { scope })
}

export function fetchChallenge(uuid: string): Promise<Challenge> {
  return getData<Challenge>(`/challenges/${uuid}`)
}

export async function createChallenge(input: ChallengeInput): Promise<Challenge> {
  const response = await api.post<{ data: Challenge }>('/challenges', input)

  return response.data.data
}

/**
 * Rejoindre un défi.
 *
 * Un POST, pas un PUT sur une ressource « participation » : c'est un ACTE. Et
 * la réponse renvoie le défi complet, progression comprise — un membre qui
 * roulait déjà voit sa barre remplie immédiatement, sans second appel.
 */
export async function joinChallenge(uuid: string): Promise<Challenge> {
  const response = await api.post<{ data: Challenge }>(`/challenges/${uuid}/join`)

  return response.data.data
}

/** Quitter — refusé si le défi est déjà réussi : le badge reste acquis. */
export async function leaveChallenge(uuid: string): Promise<Challenge> {
  const response = await api.post<{ data: Challenge }>(`/challenges/${uuid}/leave`)

  return response.data.data
}

export async function fetchStandings(uuid: string): Promise<{
  standings: ChallengeStanding[]
  meta: { target: number; unit: string; participants: number }
}> {
  const response = await api.get<{
    data: ChallengeStanding[]
    meta: { target: number; unit: string; participants: number }
  }>(`/challenges/${uuid}/standings`)

  return { standings: response.data.data, meta: response.data.meta }
}

/** Les défis réussis du membre connecté. */
export function fetchBadges(): Promise<Badge[]> {
  return getData<Badge[]>('/challenges/badges')
}

/* ------------------------------------------------------------- affichage --- */

/**
 * Met une valeur de classement dans son unité lisible.
 *
 * Le serveur envoie des mètres et des secondes ; personne ne lit « 148 200 m ».
 * La conversion est ici, à la frontière de l'affichage — jamais dans le
 * transport, où elle créerait deux vérités.
 */
export function formatMetric(value: number, metric: ChallengeMetricCode): string {
  switch (metric) {
    case 'distance':
      return `${(value / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} km`
    case 'elevation':
      return `${Math.round(value).toLocaleString('fr-FR')} m D+`
    case 'duration': {
      const heures = Math.floor(value / 3600)
      const minutes = Math.round((value % 3600) / 60)

      return heures > 0 ? `${heures} h ${String(minutes).padStart(2, '0')}` : `${minutes} min`
    }
    default:
      return `${value} sortie${value > 1 ? 's' : ''}`
  }
}
