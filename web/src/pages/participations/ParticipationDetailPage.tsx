import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, ArrowLeft, UserMinus, UserPlus } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Avatar } from '@/components/ui/Avatar'
import { ApiError } from '@/lib/api'
import { formatDate, formatFcfa } from '@/lib/format'
import {
  assignMembers,
  fetchParticipation,
  removeLine,
  updateLine,
  updateParticipationStatus,
} from '@/lib/participations'
import type {
  Participation,
  ParticipationLine,
  ParticipationStatusCode,
} from '@/types/api'

/**
 * Fiche d'une collecte.
 *
 * Trois blocs, dans l'ordre où le bureau les consulte : le suivi de l'argent,
 * les actes possibles sur la campagne, puis la liste nominative des dettes.
 *
 * Les lignes impayées arrivent en tête — c'est ce qu'un collecteur vient
 * chercher. Les lignes soldées ne demandent plus rien.
 */
export function ParticipationDetailPage() {
  const { uuid = '' } = useParams()
  const queryClient = useQueryClient()
  const [notice, setNotice] = useState<string | null>(null)

  const query = useQuery({
    queryKey: ['participation', uuid],
    queryFn: () => fetchParticipation(uuid),
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['participation', uuid] })
    void queryClient.invalidateQueries({ queryKey: ['participations'] })
    void queryClient.invalidateQueries({ queryKey: ['stats', 'dashboard'] })
  }

  const assign = useMutation({
    mutationFn: () => assignMembers(uuid),
    onSuccess: (result) => {
      setNotice(
        result.created === 0
          ? 'Tous les membres actifs étaient déjà rattachés.'
          : `${result.created} membre(s) rattaché(s)${
              result.skipped > 0 ? `, ${result.skipped} déjà présent(s)` : ''
            }.`,
      )
      refresh()
    },
  })

  const changeStatus = useMutation({
    mutationFn: (status: ParticipationStatusCode) => updateParticipationStatus(uuid, status),
    onSuccess: () => {
      setNotice(null)
      refresh()
    },
  })

  const exempt = useMutation({
    mutationFn: (lineId: number) => updateLine(uuid, lineId, { exempt: true }),
    onSuccess: () => refresh(),
  })

  const remove = useMutation({
    mutationFn: (lineId: number) => removeLine(uuid, lineId),
    onSuccess: (result) => {
      setNotice(result.message)
      refresh()
    },
  })

  if (query.isLoading) {
    return <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>
  }

  const participation = query.data

  if (query.error !== null || participation === undefined) {
    return (
      <div className="space-y-4">
        <BackLink />
        <p className="flex items-center gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {query.error instanceof ApiError
            ? query.error.message
            : 'Cette collecte est introuvable.'}
        </p>
      </div>
    )
  }

  const error = [assign.error, changeStatus.error, exempt.error, remove.error].find(
    (caught): caught is ApiError => caught instanceof ApiError,
  )

  const { tally } = participation
  const busy = assign.isPending || changeStatus.isPending || remove.isPending

  return (
    <div className="space-y-5">
      <BackLink />

      {/* --- Suivi de l'argent -------------------------------------------- */}
      <header className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-2xl">{participation.name}</h1>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              {participation.status_label}
              {participation.due_on !== null &&
                ` · échéance le ${formatDate(participation.due_on)}`}
              {` · ${formatFcfa(participation.expected_amount)} par membre`}
            </p>
          </div>

          {participation.permissions?.update === true && (
            <Link
              to={`/participations/${participation.uuid}/modifier`}
              className="rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-4 py-2 text-sm font-medium hover:border-[var(--cd-orange)]"
            >
              Modifier
            </Link>
          )}
        </div>

        <dl className="mt-5 grid gap-4 sm:grid-cols-4">
          <Amount label="Attendu" value={tally.expected_amount} />
          <Amount label="Encaissé" value={tally.collected_amount} accent />
          <Amount label="Reste" value={tally.remaining_amount} />
          <div>
            <dt className="text-xs text-[var(--cd-text-muted)]">Membres à jour</dt>
            <dd className="mt-1 text-xl font-bold tabular-nums">
              {tally.paid_members} / {tally.members}
            </dd>
          </div>
        </dl>

        <div
          className="mt-4 h-2 overflow-hidden rounded-full bg-[var(--cd-surface-2)]"
          role="presentation"
        >
          <div
            className="h-full rounded-full bg-[var(--cd-green)] transition-[width] duration-500"
            style={{ width: `${Math.min(100, tally.progress_percent)}%` }}
          />
        </div>

        {participation.description !== null && participation.description !== '' && (
          <p className="mt-4 text-sm leading-relaxed whitespace-pre-line">
            {participation.description}
          </p>
        )}
      </header>

      {notice !== null && (
        <p className="cd-rise rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface-2)] p-4 text-sm">
          {notice}
        </p>
      )}

      {error !== undefined && (
        <p className="flex items-center gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {error.message}
        </p>
      )}

      {/* --- Actes sur la campagne ---------------------------------------- */}
      {participation.permissions?.assign === true && (
        <Lifecycle
          participation={participation}
          busy={busy}
          onAssign={() => assign.mutate()}
          onStatus={(status) => changeStatus.mutate(status)}
        />
      )}

      {/* --- Les dettes ---------------------------------------------------- */}
      <Lines
        participation={participation}
        busy={busy || exempt.isPending}
        onExempt={(lineId) => exempt.mutate(lineId)}
        onRemove={(lineId) => remove.mutate(lineId)}
      />
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function BackLink() {
  return (
    <Link
      to="/participations"
      className="inline-flex items-center gap-1.5 text-sm text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]"
    >
      <ArrowLeft size={15} aria-hidden="true" />
      Toutes les collectes
    </Link>
  )
}

function Amount({
  label,
  value,
  accent,
}: {
  label: string
  value: number
  accent?: boolean
}) {
  return (
    <div>
      <dt className="text-xs text-[var(--cd-text-muted)]">{label}</dt>
      <dd
        className={[
          'mt-1 tabular-nums font-bold',
          accent ? 'text-2xl text-[var(--cd-orange-text)]' : 'text-xl',
        ].join(' ')}
      >
        {formatFcfa(value)}
      </dd>
    </div>
  )
}

/** Transitions autorisées, miroir de `ParticipationStatus::allowedTransitions`. */
const TRANSITIONS: Record<
  ParticipationStatusCode,
  { status: ParticipationStatusCode; label: string }[]
> = {
  DRAFT: [
    { status: 'OPEN', label: 'Ouvrir la collecte' },
    { status: 'CANCELLED', label: 'Annuler' },
  ],
  OPEN: [
    { status: 'CLOSED', label: 'Clôturer' },
    { status: 'CANCELLED', label: 'Annuler' },
  ],
  CLOSED: [],
  CANCELLED: [],
}

function Lifecycle({
  participation,
  busy,
  onAssign,
  onStatus,
}: {
  participation: Participation
  busy: boolean
  onAssign: () => void
  onStatus: (status: ParticipationStatusCode) => void
}) {
  const transitions = TRANSITIONS[participation.status]
  const canAssign = participation.status === 'DRAFT' || participation.status === 'OPEN'

  if (transitions.length === 0 && !canAssign) {
    return null
  }

  return (
    <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="text-sm font-semibold">Gestion de la collecte</h2>
      <p className="mt-1 text-xs text-[var(--cd-text-muted)]">
        {participation.status === 'DRAFT'
          ? "Ce brouillon n'est visible que du bureau. Ouvrez-la pour engager le club."
          : 'Une collecte clôturée ne se rouvre pas : les comptes sont arrêtés. On en crée une nouvelle.'}
      </p>

      <div className="mt-4 flex flex-wrap gap-2">
        {canAssign && (
          <button
            type="button"
            onClick={onAssign}
            disabled={busy}
            className="inline-flex items-center gap-2 rounded-[var(--cd-radius-pill)] border border-[var(--cd-border-strong)] px-4 py-2 text-sm font-medium transition-colors hover:border-[var(--cd-orange)] disabled:opacity-60"
          >
            <UserPlus size={16} aria-hidden="true" />
            Rattacher tous les membres actifs
          </button>
        )}

        {transitions.map((transition) => (
          <button
            key={transition.status}
            type="button"
            onClick={() => onStatus(transition.status)}
            disabled={busy}
            className={[
              'rounded-[var(--cd-radius-pill)] px-4 py-2 text-sm font-medium transition-colors disabled:opacity-60',
              transition.status === 'CANCELLED'
                ? 'border border-[var(--cd-border)] hover:border-[var(--cd-danger)] hover:text-[var(--cd-danger)]'
                : 'bg-[var(--cd-orange)] text-[var(--cd-black)] hover:bg-[var(--cd-orange-hover)]',
            ].join(' ')}
          >
            {transition.label}
          </button>
        ))}
      </div>
    </section>
  )
}

const LINE_STYLE: Record<string, string> = {
  PAYE: 'text-[var(--cd-green-hover)]',
  PARTIELLEMENT_PAYE: 'text-[var(--cd-warning)]',
  ANNULE: 'text-[var(--cd-text-muted)]',
}

function Lines({
  participation,
  busy,
  onExempt,
  onRemove,
}: {
  participation: Participation
  busy: boolean
  onExempt: (lineId: number) => void
  onRemove: (lineId: number) => void
}) {
  const lines = participation.lines ?? []
  const canManage = participation.permissions?.assign === true

  return (
    <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="text-sm font-semibold">
        Membres concernés
        <span className="ml-2 font-normal text-[var(--cd-text-muted)] tabular-nums">
          {lines.length}
        </span>
      </h2>

      {lines.length === 0 && (
        <p className="mt-4 text-sm text-[var(--cd-text-muted)]">
          Aucun membre n'est rattaché. Utilisez « Rattacher tous les membres actifs »
          ci-dessus.
        </p>
      )}

      {lines.length > 0 && (
        <ul className="mt-4 divide-y divide-[var(--cd-border)]">
          {lines.map((line) => (
            <LineRow
              key={line.id}
              line={line}
              canManage={canManage}
              busy={busy}
              onExempt={() => onExempt(line.id)}
              onRemove={() => onRemove(line.id)}
            />
          ))}
        </ul>
      )}
    </section>
  )
}

function LineRow({
  line,
  canManage,
  busy,
  onExempt,
  onRemove,
}: {
  line: ParticipationLine
  canManage: boolean
  busy: boolean
  onExempt: () => void
  onRemove: () => void
}) {
  const member = line.member

  if (member === undefined) return null

  const cancelled = line.status === 'ANNULE'

  return (
    <li className="flex items-center gap-3 py-3">
      <Avatar initials={member.initials} photoUrl={member.photo_url} size={36} />

      <div className="min-w-0 flex-1">
        <p
          className={[
            'truncate text-sm font-medium',
            cancelled ? 'text-[var(--cd-text-muted)] line-through' : '',
          ].join(' ')}
        >
          {member.full_name}
        </p>
        <p className="text-xs tabular-nums text-[var(--cd-text-muted)]">
          {member.matricule}
          {member.phone_formatted !== null && ` · ${member.phone_formatted}`}
          {line.note !== null && line.note !== '' && ` · ${line.note}`}
        </p>
      </div>

      <div className="shrink-0 text-right">
        <p className={['text-sm font-semibold tabular-nums', LINE_STYLE[line.status] ?? ''].join(' ')}>
          {formatFcfa(line.paid_amount)} / {formatFcfa(line.expected_amount)}
        </p>
        <p className="text-xs text-[var(--cd-text-muted)]">{line.status_label}</p>
      </div>

      {canManage && !cancelled && (
        <button
          type="button"
          onClick={line.paid_amount > 0 ? onExempt : onRemove}
          disabled={busy}
          aria-label={
            line.paid_amount > 0
              ? `Dispenser ${member.full_name}`
              : `Retirer ${member.full_name}`
          }
          title={
            line.paid_amount > 0
              ? 'Des paiements existent : la ligne sera annulée, pas supprimée.'
              : 'Retirer de la collecte'
          }
          className="flex size-9 shrink-0 items-center justify-center rounded-full border border-[var(--cd-border)] text-[var(--cd-text-muted)] transition-colors hover:border-[var(--cd-danger)] hover:text-[var(--cd-danger)] disabled:opacity-60"
        >
          <UserMinus size={16} aria-hidden="true" />
        </button>
      )}
    </li>
  )
}
