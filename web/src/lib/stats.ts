import { getData } from '@/lib/api'
import type { DashboardStats } from '@/types/api'

/** Statistiques du tableau de bord. */
export function fetchDashboardStats(): Promise<DashboardStats> {
  return getData<DashboardStats>('/stats/dashboard')
}
