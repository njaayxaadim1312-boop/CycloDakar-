import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertCircle,
  ArrowLeft,
  CalendarClock,
  Check,
  MapPin,
  Route,
  Users,
  X,
} from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { StatusPill } from '@/components/events/EventCard'
import { Avatar } from '@/components/ui/Avatar'
import { ApiError } from '@/lib/api'
import {
  cancelRegistration,
  fetchEvent,
  markAttendance,
  registerToEvent,
  updateEventStatus,
} from '@/lib/events'
import { formatDateTime, formatDistance } from '@/lib/format'
import type { AttendanceStatusCode, ClubEvent, EventStatusCode } from '@/types/api'

/**
 * Fiche d'une sortie.
 *
 * L'écran répond dans l'ordre aux questions d'un membre : quand et où, est-ce
 * que je viens, et qui d'autre vient. Le bureau y ajoute deux outils qui ne
 * concernent que lui : le cycle de vie de la sortie et le pointage.
 *
 * Toutes les actions invalident la requête plutôt que de modifier l'état local :
 * les places se comptent côté serveur, et un compteur recalculé dans le
 * navigateur divergerait dès qu'un autre membre s'inscrit en même temps.
 */
export function EventDetailPage() {
  const { uuid = '' } = useParams()
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['event', uuid],
    queryFn: () => fetchEvent(uuid),
  })

  const event = query.data

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['event', uuid] })
    void queryClient.invalidateQueries({ queryKey: ['events'] })
    void queryClient.invalidateQueries({ queryKey: ['stats', 'dashboard'] })
  }

  const register = useMutation({
    mutationFn: () => registerToEvent(uuid),
    onSuccess: refresh,
  })

  const unregister = useMutation({
    mutationFn: () => cancelRegistration(uuid),
    onSuccess: refresh,
  })

  const changeStatus = useMutation({
    mutationFn: (status: EventStatusCode) => updateEventStatus(uuid, status),
    onSuccess: refresh,
  })

  const attendance = useMutation({
    mutationFn: (input: { member: string; status: AttendanceStatusCode }) =>
      markAttendance(uuid, input.member, input.status),
    onSuccess: refresh,
  })

  if (query.isLoading) {
    return <p className="text-sm text-[var(--cd-text-muted)]">Chargement de la sortie…</p>
  }

  if (query.error !== null || event === undefined) {
    return (
      <div className="space-y-4">
        <BackLink />
        <p className="flex items-center gap-2 rounded-2xl border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {query.error instanceof ApiError
            ? query.error.message
            : 'Cette sortie est introuvable.'}
        </p>
      </div>
    )
  }

  const mutationError = [register.error, unregister.error, changeStatus.error, attendance.error]
    .find((error): error is ApiError => error instanceof ApiError)

  return (
    <div className="space-y-5">
      <BackLink />

      {/* --- En-tête ------------------------------------------------------ */}
      <header className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-2xl">{event.title}</h1>
              {event.status !== 'PUBLISHED' && (
                <StatusPill status={event.status} label={event.status_label} />
              )}
            </div>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">{event.sport_label}</p>
          </div>

          {event.permissions?.update === true && (
            <Link
              to={`/events/${event.uuid}/modifier`}
              className="rounded-full border border-[var(--cd-border)] px-4 py-2 text-sm font-medium hover:border-[var(--cd-orange)]"
            >
              Modifier
            </Link>
          )}
        </div>

        <dl className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Detail icon={CalendarClock} label="Départ">
            {event.starts_at !== null ? formatDateTime(event.starts_at) : 'À préciser'}
          </Detail>
          <Detail icon={MapPin} label="Lieu">
            {event.location_name}
          </Detail>
          <Detail icon={Route} label="Distance prévue">
            {event.planned_distance_m !== null
              ? formatDistance(event.planned_distance_m)
              : '—'}
          </Detail>
          <Detail icon={Users} label="Inscrits">
            {event.max_participants !== null
              ? `${event.seats_taken} / ${event.max_participants}`
              : `${event.seats_taken}`}
          </Detail>
        </dl>

        {event.difficulty_label !== null && (
          <p className="mt-4 text-sm text-[var(--cd-text-muted)]">
            <span className="font-medium text-[var(--cd-text)]">{event.difficulty_label}</span>
            {event.difficulty_hint !== null && ` — ${event.difficulty_hint}`}
          </p>
        )}

        {event.description !== null && event.description !== '' && (
          <p className="mt-4 text-sm leading-relaxed whitespace-pre-line">{event.description}</p>
        )}
      </header>

      {mutationError !== undefined && (
        <p className="flex items-center gap-2 rounded-2xl border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {mutationError.message}
        </p>
      )}

      {/* --- Ma participation --------------------------------------------- */}
      <RegistrationPanel
        event={event}
        busy={register.isPending || unregister.isPending}
        onRegister={() => register.mutate()}
        onCancel={() => unregister.mutate()}
      />

      {/* --- Cycle de vie, réservé au bureau ------------------------------ */}
      {event.permissions?.update === true && (
        <LifecyclePanel
          event={event}
          busy={changeStatus.isPending}
          onChange={(status) => changeStatus.mutate(status)}
        />
      )}

      {/* --- Participants -------------------------------------------------- */}
      <ParticipantsPanel
        event={event}
        busy={attendance.isPending}
        onAttendance={(member, status) => attendance.mutate({ member, status })}
      />
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function BackLink() {
  return (
    <Link
      to="/events"
      className="inline-flex items-center gap-1.5 text-sm text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]"
    >
      <ArrowLeft size={15} aria-hidden="true" />
      Tous les événements
    </Link>
  )
}

function Detail({
  icon: Icon,
  label,
  children,
}: {
  icon: typeof MapPin
  label: string
  children: React.ReactNode
}) {
  return (
    <div>
      <dt className="flex items-center gap-1.5 text-xs text-[var(--cd-text-muted)]">
        <Icon size={14} aria-hidden="true" />
        {label}
      </dt>
      <dd className="mt-1 font-medium tabular-nums">{children}</dd>
    </div>
  )
}

/**
 * « Je participe » / « Je me désiste ».
 *
 * Le bouton dit ce qui va se passer, y compris quand la sortie est pleine :
 * « Rejoindre la liste d'attente » plutôt que « Je participe », pour qu'un
 * membre ne découvre pas après coup qu'il n'a pas de place.
 */
function RegistrationPanel({
  event,
  busy,
  onRegister,
  onCancel,
}: {
  event: ClubEvent
  busy: boolean
  onRegister: () => void
  onCancel: () => void
}) {
  if (!event.registrations_open && event.my_registration === null) {
    return (
      <p className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5 text-sm text-[var(--cd-text-muted)]">
        Les inscriptions sont fermées pour cette sortie.
      </p>
    )
  }

  const mine = event.my_registration

  return (
    <section className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <div>
        {mine === null ? (
          <p className="text-sm text-[var(--cd-text-muted)]">
            {event.is_full
              ? 'Cette sortie est complète. Vous pouvez rejoindre la liste d’attente : une place se libère souvent.'
              : 'Vous n’êtes pas encore inscrit à cette sortie.'}
          </p>
        ) : mine.status === 'WAITLIST' ? (
          <p className="text-sm">
            Vous êtes{' '}
            <span className="font-semibold text-[var(--cd-warning)]">
              {mine.queue_position}
              <sup>e</sup> sur la liste d’attente
            </span>
            . Vous serez inscrit dès qu’une place se libère.
          </p>
        ) : (
          <p className="text-sm font-semibold text-[var(--cd-green-hover)]">
            Vous êtes inscrit à cette sortie.
          </p>
        )}
      </div>

      {mine === null ? (
        <button
          type="button"
          onClick={onRegister}
          disabled={busy || !event.registrations_open}
          className="rounded-full bg-[var(--cd-orange)] px-5 py-2.5 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-60"
        >
          {event.is_full ? 'Rejoindre la liste d’attente' : 'Je participe'}
        </button>
      ) : (
        <button
          type="button"
          onClick={onCancel}
          disabled={busy}
          className="rounded-full border border-[var(--cd-border)] px-5 py-2.5 text-sm font-medium transition-colors hover:border-[var(--cd-danger)] hover:text-[var(--cd-danger)] disabled:opacity-60"
        >
          Je me désiste
        </button>
      )}
    </section>
  )
}

/** Transitions autorisées, en miroir de `EventStatus::allowedTransitions`. */
const TRANSITIONS: Record<EventStatusCode, { status: EventStatusCode; label: string }[]> = {
  DRAFT: [
    { status: 'PUBLISHED', label: 'Annoncer au club' },
    { status: 'CANCELLED', label: 'Annuler' },
  ],
  PUBLISHED: [
    { status: 'ONGOING', label: 'Démarrer' },
    { status: 'DONE', label: 'Clore' },
    { status: 'CANCELLED', label: 'Annuler' },
  ],
  ONGOING: [
    { status: 'DONE', label: 'Clore' },
    { status: 'CANCELLED', label: 'Annuler' },
  ],
  DONE: [],
  CANCELLED: [],
}

function LifecyclePanel({
  event,
  busy,
  onChange,
}: {
  event: ClubEvent
  busy: boolean
  onChange: (status: EventStatusCode) => void
}) {
  const options = TRANSITIONS[event.status]

  if (options.length === 0) {
    return null
  }

  return (
    <section className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="text-sm font-semibold">Organisation</h2>
      <p className="mt-1 text-xs text-[var(--cd-text-muted)]">
        {event.status === 'DRAFT'
          ? "Ce brouillon n'est visible que de vous. Les membres le verront une fois annoncé."
          : 'Une sortie annoncée ne redevient pas un brouillon : elle s’annule, ce qui prévient les inscrits.'}
      </p>

      <div className="mt-4 flex flex-wrap gap-2">
        {options.map((option) => (
          <button
            key={option.status}
            type="button"
            onClick={() => onChange(option.status)}
            disabled={busy}
            className={[
              'rounded-full px-4 py-2 text-sm font-medium transition-colors disabled:opacity-60',
              option.status === 'CANCELLED'
                ? 'border border-[var(--cd-border)] hover:border-[var(--cd-danger)] hover:text-[var(--cd-danger)]'
                : 'bg-[var(--cd-orange)] text-[var(--cd-black)] hover:bg-[var(--cd-orange-hover)]',
            ].join(' ')}
          >
            {option.label}
          </button>
        ))}
      </div>
    </section>
  )
}

/**
 * Liste des inscrits, et pointage pour ceux qui y sont habilités.
 *
 * Les inscrits et la liste d'attente sont séparés visuellement : confondre
 * « a une place » et « attend une place » est précisément ce qui fait qu'un
 * membre se déplace pour rien.
 */
function ParticipantsPanel({
  event,
  busy,
  onAttendance,
}: {
  event: ClubEvent
  busy: boolean
  onAttendance: (member: string, status: AttendanceStatusCode) => void
}) {
  const participants = event.participants ?? []
  const registered = participants.filter((p) => p.registration_status === 'REGISTERED')
  const waiting = participants.filter((p) => p.registration_status === 'WAITLIST')

  const canPoint = event.permissions?.manage_attendance === true && event.status !== 'PUBLISHED'

  return (
    <section className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="text-sm font-semibold">
        Participants
        <span className="ml-2 font-normal text-[var(--cd-text-muted)]">
          {registered.length} inscrit{registered.length > 1 ? 's' : ''}
          {waiting.length > 0 && ` · ${waiting.length} en attente`}
        </span>
      </h2>

      {participants.length === 0 && (
        <p className="mt-4 text-sm text-[var(--cd-text-muted)]">
          Personne n’est encore inscrit. Soyez le premier.
        </p>
      )}

      {registered.length > 0 && (
        <ul className="mt-4 divide-y divide-[var(--cd-border)]">
          {registered.map((participant) => (
            <ParticipantRow
              key={participant.member?.uuid ?? participant.registered_at}
              participant={participant}
              canPoint={canPoint}
              busy={busy}
              onAttendance={onAttendance}
            />
          ))}
        </ul>
      )}

      {waiting.length > 0 && (
        <>
          <h3 className="mt-6 text-xs font-semibold tracking-wide text-[var(--cd-text-muted)] uppercase">
            Liste d’attente
          </h3>
          <ul className="mt-2 divide-y divide-[var(--cd-border)]">
            {waiting.map((participant) => (
              <ParticipantRow
                key={participant.member?.uuid ?? participant.registered_at}
                participant={participant}
                canPoint={false}
                busy={busy}
                onAttendance={onAttendance}
              />
            ))}
          </ul>
        </>
      )}
    </section>
  )
}

function ParticipantRow({
  participant,
  canPoint,
  busy,
  onAttendance,
}: {
  participant: NonNullable<ClubEvent['participants']>[number]
  canPoint: boolean
  busy: boolean
  onAttendance: (member: string, status: AttendanceStatusCode) => void
}) {
  const member = participant.member

  if (member === undefined) {
    return null
  }

  const present = participant.attendance_status === 'PRESENT'
  const absent = participant.attendance_status === 'ABSENT'

  return (
    <li className="flex items-center gap-3 py-3">
      <Avatar initials={member.initials} photoUrl={member.photo_url} size={36} />

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">{member.full_name}</p>
        <p className="text-xs text-[var(--cd-text-muted)] tabular-nums">
          {member.matricule}
          {participant.queue_position !== null && ` · ${participant.queue_position}ᵉ en attente`}
          {/* « Non pointé » n'est pas « absent ». Le dire évite d'accuser
              d'absence un membre que personne n'a eu le temps de pointer. */}
          {participant.attendance_status !== 'UNKNOWN' &&
            ` · ${participant.attendance_status_label}`}
        </p>
      </div>

      {canPoint && (
        <div className="flex shrink-0 gap-1.5">
          <button
            type="button"
            onClick={() => onAttendance(member.uuid, present ? 'UNKNOWN' : 'PRESENT')}
            disabled={busy}
            aria-pressed={present}
            aria-label={`Marquer ${member.full_name} présent`}
            className={[
              'flex size-9 items-center justify-center rounded-full border transition-colors disabled:opacity-60',
              present
                ? 'border-transparent bg-[var(--cd-green)] text-[var(--cd-black)]'
                : 'border-[var(--cd-border)] text-[var(--cd-text-muted)] hover:border-[var(--cd-green)]',
            ].join(' ')}
          >
            <Check size={16} aria-hidden="true" />
          </button>

          <button
            type="button"
            onClick={() => onAttendance(member.uuid, absent ? 'UNKNOWN' : 'ABSENT')}
            disabled={busy}
            aria-pressed={absent}
            aria-label={`Marquer ${member.full_name} absent`}
            className={[
              'flex size-9 items-center justify-center rounded-full border transition-colors disabled:opacity-60',
              absent
                ? 'border-transparent bg-[var(--cd-danger)] text-white'
                : 'border-[var(--cd-border)] text-[var(--cd-text-muted)] hover:border-[var(--cd-danger)]',
            ].join(' ')}
          >
            <X size={16} aria-hidden="true" />
          </button>
        </div>
      )}
    </li>
  )
}
