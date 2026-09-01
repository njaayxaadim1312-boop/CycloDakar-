import { useQuery } from '@tanstack/react-query'
import {
  Bike,
  CalendarDays,
  CircleAlert,
  CircleCheck,
  Clock,
  Route,
  SmartphoneNfc,
  UserPlus,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { getData } from '@/lib/api'
import { formatDistance, formatDurationLong, formatInteger } from '@/lib/format'
import { fetchDashboardStats } from '@/lib/stats'
import { useCurrentUser } from '@/stores/auth'
import type { Health, MemberStatusCode } from '@/types/api'

/**
 * Tableau de bord du club.
 *
 * Règle qui gouverne cet écran : **on n'affiche jamais un chiffre inventé**.
 * Les effectifs sont réels ; les modules à venir portent explicitement la
 * mention de leur phase et un tiret, jamais un zéro. Sur un tableau de bord
 * qui affichera un jour un solde de caisse, confondre « rien » et « pas encore
 * mesuré » suffirait à ruiner la confiance du bureau.
 */
export function DashboardPage() {
  const user = useCurrentUser()

  const stats = useQuery({
    queryKey: ['stats', 'dashboard'],
    queryFn: fetchDashboardStats,
  })

  const health = useQuery({
    queryKey: ['health'],
    queryFn: () => getData<Health>('/health'),
    retry: 1,
    refetchInterval: 60_000,
  })

  const members = stats.data?.members
  const serverUp = health.isSuccess && health.data.status === 'healthy'

  return (
    <div className="space-y-6">
      {/* --- Bandeau d'accueil -------------------------------------------- */}
      <section className="overflow-hidden rounded-[var(--cd-radius-lg)] bg-[var(--cd-orange)] px-5 py-6 sm:px-7 sm:py-8">
        <p className="text-xs font-bold tracking-[0.1em] text-black/60 uppercase">
          Saison 2026 · {user?.role_label}
        </p>
        <h2 className="mt-1 text-2xl text-[var(--cd-black)] sm:text-3xl">
          {/* Le prénom seul : plus chaleureux, et plus court sur mobile. */}
          Bonjour {user?.name.split(' ')[0] ?? ''}
        </h2>
        <p className="mt-2 max-w-2xl text-sm leading-relaxed text-black/75">
          Sorties GPS, événements, participations et caisse réunis au même endroit.
        </p>
      </section>

      {/* --- Indicateurs --------------------------------------------------- */}
      <section>
        <h3 className="mb-3 text-sm font-bold tracking-wide text-[var(--cd-text-muted)] uppercase">
          Indicateurs du club
        </h3>

        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <StatTile
            icon={Users}
            label="Membres actifs"
            to="/members?status=ACTIVE"
            value={members?.active}
            hint={members ? `${members.total} au total, tous statuts` : undefined}
            loading={stats.isLoading}
            accent
          />
          <StatTile
            icon={UserPlus}
            label="Adhésions ce mois-ci"
            to="/members?sort=recent"
            value={members?.joined_this_month}
            hint="Depuis le 1er du mois"
            loading={stats.isLoading}
          />
          <StatTile
            icon={SmartphoneNfc}
            label="Membres sans compte"
            to="/members?has_account=0"
            value={members?.without_account}
            hint="QR Code à remettre imprimé"
            loading={stats.isLoading}
          />
          <StatTile
            icon={Bike}
            label="Activités"
            to="/activities"
            value={
              stats.data === undefined
                ? undefined
                : formatInteger(stats.data.activities.total)
            }
            hint={
              stats.data === undefined
                ? undefined
                : `${formatInteger(stats.data.activities.this_month)} ce mois-ci`
            }
            loading={stats.isLoading}
          />
          <StatTile
            icon={Route}
            label="Distance totale"
            to="/activities"
            value={
              stats.data === undefined
                ? undefined
                : formatDistance(stats.data.activities.distance_m)
            }
            hint={
              stats.data === undefined
                ? undefined
                : `${formatDurationLong(stats.data.activities.moving_time_s)} en selle`
            }
            loading={stats.isLoading}
          />
          <StatTile
            icon={CalendarDays}
            label="Sorties à venir"
            to="/events"
            value={stats.data?.events.upcoming}
            hint={
              stats.data === undefined
                ? undefined
                : stats.data.events.next !== null
                  ? `Prochaine : ${stats.data.events.next.title}`
                  : 'Aucune sortie au calendrier'
            }
            loading={stats.isLoading}
          />
          {stats.data?.finance.visible && (
            <StatTile
              icon={Wallet}
              label="Solde de caisse"
              to="/finance"
              phase={stats.data.finance.phase}
              loading={stats.isLoading}
            />
          )}
          <StatTile
            icon={Wallet}
            label="Reste à collecter"
            to="/participations"
            phase={stats.data?.participations.phase}
            loading={stats.isLoading}
          />
        </div>
      </section>

      {/* --- Répartitions -------------------------------------------------- */}
      {members && (
        <section className="grid gap-4 lg:grid-cols-2">
          <article className="cd-card p-5">
            <h3 className="text-base font-bold">Effectif par statut</h3>
            <ul className="mt-4 space-y-3">
              {(
                Object.entries(members.by_status) as [
                  MemberStatusCode,
                  { label: string; count: number },
                ][]
              ).map(([code, entry]) => (
                <li key={code}>
                  <div className="flex items-baseline justify-between gap-3 text-sm">
                    <span className="font-medium">{entry.label}</span>
                    <span className="tabular font-bold">{entry.count}</span>
                  </div>
                  {/* Barre décorative : le chiffre est écrit juste à côté, elle
                      n'est donc pas seule porteuse de l'information. */}
                  <div
                    className="mt-1 h-2 overflow-hidden rounded-full bg-[var(--cd-surface-2)]"
                    role="presentation"
                  >
                    <div
                      className="h-full rounded-full transition-[width] duration-500"
                      style={{
                        width: `${members.total > 0 ? (entry.count / members.total) * 100 : 0}%`,
                        backgroundColor: STATUS_COLOR[code],
                      }}
                    />
                  </div>
                </li>
              ))}
            </ul>
          </article>

          <article className="cd-card p-5">
            <h3 className="text-base font-bold">Adhésions sur 12 mois</h3>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              Les mois sans adhésion sont affichés à zéro, pas masqués.
            </p>

            <div className="mt-4 h-52">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={members.growth}
                  margin={{ top: 4, right: 4, bottom: 0, left: -24 }}
                >
                  <CartesianGrid
                    strokeDasharray="3 3"
                    vertical={false}
                    stroke="var(--cd-border)"
                  />
                  <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11, fill: 'var(--cd-text-muted)' }}
                    axisLine={false}
                    tickLine={false}
                    interval="preserveStartEnd"
                  />
                  <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: 'var(--cd-text-muted)' }}
                    axisLine={false}
                    tickLine={false}
                  />
                  <Tooltip
                    cursor={{ fill: 'var(--cd-orange-soft)' }}
                    contentStyle={{
                      backgroundColor: 'var(--cd-surface)',
                      border: '1px solid var(--cd-border)',
                      borderRadius: 'var(--cd-radius-sm)',
                      fontSize: 13,
                    }}
                    labelStyle={{ color: 'var(--cd-text)', fontWeight: 600 }}
                    formatter={(value) => [
                      `${value} adhésion${Number(value) > 1 ? 's' : ''}`,
                      '',
                    ]}
                  />
                  <Bar
                    dataKey="count"
                    fill="var(--cd-orange)"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={28}
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </article>
        </section>
      )}

      {/* --- Rôles + état des services ------------------------------------- */}
      <section className="grid gap-4 lg:grid-cols-2">
        {members && (
          <article className="cd-card p-5">
            <h3 className="text-base font-bold">Rôles attribués</h3>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              {members.with_account} membre{members.with_account > 1 ? 's' : ''} avec
              un compte de connexion, {members.without_account} sans.
            </p>
            <dl className="mt-4 space-y-2 text-sm">
              {Object.entries(members.by_role).map(([code, entry]) => (
                <div key={code} className="flex items-baseline justify-between gap-3">
                  <dt className="text-[var(--cd-text-muted)]">{entry.label}</dt>
                  <dd className="tabular font-semibold">{entry.count}</dd>
                </div>
              ))}
            </dl>
            <Link
              to="/members"
              className="mt-4 inline-block text-sm font-semibold text-brand-text hover:underline"
            >
              Voir l'annuaire →
            </Link>
          </article>
        )}

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
              API injoignable. Lancez <code>php artisan serve</code> dans{' '}
              <code>backend/</code>.
            </p>
          )}

          {stats.data && (
            <p className="mt-4 flex items-center gap-1.5 text-xs text-[var(--cd-text-muted)]">
              <Clock size={13} />
              Chiffres arrêtés au{' '}
              {new Date(stats.data.generated_at).toLocaleString('fr-FR', {
                dateStyle: 'short',
                timeStyle: 'short',
                timeZone: 'Africa/Dakar',
              })}
            </p>
          )}

          <Link
            to="/system"
            className="mt-3 inline-block text-sm font-semibold text-brand-text hover:underline"
          >
            Diagnostic détaillé →
          </Link>
        </article>
      </section>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

