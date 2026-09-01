import { getData } from './api'
import type { DashboardStats, PersonalStats, StatsPeriod } from '../types/api'

/** Statistiques du tableau de bord. Miroir de `web/src/lib/stats.ts`. */
export function fetchDashboardStats(): Promise<DashboardStats> {
  return getData<DashboardStats>('/stats/dashboard')
}

/**
 * Cumuls et records du membre connecté.
 *
 * Les cumuls suivent la période demandée ; les records portent sur toute la
 * carrière. C'est l'API qui garantit cette distinction.
 */
export function fetchPersonalStats(period: StatsPeriod): Promise<PersonalStats> {
  return getData<PersonalStats>('/stats/me', { period })
}
