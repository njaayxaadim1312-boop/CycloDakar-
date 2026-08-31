import {
  Bar,
  BarChart,
  Cell,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { formatPace, formatSpeed } from '@/lib/format'
import type { ActivitySplit } from '@/types/api'

interface SplitsChartProps {
  splits: ActivitySplit[]
  /** Course et randonnée raisonnent en allure, cyclisme en vitesse. */
  usesPace: boolean
}

/**
 * Splits kilométriques.
 *
 * Le graphique que les sportifs regardent en premier : il montre où l'on a
 * accéléré et où l'on a faibli. Le kilomètre le plus rapide est mis en orange
 * — c'est celui dont on est fier.
 *
 * Pour l'allure, l'échelle est **inversée** : une allure plus basse est
 * meilleure, donc une barre plus haute doit représenter une meilleure
 * performance. Sans cette inversion, le meilleur kilomètre serait le plus
 * petit, ce qui se lit à l'envers de l'intuition.
 */
export function SplitsChart({ splits, usesPace }: SplitsChartProps) {
  if (splits.length === 0) {
    return (
      <p className="py-8 text-center text-sm text-[var(--cd-text-muted)]">
        Sortie de moins d'un kilomètre — pas de découpage possible.
      </p>
    )
  }

  const best = usesPace
    ? Math.min(...splits.map((s) => s.pace_s_per_km))
    : Math.max(...splits.map((s) => s.speed_mps))

  const data = splits.map((split) => ({
    km: split.km,
    // On trace toujours la vitesse : c'est elle qui croît avec la performance,
    // quel que soit l'affichage choisi.
    value: split.speed_mps,
    pace: split.pace_s_per_km,
    isBest: usesPace ? split.pace_s_per_km === best : split.speed_mps === best,
  }))

  return (
    <div className="h-48">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 4, right: 8, bottom: 0, left: -20 }}>
          <XAxis
            dataKey="km"
            tickFormatter={(value: number) => `${value}`}
            tick={{ fontSize: 11, fill: 'var(--cd-text-muted)' }}
            axisLine={false}
            tickLine={false}
          />
          <YAxis
            tickFormatter={(value: number) =>
              usesPace ? formatPace(1000 / value) : `${Math.round(value * 3.6)}`
            }
            tick={{ fontSize: 11, fill: 'var(--cd-text-muted)' }}
            axisLine={false}
            tickLine={false}
            width={54}
          />
          <Tooltip
            cursor={{ fill: 'var(--cd-orange-soft)' }}
            contentStyle={{
              backgroundColor: 'var(--cd-surface)',
              border: '1px solid var(--cd-border)',
              borderRadius: 'var(--cd-radius-sm)',
              fontSize: 13,
            }}
            labelFormatter={(value) => `Kilomètre ${value}`}
            formatter={(value, _name, item) => [
              usesPace
                ? formatPace((item.payload as { pace: number }).pace)
                : formatSpeed(Number(value)),
              usesPace ? 'Allure' : 'Vitesse',
            ]}
          />
          <Bar dataKey="value" radius={[4, 4, 0, 0]} maxBarSize={40}>
            {data.map((entry) => (
              <Cell
                key={entry.km}
                fill={entry.isBest ? 'var(--cd-orange)' : 'var(--cd-border-strong)'}
              />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}
