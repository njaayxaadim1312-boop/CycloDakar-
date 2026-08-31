import { getData } from '@/lib/api'
import type { DashboardStats, PersonalStats, StatsPeriod } from '@/types/api'

/** Statistiques du tableau de bord. */
export function fetchDashboardStats(): Promise<DashboardStats> {
  return getData<DashboardStats>('/stats/dashboard')
}

/**
 * Cumuls et records du membre connecté.
 *
 * Les cumuls suivent la période ; les records portent toujours sur toute la
 * carrière — c'est l'API qui garantit cette distinction, le client se contente
 * de l'afficher.
 */
export function fetchPersonalStats(period: StatsPeriod): Promise<PersonalStats> {
  return getData<PersonalStats>('/stats/me', { period })
}
