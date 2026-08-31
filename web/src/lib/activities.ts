import { api, getData } from '@/lib/api'
import type { Activity, Paginated, SportCode } from '@/types/api'

/**
 * Accès à l'API des activités.
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

export async function updateActivity(
  uuid: string,
  values: { title?: string | null; notes?: string | null; visibility?: string },
): Promise<Activity> {
  const response = await api.patch<{ data: Activity }>(`/activities/${uuid}`, values)

  return response.data.data
}

export async function deleteActivity(uuid: string): Promise<void> {
  await api.delete(`/activities/${uuid}`)
}
