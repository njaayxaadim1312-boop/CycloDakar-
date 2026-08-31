import type { StatsPeriod } from '@/types/api'

/**
 * Sélecteur de période.
 *
 * Rendu en boutons plutôt qu'en menu déroulant : quatre choix seulement, et
 * le membre doit voir d'un coup d'œil sur quelle période il se regarde. Un
 * menu fermé cacherait cette information, et un cumul lu sans sa période est
 * un chiffre trompeur.
 */

const PERIODS: ReadonlyArray<{ value: StatsPeriod; label: string; short: string }> = [
  { value: 'week', label: 'Cette semaine', short: 'Semaine' },
  { value: 'month', label: 'Ce mois-ci', short: 'Mois' },
  { value: 'year', label: 'Cette année', short: 'Année' },
  { value: 'all', label: 'Depuis toujours', short: 'Tout' },
]

interface PeriodFilterProps {
  value: StatsPeriod
  onChange: (period: StatsPeriod) => void
  disabled?: boolean
}

export function PeriodFilter({ value, onChange, disabled = false }: PeriodFilterProps) {
  return (
    <div
      role="group"
      aria-label="Période"
      className="inline-flex rounded-full border border-[var(--cd-border)] bg-[var(--cd-surface)] p-1"
    >
      {PERIODS.map((period) => {
        const active = period.value === value

        return (
          <button
            key={period.value}
            type="button"
            onClick={() => onChange(period.value)}
            disabled={disabled}
            aria-pressed={active}
            title={period.label}
            className={[
              'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
              'disabled:cursor-not-allowed disabled:opacity-60',
              active
                ? 'bg-[var(--cd-orange)] text-[var(--cd-black)]'
                : 'text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]',
            ].join(' ')}
          >
            {period.short}
          </button>
        )
      })}
    </div>
  )
}
