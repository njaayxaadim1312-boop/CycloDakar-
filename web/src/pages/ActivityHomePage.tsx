import { useQuery } from '@tanstack/react-query'
import { CalendarDays, ChevronRight, Route } from 'lucide-react'
import { Link } from 'react-router-dom'
import { ActivityRings } from '@/components/activity/ActivityRings'
import { GoalsDialog } from '@/components/activity/GoalsDialog'
import { ApiError } from '@/lib/api'
import { fetchActivities } from '@/lib/activities'
import {
  formatDate,
  formatDistance,
  formatDurationLong,
  formatInteger,
  formatSpeed,
} from '@/lib/format'
import { SPORT_ICON, sportTint } from '@/lib/sports'
import { fetchDashboardStats, fetchPersonalStats } from '@/lib/stats'
import { useCurrentUser } from '@/stores/auth'
import type { Activity } from '@/types/api'

const RING_CONFIGS = [
  {
    key: 'distance_m' as const,
    label: 'Distance',
    color: 'var(--cd-ring-distance)',
    format: formatDistance,
  },
  {
    key: 'moving_time_s' as const,
    label: 'Mouvement',
    color: 'var(--cd-ring-time)',
    format: formatDurationLong,
  },
  {
    key: 'activities' as const,
    label: 'Sorties',
    color: 'var(--cd-ring-activities)',
    format: (value: number) => formatInteger(value),
  },
]

/**
 * Écran d'accueil, entièrement tourné vers l'exercice.
 *
 * L'ordre de lecture est délibéré : **ce que j'ai fait cette semaine**, puis
 * mes dernières sorties, puis la prochaine sortie du club. Les effectifs, la
 * caisse et l'administration ne sont pas ici — ils vivent derrière un seul
 * bouton « Gestion » dans le menu. Un membre ouvre cette application pour
 * bouger, pas pour consulter un solde.
 *
 * L'affiche du club sert de fond au bandeau, avec des surfaces de verre
 * par-dessus : c'est l'identité du club, et elle ne coûte rien à la lisibilité
 * tant que le flou est là.
 */
