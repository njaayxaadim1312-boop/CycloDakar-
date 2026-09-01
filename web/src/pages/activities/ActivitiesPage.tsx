import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertCircle, Route } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { Avatar } from '@/components/ui/Avatar'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { ApiError } from '@/lib/api'
import { fetchActivities, type ActivityFilters } from '@/lib/activities'
import { formatDate, formatDistance, formatDurationLong, formatSpeed } from '@/lib/format'
import { SPORT_COLOR, SPORT_FILTERS, SPORT_ICON } from '@/lib/sports'
import type { Activity, SportCode } from '@/types/api'

/**
 * Historique des sorties du club.
 *
 * Aucune carte dans la liste : vingt cartes Leaflet sur une page
 * consommeraient des centaines de requêtes de tuiles et rendraient le
 * défilement saccadé. La trace s'affiche sur la fiche de détail, une à la fois.
 */
export function ActivitiesPage() {
  const [params, setParams] = useSearchParams()

  const filters: ActivityFilters = {
    sport: (params.get('sport') as SportCode | null) ?? '',
    mine: params.get('mine') === '1',
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  const query = useQuery({
    queryKey: ['activities', filters],
    queryFn: () => fetchActivities(filters),
    placeholderData: keepPreviousData,
  })

  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(params)
    value ? next.set(key, value) : next.delete(key)
    next.delete('page')
    setParams(next)
  }

  const activities = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <div className="space-y-5">
      <PageHeader
        title="Activités"
        description="Les sorties enregistrées au GPS par les membres du club."
      />

      {/* --- Filtres ------------------------------------------------------ */}
      <div className="cd-card flex flex-wrap items-center gap-3 p-4">
        <select
          value={filters.sport}
          onChange={(e) => setFilter('sport', e.target.value)}
          aria-label="Filtrer par sport"
          className="rounded-[var(--cd-radius-sm)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] px-3 py-2 text-sm outline-none focus:border-[var(--cd-orange)]"
        >
          {SPORT_FILTERS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
          <input
            type="checkbox"
            checked={filters.mine}
            onChange={(e) => setFilter('mine', e.target.checked ? '1' : '')}
            className="size-4 accent-[var(--cd-orange)]"
          />
          Mes sorties uniquement
        </label>
      </div>

      {/* --- Résultats ---------------------------------------------------- */}
      {query.isError && (
        <div className="cd-card flex items-start gap-3 p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={18} className="mt-0.5 shrink-0" />
          <span>
            {query.error instanceof ApiError
              ? query.error.message
              : "L'historique n'a pas pu être chargé."}
          </span>
        </div>
      )}

      {query.isLoading && (
        <div className="cd-card divide-y divide-[var(--cd-border)]">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="flex animate-pulse items-center gap-3 p-4">
              <span className="size-11 rounded-full bg-[var(--cd-surface-2)]" />
              <span className="h-4 w-52 rounded bg-[var(--cd-surface-2)]" />
            </div>
          ))}
        </div>
      )}

      {query.isSuccess && activities.length === 0 && (
        <div className="cd-card p-10 text-center">
          <Route size={32} className="mx-auto text-[var(--cd-text-muted)]" />
          <p className="mt-3 font-semibold">Aucune sortie enregistrée</p>
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            Les sorties apparaissent ici dès qu'un membre en enregistre une depuis
            l'application mobile.
          </p>
        </div>
      )}

      {activities.length > 0 && (
        <>
          <div className="cd-card divide-y divide-[var(--cd-border)] overflow-hidden">
            {activities.map((activity) => (
              <ActivityRow key={activity.uuid} activity={activity} />
            ))}
          </div>

          {meta && (
            <Pagination
              currentPage={meta.current_page}
              lastPage={meta.last_page}
              total={meta.total}
              perPage={meta.per_page}
              onChange={(page) => {
                const next = new URLSearchParams(params)
                next.set('page', String(page))
                setParams(next)
                window.scrollTo({ top: 0, behavior: 'smooth' })
              }}
            />
          )}
        </>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function ActivityRow({ activity }: { activity: Activity }) {
  // La table couvre TOUS les sports (Record<SportCode, …>) : plus de
  // repli, qui masquait un sport oublié derrière une icône de vélo.
  const Icon = SPORT_ICON[activity.sport]

  return (
    <Link
      to={`/activities/${activity.uuid}`}
      className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--cd-orange-soft)] sm:gap-4"
    >
      <span
        className="flex size-11 shrink-0 items-center justify-center rounded-full text-white"
        style={{ backgroundColor: SPORT_COLOR[activity.sport] }}
      >
        <Icon size={21} />
      </span>

      <div className="min-w-0 flex-1">
        <p className="truncate font-semibold">{activity.title}</p>
        <p className="truncate text-sm text-[var(--cd-text-muted)]">
          {activity.started_at && formatDate(activity.started_at)}
          {activity.zones.length > 0 && ` · ${activity.zones.join(' · ')}`}
        </p>
      </div>

      <div className="flex shrink-0 items-center gap-4 sm:gap-6">
        <Figure label="Distance" value={formatDistance(activity.distance_m)} />
        <span className="hidden sm:block">
          <Figure label="Durée" value={formatDurationLong(activity.moving_time_s)} />
        </span>
        <span className="hidden md:block">
          <Figure
            label={activity.uses_pace ? 'Allure' : 'Moyenne'}
            value={formatSpeed(activity.avg_speed_mps)}
          />
        </span>

        {activity.member && (
          <Avatar
            photoUrl={activity.member.photo_url}
            initials={activity.member.initials}
            size={32}
          />
        )}
      </div>
    </Link>
  )
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <span className="block text-right">
      <span className="tabular block font-bold">{value}</span>
      <span className="block text-[0.6875rem] text-[var(--cd-text-muted)] uppercase">
        {label}
      </span>
    </span>
  )
}
