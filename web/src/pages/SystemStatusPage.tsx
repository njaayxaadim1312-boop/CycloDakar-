import { useQuery } from '@tanstack/react-query'
import {
  Bike,
  CheckCircle2,
  Database,
  Footprints,
  HardDrive,
  Moon,
  Mountain,
  Server,
  Sun,
  XCircle,
} from 'lucide-react'
import { Logo } from '@/components/Logo'
import { useTheme } from '@/hooks/useTheme'
import { getData } from '@/lib/api'
import { formatFcfa } from '@/lib/format'
import type { AppConfig, Health, SportCode } from '@/types/api'

/**
 * Écran de vérification de l'installation (phase 1).
 *
 * Il a une vraie utilité : il prouve d'un coup d'œil que le navigateur, Vite,
 * Laravel et MySQL communiquent, et il montre l'identité visuelle appliquée.
 * Il sera remplacé par le tableau de bord à la phase 4, et restera accessible
 * sous /system pour le diagnostic.
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
  const { isDark, toggle } = useTheme()

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

  return (
    <div className="min-h-dvh bg-[var(--cd-bg)]">
      <header className="sticky top-0 z-10 border-b border-[var(--cd-border)] bg-[var(--cd-surface)]/90 backdrop-blur">
        <div className="mx-auto flex h-[60px] max-w-5xl items-center justify-between px-4">
          <Logo size={36} withWordmark />
          <button
            type="button"
            onClick={toggle}
            className="cd-btn cd-btn-ghost !min-h-9 !px-3"
            aria-label={isDark ? 'Passer en thème clair' : 'Passer en thème sombre'}
          >
            {isDark ? <Sun size={16} /> : <Moon size={16} />}
            <span className="hidden sm:inline">{isDark ? 'Clair' : 'Sombre'}</span>
          </button>
        </div>
      </header>

      <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
        <section>
          <p className="text-sm font-semibold tracking-wide text-brand-text uppercase">
            Phase 1 — Initialisation
          </p>
          <h1 className="mt-1 text-3xl sm:text-4xl">
            La plateforme du club est en place
          </h1>
          <p className="mt-2 max-w-2xl text-[var(--cd-text-muted)]">
            Cette page vérifie en direct que le navigateur, Vite, l'API Laravel et
            MySQL communiquent. Elle laissera place au tableau de bord à la phase 4.
          </p>
        </section>

        {/* --- État des services -------------------------------------------- */}
        <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <StatusCard
            icon={Server}
            title="API Laravel"
            loading={health.isLoading}
            ok={health.isSuccess && health.data.status === 'healthy'}
            detail={
              health.isError
                ? "Injoignable — lancez 'php artisan serve' dans backend/"
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
                ? `${health.data.checks.database.latency_ms} ms`
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

        {/* --- Configuration métier chargée depuis l'API --------------------- */}
        <section className="cd-card p-5">
          <h2 className="text-lg">Configuration du club</h2>
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            Servie par <code className="text-brand-text">GET /api/v1/config</code> — le web et
            le mobile lisent les mêmes valeurs, jamais une copie locale.
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
                <Fact
                  label="Fuseau d'affichage"
                  value={config.data.club.timezone}
                />
                <Fact label="Monnaie" value={formatFcfa(5000)} />
                <Fact
                  label="Cartographie"
                  value={config.data.map.provider === 'osm' ? 'OpenStreetMap' : 'Mapbox'}
                />
              </dl>

              <h3 className="mt-6 text-sm font-semibold tracking-wide uppercase">
                Sports supportés
              </h3>
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
                          GPS {sport.sample_interval_s}s · précision ≤{' '}
                          {sport.max_accuracy_m} m
                        </span>
                      </span>
                    </li>
                  )
                })}
              </ul>

              <h3 className="mt-6 text-sm font-semibold tracking-wide uppercase">
                Moyens de paiement
              </h3>
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

        {/* --- Identité visuelle -------------------------------------------- */}
        <section className="cd-card p-5">
          <h2 className="text-lg">Identité visuelle</h2>
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            Extraite de la planche <code>assets/brand/prototype-design-system.jpg</code>.
          </p>

          <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Swatch name="Orange Cyclo" hex="#FF8C00" varName="--cd-orange" />
            <Swatch name="Noir Asphalte" hex="#1A1A1A" varName="--cd-black" />
            <Swatch name="Bleu Océan" hex="#004080" varName="--cd-blue" />
            <Swatch name="Vert Trace" hex="#32CD32" varName="--cd-green" />
          </div>

          <div className="mt-5 flex flex-wrap gap-3">
            <button type="button" className="cd-btn cd-btn-primary">
              Démarrer une sortie
            </button>
            <button type="button" className="cd-btn cd-btn-dark">
              Arrêter
            </button>
            <button type="button" className="cd-btn cd-btn-ocean">
              Prendre une photo
            </button>
            <button type="button" className="cd-btn cd-btn-ghost">
              Annuler
            </button>
            <button type="button" className="cd-btn" disabled>
              Indisponible
            </button>
          </div>
        </section>

        <footer className="pb-4 text-center text-xs text-[var(--cd-text-muted)]">
          Cyclo Dakar · Ensemble, plus loin, plus forts !
        </footer>
      </main>
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
      {detail && (
        <p className="mt-2 text-sm text-[var(--cd-text-muted)]">{detail}</p>
      )}
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

function Swatch({ name, hex, varName }: { name: string; hex: string; varName: string }) {
  return (
    <div>
      <div
        className="h-16 rounded-[var(--cd-radius)] border border-[var(--cd-border)]"
        style={{ backgroundColor: `var(${varName})` }}
      />
      <p className="mt-1.5 text-sm font-semibold">{name}</p>
      <p className="tabular text-xs text-[var(--cd-text-muted)]">{hex}</p>
    </div>
  )
}
