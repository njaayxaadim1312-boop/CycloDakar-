import { useQuery } from '@tanstack/react-query'
import { Lock, Trophy } from 'lucide-react'
import { useState } from 'react'
import { Avatar } from '@/components/ui/Avatar'
import { PageHeader } from '@/components/ui/PageHeader'
import { fetchLeaderboard, formatMetric } from '@/lib/community'
import type {
  ChallengeMetricCode,
  LeaderboardEntry,
  LeaderboardPeriod,
  SportCode,
} from '@/types/api'

/**
 * Les classements du club.
 *
 * DEUX CHOSES QUE CET ÉCRAN DIT EXPLICITEMENT, ET QU'IL SERAIT TENTANT DE TAIRE.
 *
 * **Que les sorties privées n'y figurent pas.** Un membre qui ne se voit pas
 * dans le classement doit comprendre pourquoi. Sans cette phrase, il conclurait
 * à un bug, ou pire, à une injustice.
 *
 * **Qu'une période close est figée.** Un classement terminé ne bougera plus ;
 * un classement en cours peut encore changer. Ce n'est pas un détail technique
 * à cacher : c'est la différence entre « j'ai gagné » et « je suis en tête pour
 * l'instant ».
 *
 * Le rang du lecteur est affiché **même hors du top 20**. Un classement qui ne
 * montre que les premiers dit à tous les autres qu'ils ne comptent pas ;
 * connaître son rang est précisément ce qui donne envie de le remonter.
 */
const PERIODES: Array<{ code: LeaderboardPeriod; label: string }> = [
  { code: 'week', label: 'Cette semaine' },
  { code: 'month', label: 'Ce mois-ci' },
  { code: 'year', label: 'Cette année' },
]

const MESURES: Array<{ code: ChallengeMetricCode; label: string }> = [
  { code: 'distance', label: 'Distance' },
  { code: 'activities', label: 'Régularité' },
  { code: 'duration', label: 'Temps' },
  { code: 'elevation', label: 'Dénivelé' },
]

const SPORTS: Array<{ code: SportCode | ''; label: string }> = [
  { code: '', label: 'Tous sports' },
  { code: 'CYCLING', label: 'Vélo' },
  { code: 'RUNNING', label: 'Course' },
  { code: 'WALKING', label: 'Marche' },
  { code: 'HIKING', label: 'Randonnée' },
]

