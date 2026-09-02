import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { formatFcfa } from '@/lib/format'
import { fetchCashState, fetchCollections } from '@/lib/payments'
import type { CollectorTally } from '@/types/api'

/**
 * « Collectes par collecteur » — le contrôle, pas une statistique.
 *
 * Dans un club qui collecte en espèces au bord de la route, le risque numéro
 * un n'est pas la panne informatique : c'est qu'un collecteur encaisse et
 * garde. Il ne se traite pas par la confiance mais en rendant visible, chaque
 * semaine, qui a encaissé combien — et combien d'opérations ont été annulées.
 *
 * **Les annulations ont leur propre colonne, et elles ne se fondent pas dans
 * le total.** Un collecteur dont les annulations sortent du lot n'est pas
 * forcément malhonnête : il peut être mal formé, ou avoir un téléphone qui
 * renvoie deux fois. Mais c'est exactement le genre d'écart qu'il faut voir
 * tôt, et le mélanger au montant encaissé le rendrait invisible.
 *
 * Trente jours par défaut : c'est l'horizon d'un point hebdomadaire de bureau.
 * « Depuis toujours » noierait l'anomalie récente dans la masse.
 */
export function CollectionsPage() {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const query = useQuery({
    queryKey: ['collections', from, to],
    queryFn: () =>
      fetchCollections({
        ...(from !== '' && { from }),
        ...(to !== '' && { to }),
      }),
  })

  const cash = useQuery({ queryKey: ['cash'], queryFn: fetchCashState })

  const collectors = query.data?.collectors ?? []
  const totals = query.data?.totals

  return (
    <div className="space-y-5">
      <PageHeader
        title="Collectes par collecteur"
        description="Qui a encaissé combien, et combien d’opérations ont été annulées."
      />

      {/* L'état de la caisse, annoncé pour ce qu'il est. */}
      {cash.data !== undefined && (
        <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-xs text-[var(--cd-text-muted)]">
                {cash.data.name} — mouvements enregistrés
              </p>
              <p className="mt-1 text-3xl font-bold tabular-nums">
                {formatFcfa(cash.data.balance)}
              </p>
            </div>

            {/* Un écart entre le cache et le grand livre signifie qu'une
                écriture est passée hors du seul chemin autorisé. Le trésorier
                doit le voir sans attendre la vérification nocturne. */}
            {cash.data.balance !== cash.data.derived_balance && (
              <p className="flex items-start gap-2 rounded-[var(--cd-radius)] border border-[var(--cd-danger)] p-3 text-xs text-[var(--cd-danger)]">
                <AlertTriangle size={16} className="mt-px shrink-0" />
                Écart avec le grand livre ({formatFcfa(cash.data.derived_balance)}).
                Lancez <code>php artisan finance:recompute-balance</code>.
              </p>
            )}
          </div>

          {!cash.data.complete && cash.data.incomplete_reason !== null && (
            // On ne présente JAMAIS ce montant comme le solde réel du club.
            // Confondre « tout ce qui est enregistré » et « tout ce qui
            // existe » ruinerait la confiance du bureau.
            <p className="mt-3 flex items-start gap-2 text-xs text-[var(--cd-text-muted)]">
              <ShieldCheck size={14} className="mt-px shrink-0" />
              {cash.data.incomplete_reason}
            </p>
          )}
        </section>
      )}

      <div className="flex flex-wrap items-end gap-3 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4">
        <label className="space-y-1">
          <span className="block text-xs font-semibold">Du</span>
          <input
            type="date"
            value={from}
            onChange={(event) => setFrom(event.target.value)}
            className="rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-sm"
          />
        </label>
        <label className="space-y-1">
          <span className="block text-xs font-semibold">Au</span>
          <input
            type="date"
            value={to}
            onChange={(event) => setTo(event.target.value)}
            className="rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-sm"
          />
        </label>
        {totals !== undefined && (
          <p className="ml-auto text-sm text-[var(--cd-text-muted)]">
            Du {new Date(totals.from).toLocaleDateString('fr-FR')} au{' '}
            {new Date(totals.to).toLocaleDateString('fr-FR')} :{' '}
            <strong className="text-[var(--cd-text)]">
              {formatFcfa(totals.total_amount)}
            </strong>{' '}
            en {totals.total_count} opération{totals.total_count > 1 ? 's' : ''}
            {totals.cancelled_count > 0 && (
              <span className="text-[var(--cd-danger)]">
                {' '}
                · {totals.cancelled_count} annulée
                {totals.cancelled_count > 1 ? 's' : ''} (
                {formatFcfa(totals.cancelled_amount)})
              </span>
            )}
          </p>
        )}
      </div>

      {query.isPending && <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>}

      {!query.isPending && collectors.length === 0 && (
        <p className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center text-sm text-[var(--cd-text-muted)]">
          Aucun encaissement sur cette période.
        </p>
      )}

      {collectors.length > 0 && (
        <section className="overflow-x-auto rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)]">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-[var(--cd-border)] text-left text-xs text-[var(--cd-text-muted)]">
                <th className="p-4 font-medium">Collecteur</th>
                <th className="p-4 text-right font-medium">Encaissé</th>
                <th className="p-4 text-right font-medium">Opérations</th>
                <th className="p-4 text-right font-medium">Annulations</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--cd-border)]">
              {collectors.map((row) => (
                <CollectorRow key={row.collector.uuid} row={row} />
              ))}
            </tbody>
          </table>
        </section>
      )}
    </div>
  )
}

function CollectorRow({ row }: { row: CollectorTally }) {
  return (
    <tr>
      <td className="p-4 font-medium">{row.collector.name}</td>
      <td className="p-4 text-right font-semibold tabular-nums">
        {formatFcfa(row.collected_amount)}
      </td>
      <td className="p-4 text-right tabular-nums text-[var(--cd-text-muted)]">
        {row.collected_count}
      </td>
      <td className="p-4 text-right tabular-nums">
        {row.cancelled_count === 0 ? (
          <span className="text-[var(--cd-text-muted)]">—</span>
        ) : (
          <span className="text-[var(--cd-danger)]">
            {row.cancelled_count} · {formatFcfa(row.cancelled_amount)}
          </span>
        )}
      </td>
    </tr>
  )
}
