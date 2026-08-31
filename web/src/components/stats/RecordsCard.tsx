import { Gauge, Mountain, Route, Timer, Trophy } from 'lucide-react'
import { Link } from 'react-router-dom'
import {
  formatDate,
  formatDistance,
  formatDurationLong,
  formatElevation,
  formatPace,
  formatSpeed,
} from '@/lib/format'
import type { PersonalRecord, PersonalRecords } from '@/types/api'

interface RecordsCardProps {
  records: PersonalRecords
}

/**
 * Records personnels.
 *
 * Deux partis pris :
 *
 * - Les records portent sur **toute la carrière**, pas sur la période choisie
 *   plus haut. Un record du mois n'est pas un record, et l'en-tête le dit
 *   explicitement pour qu'on ne s'y trompe pas en changeant de filtre.
 * - Un record absent affiche un **tiret**, jamais un zéro. « 0 m de dénivelé »
 *   se lirait comme une performance mesurée ; ce n'en est pas une.
 *
 * Chaque record est cliquable : le membre veut revoir la sortie dont il est
 * fier, pas seulement le chiffre.
 */

interface RecordRow {
  key: keyof PersonalRecords
  icon: typeof Trophy
  label: string
  format: (value: number) => string
}

const ROWS: readonly RecordRow[] = [
  { key: 'longest_distance', icon: Route, label: 'Plus longue sortie', format: formatDistance },
  { key: 'longest_duration', icon: Timer, label: 'Plus longue durée', format: formatDurationLong },
  { key: 'max_speed', icon: Gauge, label: 'Vitesse maximale', format: formatSpeed },
  { key: 'most_elevation', icon: Mountain, label: 'Plus gros dénivelé', format: formatElevation },
  { key: 'best_pace', icon: Trophy, label: 'Meilleure allure', format: formatPace },
]

export function RecordsCard({ records }: RecordsCardProps) {
  return (
    <section className="rounded-2xl border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="flex items-center gap-2 text-sm font-semibold text-[var(--cd-text)]">
        <Trophy size={16} className="text-[var(--cd-orange-text)]" aria-hidden="true" />
        Records personnels
      </h2>
      <p className="mt-1 text-xs text-[var(--cd-text-muted)]">
        Sur toute votre carrière au club, indépendamment de la période affichée.
      </p>

      <ul className="mt-4 divide-y divide-[var(--cd-border)]">
        {ROWS.map((row) => (
          <RecordLine key={row.key} row={row} record={records[row.key]} />
        ))}
      </ul>
    </section>
  )
}

function RecordLine({ row, record }: { row: RecordRow; record: PersonalRecord | null }) {
  const Icon = row.icon

  if (record === null) {
    return (
      <li className="flex items-center justify-between gap-4 py-3">
        <span className="flex items-center gap-2 text-sm text-[var(--cd-text-muted)]">
          <Icon size={15} aria-hidden="true" />
          {row.label}
        </span>
        <span className="text-sm text-[var(--cd-text-muted)]" title="Pas encore établi">
          —
        </span>
      </li>
    )
  }

  return (
    <li className="py-3">
      <Link
        to={`/activities/${record.activity_uuid}`}
        className="flex items-center justify-between gap-4 rounded-lg hover:bg-[var(--cd-surface-2)]"
      >
        <span className="min-w-0">
          <span className="flex items-center gap-2 text-sm text-[var(--cd-text)]">
            <Icon size={15} className="shrink-0 text-[var(--cd-text-muted)]" aria-hidden="true" />
            {row.label}
          </span>
          <span className="mt-0.5 block truncate pl-[23px] text-xs text-[var(--cd-text-muted)]">
            {record.activity_title}
            {record.achieved_at !== null && ` · ${formatDate(record.achieved_at)}`}
          </span>
        </span>
        <span className="shrink-0 tabular-nums text-base font-semibold text-[var(--cd-orange-text)]">
          {row.format(record.value)}
        </span>
      </Link>
    </li>
  )
}
