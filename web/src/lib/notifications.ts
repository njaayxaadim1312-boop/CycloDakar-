import { api, getData } from '@/lib/api'
import type { AppNotification, NotificationsMeta } from '@/types/api'

/**
 * Accès à l'API des notifications.
 *
 * **Tout est personnel.** Aucune fonction ne prend d'identifiant
 * d'utilisateur : on ne lit et on ne marque que les siennes, celles de la
 * session. Le serveur ne l'accepterait pas autrement — une notification porte
 * un montant, une dette, une décision financière.
 */

export interface NotificationFilters {
  unread?: boolean
  page?: number
  per_page?: number
}

export async function fetchNotifications(filters: NotificationFilters = {}): Promise<{
  notifications: AppNotification[]
  meta: NotificationsMeta
}> {
  const params: Record<string, string | number> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === null || value === false) continue

    params[key] = value === true ? 1 : (value as string | number)
  }

  const response = await api.get<{ data: AppNotification[]; meta: NotificationsMeta }>(
    '/notifications',
    { params },
  )

  return { notifications: response.data.data, meta: response.data.meta }
}

/**
 * Le seul chiffre dont l'interface a besoin en continu : la pastille.
 *
 * Route séparée de la liste, et c'est délibéré : charger trente notifications
 * pour afficher un nombre serait absurde, surtout sur un réseau mobile.
 */
export function fetchUnreadCount(): Promise<{ unread: number }> {
  return getData<{ unread: number }>('/notifications/unread-count')
}

export async function markAsRead(id: string): Promise<AppNotification> {
  const response = await api.post<{ data: AppNotification }>(`/notifications/${id}/read`)

  return response.data.data
}

export async function markAllAsRead(): Promise<{ marked: number; unread: number }> {
  const response = await api.post<{ data: { marked: number; unread: number } }>(
    '/notifications/read-all',
  )

  return response.data.data
}
