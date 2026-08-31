import { useQuery } from '@tanstack/react-query'
import {
  Bike,
  CheckCircle2,
  Database,
  Footprints,
  HardDrive,
  Mountain,
  RefreshCw,
  Server,
  XCircle,
} from 'lucide-react'
import { getData } from '@/lib/api'
import { formatFcfa } from '@/lib/format'
import type { AppConfig, Health, SportCode } from '@/types/api'

/**
 * Diagnostic en direct de l'installation.
 *
 * Il vérifie d'un coup d'œil que le navigateur, Vite, l'API Laravel et MySQL
 * communiquent — c'est le premier écran à consulter quand quelque chose ne
 * répond plus.
 */

const SPORT_ICONS: Record<SportCode, typeof Bike> = {
  CYCLING: Bike,
  RUNNING: Footprints,
  HIKING: Mountain,
}

const SPORT_COLORS: Record<SportCode, string> = {
  CYCLING: 'var(--cd-sport-cycling)',
  RUNNING: 'var(--cd-sport-running)',
  HIKING: 'var(--cd-sport-hiking)',
}

export function SystemStatusPage() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: () => getData<Health>('/health'),
    retry: 1,
    refetchInterval: 30_000,
  })

  const config = useQuery({
    queryKey: ['config'],
    queryFn: () => getData<AppConfig>('/config'),
    retry: 1,
    // La configuration métier ne change pas pendant une session.
    staleTime: 5 * 60_000,
  })

  const refreshing = health.isFetching || config.isFetching

  return (
    <div className="space-y-6">
      <section className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-2xl">Diagnostic de la plateforme</h2>
          <p className="mt-1 max-w-2xl text-sm text-[var(--cd-text-muted)]">
            Vérification en direct de la liaison entre le navigateur, l'API Laravel,
            la base MySQL et le stockage des fichiers.
          </p>
        </div>
        <button
          type="button"
          onClick={() => {
            void health.refetch()
            void config.refetch()
          }}
          disabled={refreshing}
          className="cd-btn cd-btn-ghost !min-h-9"
        >
          <RefreshCw size={15} className={refreshing ? 'animate-spin' : undefined} />
          Actualiser
        </button>
      </section>

      {/* --- État des services -------------------------------------------- */}
      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatusCard
          icon={Server}
          title="API Laravel"
          loading={health.isLoading}
          ok={health.isSuccess && health.data.status === 'healthy'}
          detail={
            health.isError
              ? "Injoignable — lancez « php artisan serve » dans backend/"
              : health.data
                ? `Laravel ${health.data.laravel} · PHP ${health.data.php}`
                : undefined
          }
        />
        <StatusCard
          icon={Database}
          title="Base de données"
          loading={health.isLoading}
          ok={health.data?.checks.database.ok ?? false}
          detail={health.data?.checks.database.message}
          extra={
            health.data?.checks.database.latency_ms !== undefined
              ? `Latence ${health.data.checks.database.latency_ms} ms`
              : undefined
          }
        />
        <StatusCard
          icon={HardDrive}
          title="Stockage"
          loading={health.isLoading}
          ok={health.data?.checks.storage.ok ?? false}
          detail={health.data?.checks.storage.message}
        />
      </section>

      {/* --- Configuration métier ------------------------------------------ */}
      <section className="cd-card p-5">
        <h3 className="text-lg">Configuration du club</h3>
        <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
          Servie par <code className="text-brand-text">GET /api/v1/config</code> — le web
          et le mobile lisent les mêmes valeurs, jamais une copie locale.
        </p>

        {config.isLoading && (
          <p className="mt-4 text-sm text-[var(--cd-text-muted)]">Chargement…</p>
        )}

        {config.isError && (
          <p className="mt-4 text-sm text-[var(--cd-danger)]">
            Configuration indisponible : l'API ne répond pas.
          </p>
        )}

        {config.data && (
          <>
            <dl className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Fact label="Club" value={config.data.club.name} />
              <Fact label="Fuseau d'affichage" value={config.data.club.timezone} />
              <Fact label="Monnaie" value={formatFcfa(5000)} />
              <Fact
                label="Cartographie"
                value={config.data.map.provider === 'osm' ? 'OpenStreetMap' : 'Mapbox'}
              />
            </dl>

            <h4 className="mt-6 text-sm font-bold tracking-wide uppercase">
              Sports supportés
            </h4>
            <ul className="mt-3 grid gap-3 sm:grid-cols-3">
              {config.data.sports.map((sport) => {
                const Icon = SPORT_ICONS[sport.code] ?? Bike
                return (
                  <li
                    key={sport.code}
                    className="flex items-center gap-3 rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-surface-2)] p-3"
                  >
                    <span
                      className="flex size-10 shrink-0 items-center justify-center rounded-full"
                      style={{ backgroundColor: SPORT_COLORS[sport.code], color: '#fff' }}
                    >
                      <Icon size={20} />
                    </span>
                    <span className="min-w-0">
                      <span className="block font-semibold">{sport.label}</span>
                      <span className="tabular block text-xs text-[var(--cd-text-muted)]">
                        GPS {sport.sample_interval_s}s · précision ≤ {sport.max_accuracy_m} m
                      </span>
                    </span>
                  </li>
                )
              })}
            </ul>

            <h4 className="mt-6 text-sm font-bold tracking-wide uppercase">
              Moyens de paiement
            </h4>
            <ul className="mt-3 flex flex-wrap gap-2">
              {config.data.payment_methods.map((method) => (
                <li
                  key={method.code}
                  className="cd-badge bg-[var(--cd-surface-2)] text-[var(--cd-text)]"
                >
                  {method.label}
                </li>
              ))}
            </ul>
          </>
        )}
      </section>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

interface StatusCardProps {
  icon: typeof Server
  title: string
  ok: boolean
  loading: boolean
  detail?: string
  extra?: string
}

function StatusCard({ icon: Icon, title, ok, loading, detail, extra }: StatusCardProps) {
  return (
    <article className="cd-card p-4">
      <div className="flex items-start justify-between gap-3">
        <span className="flex items-center gap-2 font-semibold">
          <Icon size={18} className="text-[var(--cd-text-muted)]" />
          {title}
        </span>
        {loading ? (
          <span
            className="size-5 animate-pulse rounded-full bg-[var(--cd-border-strong)]"
            aria-label="Vérification en cours"
          />
        ) : ok ? (
          <CheckCircle2 size={20} className="text-[var(--cd-green)]" aria-label="Opérationnel" />
        ) : (
          <XCircle size={20} className="text-[var(--cd-danger)]" aria-label="En échec" />
        )}
      </div>
      {detail && <p className="mt-2 text-sm text-[var(--cd-text-muted)]">{detail}</p>}
      {extra && <p className="tabular mt-1 text-xs text-[var(--cd-text-muted)]">{extra}</p>}
    </article>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs tracking-wide text-[var(--cd-text-muted)] uppercase">{label}</dt>
      <dd className="mt-0.5 font-semibold">{value}</dd>
    </div>
  )
}