export function ActivityHomePage() {
  const user = useCurrentUser()

  const stats = useQuery({
    queryKey: ['stats', 'me', 'week'],
    queryFn: () => fetchPersonalStats('week'),
    retry: (count, error) =>
      !(error instanceof ApiError && error.code === 'NO_MEMBER_PROFILE') && count < 2,
  })

  const recent = useQuery({
    queryKey: ['activities', { mine: true, per_page: 4 }],
    queryFn: () => fetchActivities({ mine: true, per_page: 4 }),
  })

  const club = useQuery({
    queryKey: ['stats', 'dashboard'],
    queryFn: fetchDashboardStats,
  })

  const noProfile =
    stats.error instanceof ApiError && stats.error.code === 'NO_MEMBER_PROFILE'

  return (
    <div className="space-y-6">
      {/* --- Bandeau : l'affiche du club, en verre ----------------------- */}
      <section className="cd-fade relative overflow-hidden rounded-[var(--cd-radius-lg)]">
        <img
          src="/brand/hero.jpg"
          alt=""
          aria-hidden="true"
          className="absolute inset-0 size-full object-cover object-[center_28%]"
        />
        {/* L'affiche est très contrastée : sans ce voile, le texte blanc
            tomberait tantôt sur du noir, tantôt sur un gilet fluo. */}
        <div
          className="absolute inset-0 bg-gradient-to-r from-black/75 via-black/55 to-black/25"
          aria-hidden="true"
        />

        <div className="relative flex flex-col gap-6 p-6 sm:p-8 lg:flex-row lg:items-center">
          <div className="min-w-0 flex-1">
            <p className="text-xs font-bold tracking-[0.14em] text-white/70 uppercase">
              Cette semaine
            </p>
            <h1 className="mt-1 font-display text-3xl font-extrabold text-white sm:text-4xl">
              Bonjour {user?.name.split(' ')[0] ?? ''}
            </h1>
            <p className="mt-2 max-w-md text-sm leading-relaxed text-white/75">
              Ensemble, plus loin, plus forts. Enregistrez vos sorties au GPS et
              suivez vos objectifs.
            </p>

            {stats.data !== undefined && (
              <div className="cd-stagger mt-5 flex flex-wrap gap-2">
                {RING_CONFIGS.map((config) => {
                  const metric = stats.data.rings.metrics[config.key]

                  return (
                    <span
                      key={config.key}
                      className="cd-glass-dark rounded-[var(--cd-radius-pill)] px-3.5 py-1.5 text-sm font-semibold text-white"
                    >
                      <span
                        className="mr-2 inline-block size-2 rounded-full align-middle"
                        style={{ background: config.color }}
                        aria-hidden="true"
                      />
                      {config.format(metric.value)}
                      <span className="ml-1 font-normal text-white/60">
                        / {config.format(metric.goal)}
                      </span>
                    </span>
                  )
                })}
              </div>
            )}
          </div>

          {/* --- Les anneaux ------------------------------------------- */}
          <div className="cd-pop flex shrink-0 items-center justify-center">
            {stats.data !== undefined ? (
              <ActivityRings rings={stats.data.rings} configs={RING_CONFIGS} size={188} />
            ) : (
              <div className="size-[188px] animate-pulse rounded-full bg-white/10" />
            )}
          </div>
        </div>
      </section>

      {noProfile && (
        <p className="cd-rise rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5 text-sm text-[var(--cd-text-muted)]">
          Votre compte n'est pas encore rattaché à une fiche membre. Contactez le
          bureau du club pour qu'il vous en crée une — vous pourrez alors
          enregistrer vos sorties.
        </p>
      )}

      {/* --- Régularité de la semaine ------------------------------------ */}
      {stats.data !== undefined && (
        <section className="cd-rise rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
          <div className="flex flex-wrap items-baseline justify-between gap-3">
            <h2 className="text-sm font-semibold">Régularité</h2>
            <GoalsDialog goals={stats.data.goals} />
          </div>

          <ol className="mt-4 flex justify-between gap-2">
            {stats.data.rings.days.map((day, index) => (
              <li key={day.date} className="flex flex-1 flex-col items-center gap-1.5">
                <span
                  className={[
                    'flex aspect-square w-full max-w-12 items-center justify-center rounded-[var(--cd-radius)] text-xs font-bold transition-colors',
                    day.active
                      ? 'bg-[var(--cd-orange)] text-[var(--cd-black)]'
                      : 'bg-[var(--cd-surface-2)] text-[var(--cd-text-muted)]',
                  ].join(' ')}
                  style={{ animationDelay: `${index * 40}ms` }}
                  title={
                    day.active
                      ? `${formatDate(day.date)} — ${formatDistance(day.distance_m)}`
                      : `${formatDate(day.date)} — repos`
                  }
                >
                  {day.label}
                </span>
              </li>
            ))}
          </ol>

          <p className="mt-3 text-xs text-[var(--cd-text-muted)]">
            {/* Le nombre de jours actifs dit quelque chose que le cumul ne dit
                pas : rouler 40 km en une fois n'est pas rouler 40 km en
                quatre fois. */}
            {stats.data.rings.days.filter((day) => day.active).length} jour
            {stats.data.rings.days.filter((day) => day.active).length > 1 ? 's' : ''} d'activité
            sur les sept.
          </p>
        </section>
      )}

      {/* --- Dernières sorties ------------------------------------------- */}
      <section className="cd-rise">
        <div className="mb-3 flex items-baseline justify-between gap-3">
          <h2 className="text-sm font-bold tracking-wide text-[var(--cd-text-muted)] uppercase">
            Mes dernières sorties
          </h2>
          <Link
            to="/activities?mine=1"
            className="text-sm font-medium text-[var(--cd-orange-text)] hover:underline"
          >
            Tout voir
          </Link>
        </div>

        {recent.isLoading && (
          <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>
        )}

        {recent.isSuccess && recent.data.data.length === 0 && (
          <div className="rounded-[var(--cd-radius-lg)] border border-dashed border-[var(--cd-border-strong)] p-8 text-center">
            <p className="text-sm text-[var(--cd-text-muted)]">
              Aucune sortie enregistrée pour l'instant.
            </p>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              Lancez l'enregistrement depuis l'application mobile : vélo, course,
              randonnée ou marche.
            </p>
          </div>
        )}

        {recent.data !== undefined && recent.data.data.length > 0 && (
          <div className="cd-stagger grid gap-3 sm:grid-cols-2">
            {recent.data.data.map((activity) => (
              <RecentActivity key={activity.uuid} activity={activity} />
            ))}
          </div>
        )}
      </section>

      {/* --- Prochaine sortie du club ------------------------------------ */}
      {club.data?.events.next != null && (
        <Link
          to={`/events/${club.data.events.next.uuid}`}
          className="cd-rise cd-lift flex items-center gap-4 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5"
        >
          <span className="flex size-11 shrink-0 items-center justify-center rounded-[var(--cd-radius)] bg-[var(--cd-orange-soft)]">
            <CalendarDays size={20} className="text-[var(--cd-orange-text)]" aria-hidden="true" />
          </span>

          <span className="min-w-0 flex-1">
            <span className="block text-xs text-[var(--cd-text-muted)]">
              Prochaine sortie du club
            </span>
            <span className="block truncate font-semibold">
              {club.data.events.next.title}
            </span>
            <span className="block truncate text-sm text-[var(--cd-text-muted)]">
              {club.data.events.next.starts_at !== null &&
                formatDate(club.data.events.next.starts_at)}
              {' · '}
              {club.data.events.next.location_name}
            </span>
          </span>

          <ChevronRight size={18} className="shrink-0 text-[var(--cd-text-muted)]" />
        </Link>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function RecentActivity({ activity }: { activity: Activity }) {
  const Icon = SPORT_ICON[activity.sport]

  return (
    <Link
      to={`/activities/${activity.uuid}`}
      className="cd-lift flex items-center gap-3 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4"
    >
      <span
        className="flex size-11 shrink-0 items-center justify-center rounded-[var(--cd-radius)]"
        style={{ background: sportTint(activity.sport) }}
      >
        <Icon size={20} style={{ color: `var(--cd-sport-${activity.sport.toLowerCase()})` }} aria-hidden="true" />
      </span>

      <span className="min-w-0 flex-1">
        <span className="block truncate font-semibold">{activity.title}</span>
        <span className="block text-xs text-[var(--cd-text-muted)]">
          {activity.started_at !== null ? formatDate(activity.started_at) : '—'}
        </span>
        <span className="mt-1 flex flex-wrap gap-x-3 text-sm tabular-nums">
          <span className="font-semibold text-[var(--cd-orange-text)]">
            {formatDistance(activity.distance_m)}
          </span>
          <span className="text-[var(--cd-text-muted)]">
            {formatDurationLong(activity.moving_time_s)}
          </span>
          <span className="text-[var(--cd-text-muted)]">
            {formatSpeed(activity.avg_speed_mps)}
          </span>
        </span>
      </span>

      <Route size={16} className="shrink-0 text-[var(--cd-text-muted)]" aria-hidden="true" />
    </Link>
  )
}
