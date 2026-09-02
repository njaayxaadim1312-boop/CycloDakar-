import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Award, CalendarClock, Flag, Users } from 'lucide-react'
import { useState } from 'react'
import { ChallengeDialog } from '@/components/community/ChallengeDialog'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import {
  fetchBadges,
  fetchChallenges,
  formatMetric,
  joinChallenge,
  leaveChallenge,
  type ChallengeScope,
} from '@/lib/community'
import { formatDate } from '@/lib/format'
import { useCurrentUser } from '@/stores/auth'
import type { Challenge } from '@/types/api'

/**
 * Les défis du club.
 *
 * **Les badges sont montrés en haut, avant les défis en cours.** Ce qu'on a
 * gagné se regarde plus souvent que ce qu'on doit encore faire, et c'est ce qui
 * donne envie de s'inscrire au suivant. Les cacher en bas de page reviendrait à
 * traiter la récompense comme un détail administratif.
 *
 * Un défi affiche sa **progression réelle dès l'inscription** : un membre qui
 * roulait déjà voit sa barre remplie tout de suite. C'est le serveur qui la
 * calcule, sur toute la fenêtre du défi — repartir de zéro pénaliserait celui
 * qui a ouvert l'application plus tard.
 */
const ONGLETS: Array<{ code: ChallengeScope; label: string }> = [
  { code: 'running', label: 'En cours' },
  { code: 'upcoming', label: 'À venir' },
  { code: 'past', label: 'Terminés' },
]