export function LeaderboardPage() {
  const [period, setPeriod] = useState<LeaderboardPeriod>('month')
  const [metric, setMetric] = useState<ChallengeMetricCode>('distance')
  const [sport, setSport] = useState<SportCode | ''>('')

  const query = useQuery({
    queryKey: ['leaderboard', period, metric, sport],
    queryFn: () => fetchLeaderboard({ period, metric, sport }),
  })

  const entries = query.data?.entries ?? []
  const meta = query.data?.meta

  return (
    <div className="space-y-5">
      <PageHeader
        title="Classements"
        description="Qui roule, qui marche, qui vient régulièrement."
      />

      {/* --- Les trois axes de lecture ------------------------------------- */}
      <section className="space-y-3 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4">
        <Choix
          legende="Période"
          options={PERIODES}
          courant={period}
          onChange={(code) => setPeriod(code)}
        />
        {/* « Régularité » plutôt que « Nombre de sorties » : c'est la mesure
            qui met en avant celui qui vient chaque dimanche, et la nommer
            ainsi dit ce que le club valorise. */}
        <Choix
          legende="Mesure"
          options={MESURES}
          courant={metric}
          onChange={(code) => setMetric(code)}
        />
        <Choix
          legende="Sport"
          options={SPORTS}
          courant={sport}
          onChange={(code) => setSport(code)}
        />
      </section>

      {/* --- Mon rang, même hors du top ------------------------------------ */}
      {meta?.me != null && (
        <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-orange)] bg-[var(--cd-surface)] p-5">
          <p className="text-xs text-[var(--cd-text-muted)]">Ma position</p>
          <div className="mt-1 flex flex-wrap items-baseline gap-3">
            <p className="text-3xl font-bold tabular-nums">
              {meta.me.rank === null ? '—' : `${meta.me.rank}ᵉ`}
            </p>
            <p className="text-sm text-[var(--cd-text-muted)]">
              {meta.me.rank === null
                ? 'Aucune sortie partagée sur cette période.'
                : `sur ${meta.me.total} membre${meta.me.total > 1 ? 's' : ''} classé${meta.me.total > 1 ? 's' : ''} · ${formatMetric(meta.me.value, metric)}`}
            </p>
          </div>
        </section>
      )}

      {/* --- L'état du classement ------------------------------------------ */}
      {meta !== undefined && (
        <p className="flex items-start gap-2 text-xs text-[var(--cd-text-muted)]">
          {meta.frozen ? (
            <>
              <Lock size={14} className="mt-px shrink-0" />
              <span>
                Ce classement est <strong>définitif</strong> : la période est close, il
                ne bougera plus.
              </span>
            </>
          ) : (
            <>
              <Trophy size={14} className="mt-px shrink-0" />
              <span>
                Période en cours — ce classement peut encore changer d’ici la fin.
              </span>
            </>
          )}
        </p>
      )}

      {query.isPending && <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>}

      {!query.isPending && entries.length === 0 && (
        <div className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center">
          <Trophy size={28} aria-hidden="true" className="mx-auto text-[var(--cd-text-muted)]" />
          <p className="mt-3 text-sm font-medium">Aucune sortie classée sur cette période.</p>
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            Enregistrez une sortie pour ouvrir le classement.
          </p>
        </div>
      )}

      {entries.length > 0 && (
        <ul className="space-y-2">
          {entries.map((entry) => (
            <Ligne
              key={entry.member.uuid}
              entry={entry}
              metric={metric}
              /* Le serveur renvoie l'identité du lecteur dans `meta.me` :
                 `CurrentUser` ne porte pas la fiche club, et comparer sur le
                 nom serait faux le jour où deux membres s'appellent pareil. */
              moi={entry.member.uuid === meta?.me?.member?.uuid}
            />
          ))}
        </ul>
      )}

      {/* La phrase qui évite qu'un membre conclue à un bug. */}
      <p className="text-xs text-[var(--cd-text-muted)]">
        Les sorties marquées <strong>privées</strong> ne sont jamais comptées dans un
        classement, même pour leur auteur : un classement est une publication.
      </p>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function Choix<T extends string>({
  legende,
  options,
  courant,
  onChange,
}: {
  legende: string
  options: Array<{ code: T; label: string }>
  courant: T
  onChange: (code: T) => void
}) {
  return (
    <fieldset>
      <legend className="text-xs font-semibold text-[var(--cd-text-muted)]">{legende}</legend>
      <div className="mt-1.5 flex flex-wrap gap-1.5">
        {options.map((option) => (
          <button
            key={option.code}
            type="button"
            onClick={() => onChange(option.code)}
            aria-pressed={courant === option.code}
            className={`rounded-[var(--cd-radius-pill)] border px-3 py-1.5 text-xs font-medium transition-colors ${
              courant === option.code
                ? 'border-[var(--cd-orange)] bg-[var(--cd-orange)] text-[var(--cd-black)]'
                : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]'
            }`}
          >
            {option.label}
          </button>
        ))}
      </div>
    </fieldset>
  )
}

/**
 * Le podium se distingue, le reste s'aligne.
 *
 * Trois couleurs seulement, et aucune n'est en dur : elles viennent des jetons.
 * Au-delà du troisième, une médaille n'apporte rien — c'est le rang chiffré
 * qu'on lit.
 */
const PODIUM: Record<number, string> = {
  1: 'bg-[var(--cd-orange)] text-[var(--cd-black)]',
  2: 'bg-[var(--cd-surface-2)] text-[var(--cd-text)]',
  3: 'bg-[var(--cd-orange-soft)] text-[var(--cd-orange-text)]',
}

function Ligne({
  entry,
  metric,
  moi,
}: {
  entry: LeaderboardEntry
  metric: ChallengeMetricCode
  moi: boolean
}) {
  return (
    <li
      className={[
        'cd-rise flex items-center gap-3 rounded-[var(--cd-radius-lg)] border bg-[var(--cd-surface)] p-4',
        // Se repérer soi-même dans une liste de vingt noms doit être immédiat.
        moi ? 'border-[var(--cd-orange)]' : 'border-[var(--cd-border)]',
      ].join(' ')}
    >
      <span
        className={[
          'flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-bold tabular-nums',
          PODIUM[entry.rank] ?? 'bg-[var(--cd-bg)] text-[var(--cd-text-muted)]',
        ].join(' ')}
      >
        {entry.rank}
      </span>

      <Avatar
        initials={entry.member.initials}
        photoUrl={entry.member.photo_url}
        size={36}
      />

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold">
          {entry.member.full_name}
          {moi && <span className="ml-2 text-xs text-[var(--cd-orange-text)]">vous</span>}
        </p>
        <p className="text-xs text-[var(--cd-text-muted)]">
          {entry.activities} sortie{entry.activities > 1 ? 's' : ''}
        </p>
      </div>

      <p className="shrink-0 text-right text-sm font-bold tabular-nums">
        {formatMetric(entry.value, metric)}
      </p>
    </li>
  )
}
