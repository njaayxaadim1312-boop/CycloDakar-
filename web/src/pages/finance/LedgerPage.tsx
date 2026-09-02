import { useQuery } from '@tanstack/react-query'
import { Info } from 'lucide-react'
import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { fetchLedger, fetchLedgerCategories, type LedgerFilters } from '@/lib/finance'
import { formatDate, formatFcfa } from '@/lib/format'
import type { LedgerEntry } from '@/types/api'

/**
 * Le journal de caisse — le grand livre, ligne par ligne.
 *
 * C'est la pièce qu'on imprime pour une assemblée générale, et deux choix en
 * découlent directement.
 *
 * **La colonne « Solde » est LUE, jamais recalculée ici.** C'est ce qui
 * garantit qu'un journal imprimé se réimprime identique six mois plus tard,
 * même si une écriture antérieure a été contre-passée entre-temps. La
 * recalculer côté client donnerait, un jour, deux versions du même document.
 *
 * **Une contre-passation se voit.** Elle porte son motif et désigne l'écriture
 * qu'elle annule. Les masquer allégerait la liste et rendrait tout écart
 * incompréhensible — c'est exactement l'inverse de ce qu'un journal sert à
 * faire.
 */
export function LedgerPage() {
  const [filters, setFilters] = useState<LedgerFilters>({ direction: '' })

  const query = useQuery({
    queryKey: ['ledger', filters],
    queryFn: () => fetchLedger({ ...filters, per_page: 100 }),
  })

  const categories = useQuery({
    queryKey: ['ledger-categories'],
    queryFn: fetchLedgerCategories,
  })

  const entries = query.data?.data ?? []

  function set(patch: Partial<LedgerFilters>) {
    setFilters((current) => ({ ...current, ...patch }))
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Journal de caisse"
        description="Toutes les écritures, dans l'ordre où elles sont arrivées."
      />

      <div className="flex flex-wrap items-end gap-3 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4">
        <label className="space-y-1">
          <span className="block text-xs font-semibold">Du</span>
          <input
            type="date"
            value={filters.from ?? ''}
            onChange={(event) => set({ from: event.target.value })}
            className="rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-sm"
          />
        </label>
        <label className="space-y-1">
          <span className="block text-xs font-semibold">Au</span>
          <input
            type="date"
            value={filters.to ?? ''}
            onChange={(event) => set({ to: event.target.value })}
            className="rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-sm"
          />
        </label>
        <label className="space-y-1">
          <span className="block text-xs font-semibold">Sens</span>
          <select
            value={filters.direction ?? ''}
            onChange={(event) =>
              set({ direction: event.target.value as LedgerFilters['direction'] })
            }
            className="rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-sm"
          >
            <option value="">Tous</option>
            <option value="IN">Entrées</option>
            <option value="OUT">Sorties</option>
          </select>
        </label>
        <label className="space-y-1">
          <span className="block text-xs font-semibold">Poste</span>
          <select
            value={filters.category ?? ''}
            onChange={(event) => set({ category: event.target.value })}
            className="rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-sm"
          >
            <option value="">Tous</option>
            {(categories.data ?? []).map((poste) => (
              <option key={poste.code} value={poste.code}>
                {poste.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {query.isPending && <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>}

      {!query.isPending && entries.length === 0 && (
        <p className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center text-sm text-[var(--cd-text-muted)]">
          Aucune écriture sur cette période.
        </p>
      )}

      {entries.length > 0 && (
        <>
          <section className="overflow-x-auto rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)]">
            <table className="w-full min-w-[46rem] text-sm">
              <thead>
                <tr className="border-b border-[var(--cd-border)] text-left text-xs text-[var(--cd-text-muted)]">
                  <th className="p-4 font-medium">Date</th>
                  <th className="p-4 font-medium">Opération</th>
                  <th className="p-4 text-right font-medium">Entrée</th>
                  <th className="p-4 text-right font-medium">Sortie</th>
                  <th className="p-4 text-right font-medium">Solde</th>
                  <th className="p-4 font-medium">Auteur</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[var(--cd-border)]">
                {entries.map((entry) => (
                  <LedgerRow key={entry.uuid} entry={entry} />
                ))}
              </tbody>
            </table>
          </section>

          {/* Sans cette note, la colonne « Solde » passe pour incohérente dès
              qu'une saisie a été antidatée — et on cherche un bug qui n'existe
              pas. Voir docs/finance.md §2. */}
          <p className="flex items-start gap-2 text-xs text-[var(--cd-text-muted)]">
            <Info size={14} className="mt-px shrink-0" />
            La colonne « Solde » est le solde de la caisse au moment où l’écriture a
            été passée. Une opération saisie après coup pour une date antérieure
            apparaît donc plus haut dans la liste que son solde ne le suggère.
          </p>
        </>
      )}
    </div>
  )
}

function LedgerRow({ entry }: { entry: LedgerEntry }) {
  const isReversal = entry.reverses != null

  return (
    <tr className={isReversal ? 'bg-[var(--cd-surface-2)]' : ''}>
      <td className="p-4 whitespace-nowrap tabular-nums text-[var(--cd-text-muted)]">
        {formatDate(entry.occurred_on)}
      </td>

      <td className="p-4">
        <p className="font-medium">{entry.label}</p>
        <p className="text-xs text-[var(--cd-text-muted)]">
          {entry.source_label}
          {entry.category != null && ` · ${entry.category.name}`}
        </p>
        {entry.reason !== null && (
          <p className="mt-0.5 text-xs text-[var(--cd-danger)]">{entry.reason}</p>
        )}
      </td>

      <td className="p-4 text-right tabular-nums text-[var(--cd-success)]">
        {entry.direction === 'IN' ? formatFcfa(entry.amount) : ''}
      </td>
      <td className="p-4 text-right tabular-nums text-[var(--cd-danger)]">
        {entry.direction === 'OUT' ? formatFcfa(entry.amount) : ''}
      </td>

      <td className="p-4 text-right font-semibold tabular-nums">
        {formatFcfa(entry.balance_after)}
      </td>

      <td className="p-4 text-xs whitespace-nowrap text-[var(--cd-text-muted)]">
        {entry.author?.name ?? '—'}
      </td>
    </tr>
  )
}