export function ChallengesPage() {
  const user = useCurrentUser()
  const [scope, setScope] = useState<ChallengeScope>('running')

  const query = useQuery({
    queryKey: ['challenges', scope],
    queryFn: () => fetchChallenges(scope),
  })

  const badges = useQuery({ queryKey: ['badges'], queryFn: fetchBadges })

  const challenges = query.data ?? []
  const mesBadges = badges.data ?? []

  return (
    <div className="space-y-5">
      <PageHeader
        title="Challenges"
        description="Des objectifs pour se donner une raison de sortir."
        actions={user?.abilities.lead_rides === true ? <ChallengeDialog /> : undefined}
      />

      {/* --- Ce qu'on a déjà gagné, en premier ----------------------------- */}
      {mesBadges.length > 0 && (
        <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <Award size={16} aria-hidden="true" />
            Mes badges
            <span className="font-normal text-[var(--cd-text-muted)] tabular-nums">
              {mesBadges.length}
            </span>
          </h2>

          <ul className="mt-4 flex flex-wrap gap-2">
            {mesBadges.map((badge) => (
              <li
                key={badge.challenge.uuid}
                className="cd-rise rounded-[var(--cd-radius)] border border-[var(--cd-orange)] bg-[var(--cd-orange-soft)] px-3 py-2"
              >
                <p className="text-sm font-semibold text-[var(--cd-orange-text)]">
                  {badge.challenge.title}
                </p>
                <p className="text-xs text-[var(--cd-text-muted)]">
                  Réussi le {formatDate(badge.completed_at)}
                </p>
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="flex flex-wrap gap-1.5">
        {ONGLETS.map((onglet) => (
          <button
            key={onglet.code}
            type="button"
            onClick={() => setScope(onglet.code)}
            aria-pressed={scope === onglet.code}
            className={`rounded-[var(--cd-radius-pill)] border px-3 py-1.5 text-xs font-medium transition-colors ${
              scope === onglet.code
                ? 'border-[var(--cd-orange)] bg-[var(--cd-orange)] text-[var(--cd-black)]'
                : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]'
            }`}
          >
            {onglet.label}
          </button>
        ))}
      </div>

      {query.isPending && <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>}

      {!query.isPending && challenges.length === 0 && (
        <div className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center">
          <Flag size={28} aria-hidden="true" className="mx-auto text-[var(--cd-text-muted)]" />
          <p className="mt-3 text-sm font-medium">
            {scope === 'running' ? 'Aucun défi en cours.' : 'Rien ici pour le moment.'}
          </p>
          {user?.abilities.lead_rides === true && scope === 'running' && (
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              Vous pouvez en proposer un — « 100 km ce mois-ci » suffit à lancer une
              dynamique.
            </p>
          )}
        </div>
      )}

      {challenges.length > 0 && (
        <ul className="grid gap-4 lg:grid-cols-2">
          {challenges.map((challenge) => (
            <ChallengeCard key={challenge.uuid} challenge={challenge} />
          ))}
        </ul>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function ChallengeCard({ challenge }: { challenge: Challenge }) {
  const queryClient = useQueryClient()

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['challenges'] })
    void queryClient.invalidateQueries({ queryKey: ['badges'] })
  }

  const join = useMutation({ mutationFn: () => joinChallenge(challenge.uuid), onSuccess: refresh })
  const leave = useMutation({ mutationFn: () => leaveChallenge(challenge.uuid), onSuccess: refresh })

  const error =
    join.error instanceof ApiError
      ? join.error
      : leave.error instanceof ApiError
        ? leave.error
        : null

  const inscrit = challenge.my_progress !== null
  const reussi = challenge.my_progress?.completed_at != null
  const pourcent = challenge.my_progress?.percent ?? 0

  return (
    <li className="cd-rise flex flex-col rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="font-bold">{challenge.title}</h3>
          <p className="mt-0.5 text-xs text-[var(--cd-text-muted)]">
            {challenge.metric_label} · {formatMetric(challenge.target, challenge.metric)}
            {challenge.sport_label !== null && ` · ${challenge.sport_label}`}
          </p>
        </div>

        {reussi && (
          <span className="shrink-0 rounded-[var(--cd-radius-pill)] bg-[var(--cd-green-soft)] px-2 py-1 text-xs font-semibold text-[var(--cd-green-hover)]">
            Réussi
          </span>
        )}
      </div>

      {challenge.description !== null && challenge.description !== '' && (
        <p className="mt-3 text-sm leading-relaxed text-[var(--cd-text-muted)]">
          {challenge.description}
        </p>
      )}

      {/* --- La progression, quand on participe ---------------------------- */}
      {inscrit && (
        <div className="mt-4">
          <div className="flex items-baseline justify-between text-sm">
            <span className="font-semibold tabular-nums">
              {formatMetric(challenge.my_progress?.value ?? 0, challenge.metric)}
            </span>
            <span className="text-xs text-[var(--cd-text-muted)] tabular-nums">
              {pourcent} %
            </span>
          </div>

          <div
            className="mt-1.5 h-2 overflow-hidden rounded-full bg-[var(--cd-surface-2)]"
            role="presentation"
          >
            <div
              className="h-full rounded-full bg-[var(--cd-green)] transition-[width] duration-500"
              style={{ width: `${pourcent}%` }}
            />
          </div>
        </div>
      )}

      <dl className="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs text-[var(--cd-text-muted)]">
        <div className="flex items-center gap-1.5">
          <CalendarClock size={13} aria-hidden="true" />
          {challenge.days_left === null
            ? `Terminé le ${formatDate(challenge.ends_on)}`
            : `${challenge.days_left} jour${challenge.days_left > 1 ? 's' : ''} restant${challenge.days_left > 1 ? 's' : ''}`}
        </div>
        <div className="flex items-center gap-1.5">
          <Users size={13} aria-hidden="true" />
          {challenge.participants} participant{challenge.participants > 1 ? 's' : ''}
          {challenge.finishers > 0 && ` · ${challenge.finishers} au but`}
        </div>
      </dl>

      {error !== null && (
        <p role="alert" className="mt-3 text-xs text-[var(--cd-danger)]">
          {error.message}
        </p>
      )}

      <div className="mt-4 flex flex-wrap gap-2">
        {!inscrit && challenge.permissions?.join === true && challenge.accepts_entries && (
          <button
            type="button"
            onClick={() => join.mutate()}
            disabled={join.isPending}
            className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-60"
          >
            {join.isPending ? 'Inscription…' : 'Participer'}
          </button>
        )}

        {/* Un défi réussi ne se quitte pas : le badge reste acquis, et le
            bouton disparaît plutôt que d'échouer. */}
        {inscrit && !reussi && challenge.accepts_entries && (
          <button
            type="button"
            onClick={() => leave.mutate()}
            disabled={leave.isPending}
            className="rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-4 py-2 text-sm font-medium disabled:opacity-60"
          >
            {leave.isPending ? '…' : 'Me retirer'}
          </button>
        )}
      </div>
    </li>
  )
}
