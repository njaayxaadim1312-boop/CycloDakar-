import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertCircle, Bike } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { PeriodFilter } from '@/components/stats/PeriodFilter'
import { RecordsCard } from '@/components/stats/RecordsCard'
import { SportBreakdownCard } from '@/components/stats/SportBreakdownCard'
import { TotalsCard } from '@/components/stats/TotalsCard'
import { WeeklyTrendChart } from '@/components/stats/WeeklyTrendChart'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import { fetchPersonalStats } from '@/lib/stats'
import type { StatsPeriod } from '@/types/api'

const PERIODS: StatsPeriod[] = ['week', 'month', 'year', 'all']

function readPeriod(raw: string | null): StatsPeriod {
  return PERIODS.find((period) => period === raw) ?? 'month'
}

/**
 * Mes statistiques — cumuls, répartition, tendance et records.
 *
 * La période vit dans l'URL plutôt que dans un état local : un membre qui
 * envoie « regarde mon année » à un camarade doit lui envoyer la bonne vue,
 * et le retour arrière du navigateur doit ramener la période précédente.
 *
 * `keepPreviousData` évite que la page se vide en changeant de période : les
 * chiffres restent affichés, légèrement estompés, pendant le rechargement.
 */
export function PersonalStatsPage() {
  const [params, setParams] = useSearchParams()
  const period = readPeriod(params.get('period'))

  const query = useQuery({
    queryKey: ['stats', 'me', period],
    queryFn: () => fetchPersonalStats(period),
    placeholderData: keepPreviousData,
  })

  function setPeriod(next: StatsPeriod) {
    const search = new URLSearchParams(params)
    next === 'month' ? search.delete('period') : search.set('period', next)
    setParams(search)
  }

  // Un compte sans fiche membre n'a pas de statistiques à afficher : ce n'est
  // pas une panne, et le dire clairement vaut mieux qu'un écran d'erreur.
  if (query.error instanceof ApiError && query.error.code === 'NO_MEMBER_PROFILE') {
    return (
      <div className="space-y-5">
        <PageHeader title="Mes statistiques" />
        <p className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-6 text-sm text-[var(--cd-text-muted)]">
          Votre compte n'est pas encore rattaché à une fiche membre. Contactez le
          bureau du club pour qu'il vous en crée une.
        </p>
      </div>
    )
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Mes statistiques"
        description="Vos cumuls, votre régularité et vos records personnels."
      />

      <PeriodFilter value={period} onChange={setPeriod} disabled={query.isLoading} />

      {query.isLoading && (
        <p className="text-sm text-[var(--cd-text-muted)]">Chargement de vos statistiques…</p>
      )}

      {query.error !== null && !(query.error instanceof ApiError && query.error.code === 'NO_MEMBER_PROFILE') && (
        <p className="flex items-center gap-2 rounded-2xl border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {query.error instanceof ApiError
            ? query.error.message
            : 'Impossible de charger vos statistiques.'}
        </p>
      )}

      {query.data !== undefined && (
        <div
          className={`space-y-5 transition-opacity ${query.isFetching ? 'opacity-60' : ''}`}
        >
          <TotalsCard totals={query.data.totals} periodLabel={query.data.period_label} />

          <div className="grid gap-5 lg:grid-cols-3">
            <div className="lg:col-span-2">
              <WeeklyTrendChart trend={query.data.trend} />
            </div>
            <SportBreakdownCard bySport={query.data.by_sport} />
          </div>

          <RecordsCard records={query.data.records} />

          <p className="text-center text-sm">
            <Link
              to="/activities?mine=1"
              className="inline-flex items-center gap-1.5 text-[var(--cd-orange-text)] hover:underline"
            >
              <Bike size={15} aria-hidden="true" />
              Voir toutes mes sorties
            </Link>
          </p>
        </div>
      )}
    </div>
  )
}
