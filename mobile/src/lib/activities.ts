import { api, getData } from './api'
import type { Activity, Paginated, SportCode } from '../types/api'

/**
 * Accès à l'API des activités. Miroir de `web/src/lib/activities.ts` : le web
 * et le mobile consomment la MÊME API, et le contrat ne doit diverger nulle
 * part.
 *
 * Rappel du contrat : toutes les grandeurs arrivent en **unités SI** (mètres,
 * secondes, m/s). La conversion appartient à l'affichage — voir `format.ts`.
 */

export interface ActivityFilters {
  sport?: SportCode | ''
  from?: string
  to?: string
  /** Restreint aux sorties de l'utilisateur connecté. */
  mine?: boolean
  member?: string
  page?: number
  per_page?: number
}

function cleanParams(filters: ActivityFilters): Record<string, string | number> {
  const params: Record<string, string | number> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === undefined || value === null || value === false) {
      continue
    }

    params[key] = typeof value === 'boolean' ? 1 : (value as string | number)
  }

  return params
}

export async function fetchActivities(
  filters: ActivityFilters,
): Promise<Paginated<Activity>> {
  const response = await api.get<Paginated<Activity>>('/activities', {
    params: cleanParams(filters),
  })

  return response.data
}

export function fetchActivity(uuid: string): Promise<Activity> {
  return getData<Activity>(`/activities/${uuid}`)
}

// Modification et suppression d'une sortie déjà transmise : REPORTÉES.
// Le membre les fait depuis le web (`web/src/lib/activities.ts`). Sur le
// téléphone, la seule édition qui existe aujourd'hui est celle du résumé,
// juste après la sortie, et elle passe par la base locale.
