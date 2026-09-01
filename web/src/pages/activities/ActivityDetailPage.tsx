import { useQuery } from '@tanstack/react-query'
import {
  AlertCircle,
  ArrowLeft,
  Clapperboard,
  Clock,
  Gauge,
  MapPin,
  Mountain,
  Route,
  SignalHigh,
  Timer,
} from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ElevationProfile } from '@/components/charts/ElevationProfile'
import { SplitsChart } from '@/components/charts/SplitsChart'
import { ActivityMap } from '@/components/map/ActivityMap'
import { Avatar } from '@/components/ui/Avatar'
import { ApiError } from '@/lib/api'
import { fetchActivity } from '@/lib/activities'
import {
  formatDateTime,
  formatDistance,
  formatDuration,
  formatElevation,
  formatPace,
  formatSpeed,
  formatTime,
} from '@/lib/format'

/**
 * Fiche détaillée d'une sortie.
 *
 * Reprend la structure du résumé demandé au cahier des charges (§22) :
 * distance, durée, temps actif, vitesses, dénivelé, carte, zones traversées,
 * heures de départ et d'arrivée.
 */
export function ActivityDetailPage() {
  const { uuid = '' } = useParams()
  const navigate = useNavigate()

  const query = useQuery({
    queryKey: ['activity', uuid],
    queryFn: () => fetchActivity(uuid),
    enabled: uuid !== '',
  })

  if (query.isLoading) {
    return <div className="cd-card h-96 animate-pulse" />
  }

  if (query.isError) {
    return (
      <div className="cd-card mx-auto max-w-md p-8 text-center">
        <AlertCircle size={28} className="mx-auto text-[var(--cd-danger)]" />
        <p className="mt-3 font-semibold">
          {query.error instanceof ApiError && query.error.status === 403
            ? 'Cette sortie est privée.'
            : query.error instanceof ApiError && query.error.status === 404
              ? 'Cette sortie est introuvable.'
              : 'La sortie n’a pas pu être chargée.'}
        </p>
        <button
          onClick={() => navigate('/activities')}
          className="cd-btn cd-btn-primary mt-5"
        >
          <ArrowLeft size={16} />
          Retour aux activités
        </button>
      </div>
    )
  }

  const activity = query.data!

  return (
    <div className="space-y-5">
      <Link
        to="/activities"
        className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-text hover:underline"
      >
        <ArrowLeft size={15} />
        Activités
      </Link>

      {/* --- En-tête ------------------------------------------------------ */}
      <section className="cd-card p-5 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h2 className="text-2xl">{activity.title}</h2>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              {activity.started_at && formatDateTime(activity.started_at)}
              {activity.ended_at && ` → ${formatTime(activity.ended_at)}`}
            </p>

            {activity.zones.length > 0 && (
              <p className="mt-2 flex flex-wrap items-center gap-1.5 text-sm">
                <MapPin size={14} className="text-[var(--cd-text-muted)]" />
                {activity.zones.map((zone) => (
                  <span
                    key={zone}
                    className="cd-badge bg-[var(--cd-surface-2)] text-[var(--cd-text)]"
                  >
                    {zone}
                  </span>
                ))}
              </p>
            )}
          </div>

          {activity.member && (
            <div className="flex items-center gap-2.5">
              <Avatar
                photoUrl={activity.member.photo_url}
                initials={activity.member.initials}
                size={40}
              />
              <span className="text-sm font-semibold">{activity.member.full_name}</span>
            </div>
          )}
        </div>

        {/* --- Chiffres clés ---------------------------------------------- */}
        <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <Stat
            icon={Route}
            label="Distance"
            value={formatDistance(activity.distance_m)}
            accent
          />
          <Stat
            icon={Timer}
            label="Temps actif"
            value={formatDuration(activity.moving_time_s)}
          />
          <Stat
            icon={Clock}
            label="Durée totale"
            value={formatDuration(activity.duration_s)}
            hint={
              activity.paused_time_s > 0
                ? `dont ${formatDuration(activity.paused_time_s)} de pause`
                : undefined
            }
          />
          <Stat
            icon={Gauge}
            label={activity.uses_pace ? 'Allure moyenne' : 'Vitesse moyenne'}
            value={
              activity.uses_pace && activity.avg_pace_s_per_km
                ? formatPace(activity.avg_pace_s_per_km)
                : formatSpeed(activity.avg_speed_mps)
            }
          />
          <Stat
            icon={Gauge}
            label="Vitesse maximale"
            value={formatSpeed(activity.max_speed_mps)}
          />
          <Stat
            icon={Mountain}
            label="Dénivelé"
            value={formatElevation(activity.elevation_gain_m)}
            hint={
              activity.elevation_loss_m > 0
                ? `${formatElevation(-activity.elevation_loss_m)} en descente`
                : undefined
            }
          />
        </div>
      </section>

      {/* --- Carte -------------------------------------------------------- */}
      <section className="cd-card overflow-hidden p-5">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <h3 className="text-lg">Parcours</h3>

          {/* Le film du parcours : depart, itineraire, arrivee, en video
              partageable. C'est ce que les membres montrent apres une
              sortie. */}
          <Link
            to={`/activities/${activity.uuid}/video`}
            className="inline-flex items-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-black)] px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
          >
            <Clapperboard size={16} aria-hidden="true" />
            Revoir en vidéo
          </Link>
        </div>

        <ActivityMap polyline={activity.polyline} bounds={activity.bounds} height={380} />
      </section>

      {/* --- Graphiques --------------------------------------------------- */}
      {activity.stats && (
        <section className="grid gap-4 lg:grid-cols-2">
          <article className="cd-card p-5">
            <h3 className="text-lg">Profil d'altitude</h3>
            <p className="mt-1 mb-3 text-sm text-[var(--cd-text-muted)]">
              Échelle cadrée sur le relief réel, pas sur zéro.
            </p>
            <ElevationProfile profile={activity.stats.elevation_profile} />
          </article>

          <article className="cd-card p-5">
            <h3 className="text-lg">
              {activity.uses_pace ? 'Allure par kilomètre' : 'Vitesse par kilomètre'}
            </h3>
            <p className="mt-1 mb-3 text-sm text-[var(--cd-text-muted)]">
              Le meilleur kilomètre est en orange.
            </p>
            <SplitsChart splits={activity.stats.splits} usesPace={activity.uses_pace} />
          </article>
        </section>
      )}

      {/* --- Qualité du signal --------------------------------------------- */}
      {activity.signal && (
        <section className="cd-card p-5">
          <h3 className="flex items-center gap-2 text-lg">
            <SignalHigh size={19} className="text-[var(--cd-text-muted)]" />
            Qualité du signal GPS
          </h3>
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            {activity.signal.filtered_out === 0
              ? 'Aucun point écarté : le signal était excellent.'
              : `${activity.signal.filtered_out} position${activity.signal.filtered_out > 1 ? 's' : ''} écartée${activity.signal.filtered_out > 1 ? 's' : ''} sur ${activity.signal.raw_points_count} — sauts de signal ou précision insuffisante. C'est normal en ville, où les immeubles renvoient le signal.`}
          </p>

          <dl className="mt-4 grid gap-4 sm:grid-cols-3">
            <SmallFact label="Positions reçues" value={`${activity.signal.raw_points_count}`} />
            <SmallFact label="Positions retenues" value={`${activity.points_count}`} />
            <SmallFact
              label="Qualité"
              value={
                activity.signal.quality_percent !== null
                  ? `${activity.signal.quality_percent} %`
                  : '—'
              }
            />
          </dl>
        </section>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function Stat({
  icon: Icon,
  label,
  value,
  hint,
  accent,
}: {
  icon: typeof Route
  label: string
  value: string
  hint?: string
  accent?: boolean
}) {
  return (
    <div className="rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-surface-2)] p-4">
      <span className="flex items-center gap-1.5 text-xs tracking-wide text-[var(--cd-text-muted)] uppercase">
        <Icon size={13} />
        {label}
      </span>
      <p
        className={
          accent
            ? 'tabular mt-1 text-2xl font-extrabold text-brand-text'
            : 'tabular mt-1 text-2xl font-extrabold'
        }
      >
        {value}
      </p>
      {hint && <p className="text-xs text-[var(--cd-text-muted)]">{hint}</p>}
    </div>
  )
}

function SmallFact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs tracking-wide text-[var(--cd-text-muted)] uppercase">
        {label}
      </dt>
      <dd className="tabular mt-0.5 font-bold">{value}</dd>
    </div>
  )
}
