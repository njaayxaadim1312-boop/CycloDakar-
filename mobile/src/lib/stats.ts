import { getData } from './api'
import type { DashboardStats } from '../types/api'

/** Statistiques du tableau de bord. Miroir de `web/src/lib/stats.ts`. */
export function fetchDashboardStats(): Promise<DashboardStats> {
  return getData<DashboardStats>('/stats/dashboard')
}
