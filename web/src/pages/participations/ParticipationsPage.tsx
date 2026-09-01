import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertCircle, CalendarClock, Plus, TriangleAlert, Users } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { formatDate, formatFcfa } from '@/lib/format'
import { fetchParticipations, type ParticipationFilters } from '@/lib/participations'
import { useCurrentUser } from '@/stores/auth'
import type { Participation } from '@/types/api'

/**
 * Campagnes de collecte.
 *
 * L'écran répond à une seule question : **où en est l'argent ?** D'où trois
 * chiffres par ligne — attendu, encaissé, reste — et une barre de progression.
 *
 * Les montants viennent tous du serveur. Le client n'additionne rien : deux
 * clients qui calculeraient différemment afficheraient deux « restes à
 * collecter », et sur de l'argent c'est inacceptable.
 */
export function ParticipationsPage() {
  const [params, setParams] = useSearchParams()
  const user = useCurrentUser()

  const scope = params.get('scope') === 'all' ? 'all' : 'open'

  const filters: ParticipationFilters = {
    scope,
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  const query = useQuery({
    queryKey: ['participations', filters],
    queryFn: () => fetchParticipations(filters),
    placeholderData: keepPreviousData,
  })

  const canCreate = user?.abilities.manage_finance === true
  const items = query.data?.data ?? []

  return (
    <div className="space-y-5">
      <PageHeader
        title="Participations"
        description="Campagnes de collecte : montant attendu, échéance, suivi de l'encaissement."
        actions={
          canCreate ? (
            <Link
              to="/participations/nouvelle"
              className="inline-flex items-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)]"
            >
              <Plus size={16} aria-hidden="true" />
              Nouvelle collecte
            </Link>
          ) : undefined
        }
      />

      <div
        role="group"
        aria-label="Portée"
        className="inline-flex rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-1"
      >
        {[
          { value: 'open', label: 'En cours' },
          { value: 'all', label: 'Toutes' },
        ].map((entry) => (
          <button
            key={entry.value}
            type="button"
            onClick={() => {
              const next = new URLSearchParams(params)
              entry.value === 'open' ? next.delete('scope') : next.set('scope', entry.value)
              next.delete('page')
              setParams(next)
            }}
            aria-pressed={scope === entry.value}
            className={[
              'rounded-[var(--cd-radius-pill)] px-4 py-1.5 text-sm font-medium transition-colors',
              scope === entry.value
                ? 'bg-[var(--cd-orange)] text-[var(--cd-black)]'
                : 'text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]',
            ].join(' ')}
          >
            {entry.label}
          </button>
        ))}
      </div>

      {query.isLoading && (
        <p className="text-sm text-[var(--cd-text-muted)]">Chargement des collectes…</p>
      )}

      {query.error !== null && (
        <p className="flex items-center gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {query.error instanceof ApiError
            ? query.error.message
            : 'Impossible de charger les collectes.'}
        </p>
      )}

      {query.isSuccess && items.length === 0 && (
        <p className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center text-sm text-[var(--cd-text-muted)]">
          {scope === 'open'
            ? "Aucune collecte en cours."
            : "Aucune collecte n'a encore été créée."}
        </p>
      )}

      {items.length > 0 && (
        <div className={`cd-stagger space-y-3 ${query.isFetching ? 'opacity-60' : ''}`}>
          {items.map((participation) => (
            <ParticipationCard key={participation.uuid} participation={participation} />
          ))}
        </div>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

const STATUS_STYLE: Record<string, string> = {
  DRAFT: 'bg-[var(--cd-surface-2)] text-[var(--cd-text-muted)]',
  CLOSED: 'bg-[var(--cd-surface-2)] text-[var(--cd-text-muted)]',
  CANCELLED: 'bg-[var(--cd-danger-soft)] text-[var(--cd-danger)]',
}

function ParticipationCard({ participation }: { participation: Participation }) {
  const { tally } = participation

  return (
    <Link
      to={`/participations/${participation.uuid}`}
      className="cd-lift block rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-semibold">{participation.name}</h3>

            {/* On n'affiche le statut que lorsqu'il n'est PAS « ouverte » :
                le répéter sur chaque ligne n'apprend rien. */}
            {participation.status !== 'OPEN' && (
              <span
                className={[
                  'rounded-full px-2 py-0.5 text-xs font-semibold',
                  STATUS_STYLE[participation.status] ?? '',
                ].join(' ')}
              >
                {participation.status_label}
              </span>
            )}

            {participation.is_overdue && (
              <span className="inline-flex items-center gap-1 rounded-full bg-[var(--cd-warning-soft)] px-2 py-0.5 text-xs font-semibold text-[var(--cd-warning)]">
                <TriangleAlert size={12} aria-hidden="true" />
                Échéance dépassée
              </span>
            )}
          </div>

          <p className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-[var(--cd-text-muted)]">
            <span className="inline-flex items-center gap-1.5">
              <CalendarClock size={14} aria-hidden="true" />
              {participation.due_on !== null
                ? `Avant le ${formatDate(participation.due_on)}`
                : 'Sans échéance'}
            </span>
            <span className="inline-flex items-center gap-1.5 tabular-nums">
              <Users size={14} aria-hidden="true" />
              {tally.paid_members} / {tally.members} à jour
            </span>
            <span className="tabular-nums">
              {formatFcfa(participation.expected_amount)} par membre
            </span>
          </p>
        </div>

        <div className="text-right">
          <p className="text-2xl font-extrabold tabular-nums text-[var(--cd-orange-text)]">
            {formatFcfa(tally.collected_amount)}
          </p>
          <p className="text-xs text-[var(--cd-text-muted)] tabular-nums">
            sur {formatFcfa(tally.expected_amount)}
          </p>
        </div>
      </div>

      {/* Barre de progression : le chiffre est écrit juste au-dessus, elle
          n'est donc pas seule porteuse de l'information. */}
      <div
        className="mt-4 h-2 overflow-hidden rounded-full bg-[var(--cd-surface-2)]"
        role="presentation"
      >
        <div
          className="h-full rounded-full bg-[var(--cd-green)] transition-[width] duration-500"
          style={{ width: `${Math.min(100, tally.progress_percent)}%` }}
        />
      </div>

      <p className="mt-2 text-sm tabular-nums text-[var(--cd-text-muted)]">
        {tally.remaining_amount > 0 ? (
          <>
            Reste à collecter :{' '}
            <span className="font-semibold text-[var(--cd-text)]">
              {formatFcfa(tally.remaining_amount)}
            </span>
          </>
        ) : tally.members > 0 ? (
          <span className="font-semibold text-[var(--cd-green-hover)]">
            Collecte soldée
          </span>
        ) : (
          "Aucun membre n'est encore rattaché."
        )}
      </p>
    </Link>
  )
}
