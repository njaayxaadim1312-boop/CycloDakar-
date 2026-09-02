import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlarmClock,
  Award,
  AlertTriangle,
  Bell,
  CalendarDays,
  CheckCircle,
  Receipt,
  Trophy,
  Wallet,
  XCircle,
  type LucideIcon,
} from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  fetchNotifications,
  fetchUnreadCount,
  markAllAsRead,
  markAsRead,
} from '@/lib/notifications'
import type { AppNotification } from '@/types/api'

/**
 * La cloche des notifications, dans l'en-tête.
 *
 * DEUX REQUÊTES, ET NON UNE.
 *
 * Le compteur est interrogé en continu, la liste seulement à l'ouverture du
 * panneau. Charger trente notifications toutes les minutes pour afficher une
 * pastille coûterait, sur un réseau mobile sénégalais, bien plus que le
 * confort qu'il apporte.
 *
 * Le rafraîchissement est d'une minute. Plus court donnerait l'illusion du
 * temps réel sans l'être — il faudrait une connexion permanente pour cela — et
 * viderait la batterie pour un gain que personne ne remarque.
 *
 * **Ouvrir une notification la marque lue et y mène.** Un panneau qui exige un
 * second geste pour marquer laisse s'accumuler des pastilles que plus personne
 * ne regarde.
 */
const ICONES: Record<string, LucideIcon> = {
  receipt: Receipt,
  wallet: Wallet,
  award: Award,
  trophy: Trophy,
  'calendar-days': CalendarDays,
  'alarm-clock': AlarmClock,
  'check-circle': CheckCircle,
  'x-circle': XCircle,
  'alert-triangle': AlertTriangle,
  bell: Bell,
}

export function NotificationBell() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const panneau = useRef<HTMLDivElement>(null)

  const [ouvert, setOuvert] = useState(false)

  const compteur = useQuery({
    queryKey: ['notifications-unread'],
    queryFn: fetchUnreadCount,
    // Une minute : au-delà on rate des choses, en deçà on épuise la batterie
    // pour simuler un temps réel qu'on n'a pas.
    refetchInterval: 60_000,
  })

  const liste = useQuery({
    queryKey: ['notifications'],
    queryFn: () => fetchNotifications({ per_page: 15 }),
    // Chargée seulement quand le panneau s'ouvre.
    enabled: ouvert,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    void queryClient.invalidateQueries({ queryKey: ['notifications-unread'] })
  }

  const lire = useMutation({ mutationFn: markAsRead, onSuccess: refresh })
  const toutLire = useMutation({ mutationFn: markAllAsRead, onSuccess: refresh })

  // Fermer au clic extérieur et à l'échappement : un panneau qui reste ouvert
  // derrière le contenu est le genre de détail qui fait paraître une
  // application inachevée.
  useEffect(() => {
    if (!ouvert) return

    function surClic(event: MouseEvent) {
      if (panneau.current !== null && !panneau.current.contains(event.target as Node)) {
        setOuvert(false)
      }
    }

    function surTouche(event: KeyboardEvent) {
      if (event.key === 'Escape') setOuvert(false)
    }

    document.addEventListener('mousedown', surClic)
    document.addEventListener('keydown', surTouche)

    return () => {
      document.removeEventListener('mousedown', surClic)
      document.removeEventListener('keydown', surTouche)
    }
  }, [ouvert])

  const nonLues = compteur.data?.unread ?? 0
  const notifications = liste.data?.notifications ?? []

  function ouvrir(notification: AppNotification) {
    if (!notification.read) lire.mutate(notification.id)

    setOuvert(false)

    if (notification.url !== null) navigate(notification.url)
  }

  return (
    <div ref={panneau} className="relative">
      <button
        type="button"
        onClick={() => setOuvert((etat) => !etat)}
        aria-label={
          nonLues > 0 ? `Notifications, ${nonLues} non lue(s)` : 'Notifications'
        }
        aria-expanded={ouvert}
        className="relative flex size-9 items-center justify-center rounded-full border border-[var(--cd-border)] text-[var(--cd-text-muted)] transition-colors hover:border-[var(--cd-orange)] hover:text-[var(--cd-text)]"
      >
        <Bell size={17} aria-hidden="true" />

        {nonLues > 0 && (
          <span className="absolute -top-1 -right-1 flex min-w-[18px] items-center justify-center rounded-full bg-[var(--cd-orange)] px-1 text-[10px] font-bold text-[var(--cd-black)] tabular-nums">
            {/* Au-delà de neuf, le chiffre exact n'apporte rien et déborde. */}
            {nonLues > 9 ? '9+' : nonLues}
          </span>
        )}
      </button>

      {ouvert && (
        <div className="cd-pop absolute right-0 z-50 mt-2 w-[min(92vw,22rem)] overflow-hidden rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] shadow-[var(--cd-shadow-lg)]">
          <div className="flex items-center justify-between gap-3 border-b border-[var(--cd-border)] p-4">
            <h2 className="text-sm font-semibold">Notifications</h2>

            {nonLues > 0 && (
              <button
                type="button"
                onClick={() => toutLire.mutate()}
                disabled={toutLire.isPending}
                className="text-xs font-medium text-[var(--cd-orange-text)] hover:underline disabled:opacity-60"
              >
                Tout marquer comme lu
              </button>
            )}
          </div>

          <div className="max-h-[min(70vh,26rem)] overflow-y-auto">
            {liste.isPending && (
              <p className="p-4 text-sm text-[var(--cd-text-muted)]">Chargement…</p>
            )}

            {!liste.isPending && notifications.length === 0 && (
              <p className="p-6 text-center text-sm text-[var(--cd-text-muted)]">
                Rien de neuf. Le club vous préviendra ici.
              </p>
            )}

            <ul className="divide-y divide-[var(--cd-border)]">
              {notifications.map((notification) => {
                const Icone = ICONES[notification.icon] ?? Bell

                return (
                  <li key={notification.id}>
                    <button
                      type="button"
                      onClick={() => ouvrir(notification)}
                      className={[
                        'flex w-full items-start gap-3 p-4 text-left transition-colors hover:bg-[var(--cd-bg)]',
                        notification.read ? '' : 'bg-[var(--cd-orange-soft)]',
                      ].join(' ')}
                    >
                      <Icone
                        size={16}
                        aria-hidden="true"
                        className={
                          notification.read
                            ? 'mt-0.5 shrink-0 text-[var(--cd-text-muted)]'
                            : 'mt-0.5 shrink-0 text-[var(--cd-orange-text)]'
                        }
                      />

                      <span className="min-w-0 flex-1">
                        <span
                          className={[
                            'block text-sm',
                            notification.read ? 'font-medium' : 'font-bold',
                          ].join(' ')}
                        >
                          {notification.title}
                        </span>
                        <span className="mt-0.5 block text-xs leading-relaxed text-[var(--cd-text-muted)]">
                          {notification.body}
                        </span>
                        {notification.created_at !== null && (
                          <span className="mt-1 block text-[11px] text-[var(--cd-text-muted)]">
                            {new Date(notification.created_at).toLocaleString('fr-FR', {
                              day: 'numeric',
                              month: 'short',
                              hour: '2-digit',
                              minute: '2-digit',
                            })}
                          </span>
                        )}
                      </span>
                    </button>
                  </li>
                )
              })}
            </ul>
          </div>
        </div>
      )}
    </div>
  )
}