const STATUS_COLOR: Record<MemberStatusCode, string> = {
  ACTIVE: 'var(--cd-green)',
  PENDING: 'var(--cd-warning)',
  SUSPENDED: 'var(--cd-danger)',
  FORMER: 'var(--cd-border-strong)',
}

interface StatTileProps {
  icon: LucideIcon
  label: string
  to: string
  /**
   * Valeur réelle, déjà formatée quand elle porte une unité (« 1 240 km »).
   * Absente tant que le module n'est pas livré — c'est ce qui déclenche
   * l'affichage du tiret et de la phase.
   */
  value?: number | string
  hint?: string
  /** Phase qui livrera la valeur — affichée à la place du chiffre. */
  phase?: number
  loading?: boolean
  accent?: boolean
}

function StatTile({
  icon: Icon,
  label,
  to,
  value,
  hint,
  phase,
  loading,
  accent,
}: StatTileProps) {
  const hasValue = value !== undefined

  return (
    <Link
      to={to}
      className="cd-card block p-4 transition-colors hover:border-[var(--cd-orange)]"
    >
      <div className="flex items-start justify-between gap-2">
        <span className="text-sm font-semibold text-[var(--cd-text-muted)]">
          {label}
        </span>
        <Icon
          size={18}
          className={accent ? 'text-[var(--cd-orange)]' : 'text-[var(--cd-text-muted)]'}
        />
      </div>

      {loading ? (
        <span className="mt-2.5 block h-8 w-16 animate-pulse rounded bg-[var(--cd-surface-2)]" />
      ) : (
        <p
          className={
            hasValue
              ? 'tabular mt-2 text-3xl font-extrabold'
              : 'tabular mt-2 text-3xl font-extrabold text-[var(--cd-border-strong)]'
          }
        >
          {hasValue ? value : '—'}
        </p>
      )}

      <p className="mt-0.5 min-h-4 text-xs text-[var(--cd-text-muted)]">
        {hasValue ? hint : phase ? `Disponible en phase ${phase}` : null}
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
