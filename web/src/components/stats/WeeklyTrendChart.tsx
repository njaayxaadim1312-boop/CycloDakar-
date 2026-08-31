import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { formatDistance } from '@/lib/format'
import type { WeeklyTrendPoint } from '@/types/api'

interface WeeklyTrendChartProps {
  trend: WeeklyTrendPoint[]
}

/**
 * Distance par semaine sur les douze dernières.
 *
 * Les semaines creuses sont tracées à zéro plutôt que sautées : une courbe qui
 * ne montrerait que les semaines actives donnerait une fausse impression de
 * régularité, alors que c'est précisément l'irrégularité que le membre a
 * besoin de voir.
 */
export function WeeklyTrendChart({ trend }: WeeklyTrendChartProps) {
  const total = trend.reduce((sum, point) => sum + point.distance_m, 0)

  const data = trend.map((point) => ({
    label: point.label,
    km: point.distance_m / 1000,
    activities: point.activities,
  }))

  return (
    <section className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="text-sm font-semibold text-[var(--cd-text)]">Douze dernières semaines</h2>

      {total === 0 ? (
        <p className="py-10 text-center text-sm text-[var(--cd-text-muted)]">
          Aucune sortie enregistrée sur les trois derniers mois.
        </p>
      ) : (
        <div className="mt-4 h-48">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={data} margin={{ top: 4, right: 8, bottom: 0, left: -18 }}>
              <CartesianGrid stroke="var(--cd-border)" vertical={false} />
              <XAxis
                dataKey="label"
                tick={{ fontSize: 10, fill: 'var(--cd-text-muted)' }}
                axisLine={false}
                tickLine={false}
                interval="preserveStartEnd"
              />
              <YAxis
                tick={{ fontSize: 10, fill: 'var(--cd-text-muted)' }}
                axisLine={false}
                tickLine={false}
                width={44}
                tickFormatter={(value: number) => `${value} km`}
              />
              <Tooltip
                cursor={{ fill: 'var(--cd-surface-2)' }}
                contentStyle={{
                  background: 'var(--cd-surface)',
                  border: '1px solid var(--cd-border)',
                  borderRadius: 12,
                  fontSize: 12,
                }}
                formatter={(value, _name, item) => {
                  // Recharts type la valeur en `ValueType | undefined` : le
                  // graphique accepte des séries hétérogènes, pas nous.
                  const km = typeof value === 'number' ? value : 0
                  const point = item.payload as { activities: number }

                  return [
                    `${formatDistance(km * 1000)} · ${point.activities} sortie(s)`,
                    'Semaine',
                  ]
                }}
              />
              {/* Littéral et non variable CSS : recharts peint sur un <svg>,
                  où `var()` n'est pas résolu dans l'attribut `fill`. */}
              <Bar dataKey="km" fill="#ff8c00" radius={[4, 4, 0, 0]} maxBarSize={28} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </section>
  )
}
