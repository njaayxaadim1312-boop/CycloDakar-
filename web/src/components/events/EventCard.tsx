import { Bike, Footprints, MapPin, Mountain, Route, Users } from 'lucide-react'
import { Link } from 'react-router-dom'
import { formatDateTime, formatDistance } from '@/lib/format'
import type { ClubEvent, SportCode } from '@/types/api'

const SPORT_ICON: Record<SportCode, typeof Bike> = {
  CYCLING: Bike,
  RUNNING: Footprints,
  HIKING: Mountain,
}

const SPORT_COLOR: Record<SportCode, string> = {
  CYCLING: 'var(--cd-sport-cycling)',
  RUNNING: 'var(--cd-sport-running)',
  HIKING: 'var(--cd-sport-hiking)',
}

interface EventCardProps {
  event: ClubEvent
}

/**
 * Une sortie dans la liste.
 *
 * L'information hiérarchisée telle qu'un membre la cherche : quand, où,
 * combien de kilomètres, et suis-je inscrit. Le reste attend la fiche.
 *
 * Le statut n'apparaît que lorsqu'il n'est PAS « annoncé » : sur une liste de
 * sorties à venir, répéter « Annoncé » douze fois n'apprend rien, alors qu'un
 * « Annulé » ou un « Brouillon » doit sauter aux yeux.
 */
export function EventCard({ event }: EventCardProps) {
  const Icon = SPORT_ICON[event.sport]
  const cancelled = event.status === 'CANCELLED'

  return (
    <Link
      to={`/events/${event.uuid}`}
      className="block rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4 transition-colors hover:border-[var(--cd-orange)]"
    >
      <div className="flex items-start gap-3">
        <span
          className="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl"
          style={{ background: `color-mix(in srgb, ${SPORT_COLOR[event.sport]} 16%, transparent)` }}
        >
          <Icon size={20} style={{ color: SPORT_COLOR[event.sport] }} aria-hidden="true" />
        </span>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <h3
              className={[
                'truncate font-semibold',
                cancelled ? 'text-[var(--cd-text-muted)] line-through' : 'text-[var(--cd-text)]',
              ].join(' ')}
            >
              {event.title}
            </h3>

            {event.status !== 'PUBLISHED' && (
              <StatusPill status={event.status} label={event.status_label} />
            )}
          </div>

          <p className="mt-0.5 text-sm text-[var(--cd-text-muted)]">
            {event.starts_at !== null ? formatDateTime(event.starts_at) : 'Date à préciser'}
          </p>

          <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-[var(--cd-text-muted)]">
            <span className="inline-flex items-center gap-1.5">
              <MapPin size={14} aria-hidden="true" />
              {event.location_name}
            </span>

            {event.planned_distance_m !== null && (
              <span className="inline-flex items-center gap-1.5 tabular-nums">
                <Route size={14} aria-hidden="true" />
                {formatDistance(event.planned_distance_m)}
              </span>
            )}

            <Seats event={event} />

            {event.difficulty_label !== null && (
              <span title={event.difficulty_hint ?? undefined}>{event.difficulty_label}</span>
            )}
          </div>
        </div>

        {event.my_registration !== null && (
          <RegistrationBadge registration={event.my_registration} />
        )}
      </div>
    </Link>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * Places.
 *
 * Une sortie non limitée n'affiche pas de compteur de places restantes —
 * seulement le nombre d'inscrits. Écrire « places illimitées » occuperait la
 * ligne sans rien apprendre.
 */
function Seats({ event }: { event: ClubEvent }) {
  if (event.max_participants === null) {
    return (
      <span className="inline-flex items-center gap-1.5 tabular-nums">
        <Users size={14} aria-hidden="true" />
        {event.seats_taken} inscrit{event.seats_taken > 1 ? 's' : ''}
      </span>
    )
  }

  return (
    <span
      className={[
        'inline-flex items-center gap-1.5 tabular-nums',
        event.is_full ? 'font-medium text-[var(--cd-warning)]' : '',
      ].join(' ')}
    >
      <Users size={14} aria-hidden="true" />
      {event.seats_taken} / {event.max_participants}
      {event.is_full && ' · complet'}
    </span>
  )
}

function RegistrationBadge({
  registration,
}: {
  registration: NonNullable<ClubEvent['my_registration']>
}) {
  const waiting = registration.status === 'WAITLIST'

  return (
    <span
      className={[
        'shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold',
        waiting
          ? 'bg-[var(--cd-warning-soft)] text-[var(--cd-warning)]'
          : 'bg-[var(--cd-success-soft)] text-[var(--cd-green-hover)]',
      ].join(' ')}
      title={
        waiting && registration.queue_position !== null
          ? `${registration.queue_position}ᵉ sur la liste d'attente`
          : undefined
      }
    >
      {waiting && registration.queue_position !== null
        ? `Attente ${registration.queue_position}`
        : 'Inscrit'}
    </span>
  )
}

const STATUS_STYLE: Record<string, string> = {
  DRAFT: 'bg-[var(--cd-surface-2)] text-[var(--cd-text-muted)]',
  ONGOING: 'bg-[var(--cd-success-soft)] text-[var(--cd-green-hover)]',
  DONE: 'bg-[var(--cd-surface-2)] text-[var(--cd-text-muted)]',
  CANCELLED: 'bg-[var(--cd-danger-soft)] text-[var(--cd-danger)]',
}

export function StatusPill({ status, label }: { status: string; label: string }) {
  return (
    <span
      className={[
        'shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold',
        STATUS_STYLE[status] ?? 'bg-[var(--cd-surface-2)] text-[var(--cd-text-muted)]',
      ].join(' ')}
    >
      {label}
    </span>
  )
}
