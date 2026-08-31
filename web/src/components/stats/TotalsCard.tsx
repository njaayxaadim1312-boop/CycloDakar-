import { Bike, Clock, Mountain, Route, TrendingUp } from 'lucide-react'
import {
  formatDistance,
  formatDurationLong,
  formatElevation,
  formatInteger,
  formatSpeed,
} from '@/lib/format'
import type { PersonalTotals } from '@/types/api'

interface TotalsCardProps {
  totals: PersonalTotals
  periodLabel: string
}

/**
 * Cumuls de la période.
 *
 * Cinq chiffres, pas davantage : distance, temps, sorties, dénivelé, moyenne.
 * Une carte qui en afficherait quinze ne serait plus lue. Ce sont exactement
 * ceux qu'un membre du club cite quand on lui demande « t'as fait quoi ce
 * mois-ci ».
 */
export function TotalsCard({ totals, periodLabel }: TotalsCardProps) {
  const empty = totals.activities === 0

  const entries = [
    {
      icon: Route,
      label: 'Distance',
      value: formatDistance(totals.distance_m),
      primary: true,
    },
    {
      icon: Clock,
      label: 'Temps en mouvement',
      value: formatDurationLong(totals.moving_time_s),
      primary: true,
    },
    {
      icon: Bike,
      label: 'Sorties',
      value: formatInteger(totals.activities),
      primary: false,
    },
    {
      icon: Mountain,
      label: 'Dénivelé positif',
      value: formatElevation(totals.elevation_gain_m),
      primary: false,
    },
    {
      icon: TrendingUp,
      label: 'Vitesse moyenne',
      value: formatSpeed(totals.avg_speed_mps),
      primary: false,
    },
  ]

  return (
    <section className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="text-sm font-semibold text-[var(--cd-text-muted)]">{periodLabel}</h2>

      {empty ? (
        <p className="mt-4 text-sm text-[var(--cd-text-muted)]">
          Aucune sortie sur cette période. Enregistrez-en une depuis l'application mobile.
        </p>
      ) : (
        <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {entries.map((entry) => (
            <div key={entry.label}>
              <dt className="flex items-center gap-1.5 text-xs text-[var(--cd-text-muted)]">
                <entry.icon size={14} aria-hidden="true" />
                {entry.label}
              </dt>
              <dd
                className={[
                  'mt-1 tabular-nums font-semibold',
                  entry.primary
                    ? 'text-2xl text-[var(--cd-orange-text)]'
                    : 'text-xl text-[var(--cd-text)]',
                ].join(' ')}
              >
                {entry.value}
              </dd>
            </div>
          ))}
        </dl>
      )}
    </section>
  )
}
