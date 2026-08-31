import { useQuery } from '@tanstack/react-query'
import {
  Bike,
  CalendarDays,
  CircleAlert,
  CircleCheck,
  Route,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import { getData } from '@/lib/api'
import { useCurrentUser } from '@/stores/auth'
import type { Health } from '@/types/api'

/**
 * Tableau de bord du club.
 *
 * Les indicateurs affichent « — » tant que les modules qui les alimentent ne
 * sont pas livrés. C'est délibéré : afficher des chiffres inventés sur un
 * tableau de bord financier serait le plus sûr moyen de perdre la confiance du
 * bureau. Chaque tuile annonce la phase qui lui donnera sa valeur.
 */
export function DashboardPage() {
  const user = useCurrentUser()

  const health = useQuery({
    queryKey: ['health'],
    queryFn: () => getData<Health>('/health'),
    retry: 1,
    refetchInterval: 60_000,
  })

  const serverUp = health.isSuccess && health.data.status === 'healthy'

  return (
    <div className="space-y-6">
      {/* --- Bandeau d'accueil -------------------------------------------- */}
      <section className="overflow-hidden rounded-[var(--cd-radius-lg)] bg-[var(--cd-orange)] px-5 py-6 sm:px-7 sm:py-8">
        <p className="text-xs font-bold tracking-[0.1em] text-black/60 uppercase">
          Saison 2026 · {user?.role_label}
        </p>
        <h2 className="mt-1 text-2xl text-[var(--cd-black)] sm:text-3xl">
          {/* Le prenom seul : plus chaleureux, et plus court sur mobile. */}
          Bonjour {user?.name.split(' ')[0] ?? ''}
        </h2>
        <p className="mt-2 max-w-2xl text-sm leading-relaxed text-black/75">
          Sorties GPS, événements, participations et caisse réunis au même endroit.
          La base technique est en place ; les modules arrivent phase par phase.
        </p>
      </section>

      {/* --- Indicateurs --------------------------------------------------- */}
      <section>
        <h3 className="mb-3 text-sm font-bold tracking-wide text-[var(--cd-text-muted)] uppercase">
          Indicateurs du club
        </h3>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <StatTile icon={Users} label="Membres" phase={3} to="/members" />
          <StatTile icon={Bike} label="Activités" phase={8} to="/activities" />
          <StatTile icon={Route} label="Distance totale" phase={8} to="/activities" />
          <StatTile icon={CalendarDays} label="Événements" phase={9} to="/events" />
          <StatTile icon={Wallet} label="Solde de caisse" phase={13} to="/finance" accent />
          <StatTile icon={Wallet} label="Reste à collecter" phase={12} to="/participations" />
        </div>
      </section>

      {/* --- État des services + avancement -------------------------------- */}
      <section className="grid gap-4 lg:grid-cols-[1fr_1.4fr]">
        <article className="cd-card p-5">
          <h3 className="text-base font-bold">État des services</h3>

          <div className="mt-3 flex items-center gap-2.5">
            {health.isLoading ? (
              <span className="size-5 animate-pulse rounded-full bg-[var(--cd-border-strong)]" />
            ) : serverUp ? (
              <CircleCheck size={20} className="text-[var(--cd-green)]" />
            ) : (
              <CircleAlert size={20} className="text-[var(--cd-danger)]" />
            )}
            <span className="text-sm font-semibold">
              {health.isLoading
                ? 'Vérification…'
                : serverUp
                  ? 'Tous les services répondent'
                  : 'Un service ne répond pas'}
            </span>
          </div>

          {health.data && (
            <dl className="mt-4 space-y-2 text-sm">
              <Row label="API Laravel" value={health.data.laravel} />
              <Row label="PHP" value={health.data.php} />
              <Row
                label="Base de données"
                value={
                  health.data.checks.database.ok
                    ? `Connectée · ${health.data.checks.database.latency_ms ?? '—'} ms`
                    : 'En échec'
                }
              />
            </dl>
          )}

          {health.isError && (
            <p className="mt-3 rounded-[var(--cd-radius-sm)] bg-[var(--cd-danger-soft)] p-3 text-sm text-[var(--cd-danger)]">
              API injoignable. Lancez <code>php artisan serve</code> dans <code>backend/</code>.
            </p>
          )}

          <Link
            to="/system"
            className="mt-4 inline-block text-sm font-semibold text-brand-text hover:underline"
          >
            Diagnostic détaillé →
          </Link>
        </article>

        <article className="cd-card p-5">
          <h3 className="text-base font-bold">Avancement du projet</h3>
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            Développement par phases : chacune est livrée complète et testée avant
            la suivante.
          </p>

          <ol className="mt-4 space-y-2.5">
            <PhaseRow n={1} label="Initialisation, structure, environnement" done />
            <PhaseRow n={2} label="Authentification" done />
            <PhaseRow n={3} label="Membres, rôles et QR Code" />
            <PhaseRow n={6} label="GPS et enregistrement des sorties" />
            <PhaseRow n={12} label="Paiements et encaissements" />
            <PhaseRow n={13} label="Recettes, dépenses et caisse" />
          </ol>
        </article>
      </section>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

interface StatTileProps {
  icon: LucideIcon
  label: string
  phase: number
  to: string
  accent?: boolean
}

function StatTile({ icon: Icon, label, phase, to, accent }: StatTileProps) {
  return (
    <Link
      to={to}
      className="cd-card block p-4 transition-colors hover:border-[var(--cd-orange)]"
    >
      <div className="flex items-start justify-between gap-2">
        <span className="text-sm font-semibold text-[var(--cd-text-muted)]">{label}</span>
        <Icon
          size={18}
          className={accent ? 'text-[var(--cd-orange)]' : 'text-[var(--cd-text-muted)]'}
        />
      </div>
      <p className="tabular mt-2 text-3xl font-extrabold text-[var(--cd-border-strong)]">—</p>
      <p className="mt-0.5 text-xs text-[var(--cd-text-muted)]">
        Disponible en phase {phase}
      </p>
    </Link>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-[var(--cd-text-muted)]">{label}</dt>
      <dd className="tabular font-semibold">{value}</dd>
    </div>
  )
}

function PhaseRow({ n, label, done }: { n: number; label: string; done?: boolean }) {
  return (
    <li className="flex items-center gap-3">
      <span
        className={
          done
            ? 'flex size-6 shrink-0 items-center justify-center rounded-full bg-[var(--cd-green)] text-[0.6875rem] font-bold text-white'
            : 'flex size-6 shrink-0 items-center justify-center rounded-full bg-[var(--cd-surface-2)] text-[0.6875rem] font-bold text-[var(--cd-text-muted)]'
        }
      >
        {n}
      </span>
      <span className={done ? 'text-sm font-semibold' : 'text-sm text-[var(--cd-text-muted)]'}>
        {label}
      </span>
      {done && (
        <span className="cd-badge ml-auto bg-[var(--cd-green-soft)] text-[var(--cd-green-hover)]">
          Terminée
        </span>
      )}
    </li>
  )
}
