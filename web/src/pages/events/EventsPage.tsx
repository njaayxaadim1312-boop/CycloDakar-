import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertCircle, CalendarPlus } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { EventCard } from '@/components/events/EventCard'
import { PageHeader } from '@/components/ui/PageHeader'
import { Pagination } from '@/components/ui/Pagination'
import { ApiError } from '@/lib/api'
import { fetchEvents, type EventFilters } from '@/lib/events'
import { useCurrentUser } from '@/stores/auth'
import type { SportCode } from '@/types/api'

const SPORTS: { value: SportCode | ''; label: string }[] = [
  { value: '', label: 'Tous les sports' },
  { value: 'CYCLING', label: 'Cyclisme' },
  { value: 'RUNNING', label: 'Course' },
  { value: 'HIKING', label: 'Randonnée' },
]

const SCOPES: { value: 'upcoming' | 'past'; label: string }[] = [
  { value: 'upcoming', label: 'À venir' },
  { value: 'past', label: 'Passées' },
]

/**
 * Calendrier des sorties du club.
 *
 * La vue par défaut est **à venir** : un membre qui ouvre cet écran cherche la
 * prochaine sortie, pas celle de mars. L'historique reste à un clic.
 *
 * Les filtres vivent dans l'URL : un membre qui envoie « regarde les sorties
 * de course » à un camarade doit lui envoyer la bonne vue.
 */
export function EventsPage() {
  const [params, setParams] = useSearchParams()
  const user = useCurrentUser()

  const scope = params.get('scope') === 'past' ? 'past' : 'upcoming'

  const filters: EventFilters = {
    scope,
    sport: (params.get('sport') as SportCode | null) ?? '',
    mine: params.get('mine') === '1',
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  const query = useQuery({
    queryKey: ['events', filters],
    queryFn: () => fetchEvents(filters),
    placeholderData: keepPreviousData,
  })

  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(params)
    value === '' ? next.delete(key) : next.set(key, value)
    next.delete('page')
    setParams(next)
  }

  const events = query.data?.data ?? []
  const meta = query.data?.meta
  // `lead_rides` et non `collect` : planifier une sortie ne demande pas
  // d'approcher l'argent du club. C'est tout l'intérêt du rôle de chef de
  // groupe — voir `UserRole` côté serveur.
  const canCreate = user?.abilities.lead_rides === true

  return (
    <div className="space-y-5">
      <PageHeader
        title="Événements"
        description="Les sorties officielles du club : parcours, inscriptions et présences."
        actions={
          canCreate ? (
            <Link
              to="/events/nouveau"
              className="inline-flex items-center gap-2 rounded-full bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)]"
            >
              <CalendarPlus size={16} aria-hidden="true" />
              Nouvelle sortie
            </Link>
          ) : undefined
        }
      />

      {/* --- Filtres ------------------------------------------------------ */}
      <div className="flex flex-wrap items-center gap-3">
        <div
          role="group"
          aria-label="Période"
          className="inline-flex rounded-full border border-[var(--cd-border)] bg-[var(--cd-surface)] p-1"
        >
          {SCOPES.map((entry) => (
            <button
              key={entry.value}
              type="button"
              onClick={() => setFilter('scope', entry.value === 'upcoming' ? '' : entry.value)}
              aria-pressed={scope === entry.value}
              className={[
                'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                scope === entry.value
                  ? 'bg-[var(--cd-orange)] text-[var(--cd-black)]'
                  : 'text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]',
              ].join(' ')}
            >
              {entry.label}
            </button>
          ))}
        </div>

        <select
          value={filters.sport}
          onChange={(event) => setFilter('sport', event.target.value)}
          aria-label="Sport"
          className="rounded-full border border-[var(--cd-border)] bg-[var(--cd-surface)] px-4 py-2 text-sm"
        >
          {SPORTS.map((sport) => (
            <option key={sport.value} value={sport.value}>
              {sport.label}
            </option>
          ))}
        </select>

        <label className="inline-flex items-center gap-2 text-sm text-[var(--cd-text-muted)]">
          <input
            type="checkbox"
            checked={filters.mine === true}
            onChange={(event) => setFilter('mine', event.target.checked ? '1' : '')}
            className="size-4 accent-[var(--cd-orange)]"
          />
          Mes inscriptions
        </label>
      </div>

      {/* --- Liste -------------------------------------------------------- */}
      {query.isLoading && (
        <p className="text-sm text-[var(--cd-text-muted)]">Chargement du calendrier…</p>
      )}

      {query.error !== null && (
        <p className="flex items-center gap-2 rounded-2xl border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {query.error instanceof ApiError
            ? query.error.message
            : 'Impossible de charger les événements.'}
        </p>
      )}

      {query.isSuccess && events.length === 0 && (
        <p className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center text-sm text-[var(--cd-text-muted)]">
          {scope === 'past'
            ? "Aucune sortie passée n'est enregistrée."
            : "Aucune sortie n'est prévue pour le moment."}
        </p>
      )}

      {events.length > 0 && (
        <div className={`space-y-3 ${query.isFetching ? 'opacity-60' : ''}`}>
          {events.map((event) => (
            <EventCard key={event.uuid} event={event} />
          ))}
        </div>
      )}

      {meta !== undefined && meta.last_page > 1 && (
        <Pagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          perPage={meta.per_page}
          onChange={(page) => {
            const next = new URLSearchParams(params)
            next.set('page', String(page))
            setParams(next)
          }}
        />
      )}
    </div>
  )
}
