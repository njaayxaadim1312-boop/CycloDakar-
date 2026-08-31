import { formatDistance, formatDurationLong, formatInteger } from '@/lib/format'
import type { SportBreakdown } from '@/types/api'

interface SportBreakdownCardProps {
  bySport: Record<string, SportBreakdown>
}

/** Teinte de chaque sport — alignée sur les jetons de `tokens.css`. */
const SPORT_COLORS: Record<string, string> = {
  CYCLING: 'var(--cd-sport-cycling)',
  RUNNING: 'var(--cd-sport-running)',
  HIKING: 'var(--cd-sport-hiking)',
}

/**
 * Répartition par sport.
 *
 * Tous les sports sont affichés, y compris ceux à zéro : un sport qui
 * disparaîtrait de la liste dès qu'il n'est pas pratiqué laisserait croire
 * que le club ne le propose pas. La barre de proportion se calcule sur la
 * distance, la grandeur que les membres comparent spontanément.
 */
export function SportBreakdownCard({ bySport }: SportBreakdownCardProps) {
  const sports = Object.entries(bySport)
  const total = sports.reduce((sum, [, sport]) => sum + sport.distance_m, 0)

  return (
    <section className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="text-sm font-semibold text-[var(--cd-text)]">Par sport</h2>

      <ul className="mt-4 space-y-4">
        {sports.map(([code, sport]) => {
          const share = total > 0 ? (sport.distance_m / total) * 100 : 0

          return (
            <li key={code}>
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="text-[var(--cd-text)]">{sport.label}</span>
                <span className="tabular-nums font-medium text-[var(--cd-text)]">
                  {formatDistance(sport.distance_m)}
                </span>
              </div>

              <div
                className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-[var(--cd-surface-2)]"
                role="presentation"
              >
                <div
                  className="h-full rounded-full transition-[width]"
                  style={{
                    width: `${share}%`,
                    background: SPORT_COLORS[code] ?? 'var(--cd-orange)',
                  }}
                />
              </div>

              <p className="mt-1 text-xs text-[var(--cd-text-muted)]">
                {formatInteger(sport.activities)} sortie
                {sport.activities > 1 ? 's' : ''} · {formatDurationLong(sport.moving_time_s)}
              </p>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
