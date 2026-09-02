import { useQuery } from '@tanstack/react-query'
import { ScrollText, ShieldAlert } from 'lucide-react'
import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { api, getData } from '@/lib/api'

/**
 * Le journal d'audit.
 *
 * IL EXISTE DEPUIS LA PHASE 3 ET N'AVAIT JAMAIS PU ÊTRE LU.
 *
 * Chaque attribution de rôle, chaque encaissement, chaque annulation y écrit
 * une ligne depuis des mois — mais il fallait ouvrir la base pour les voir. Un
 * journal qu'on ne peut pas lire ne protège personne : il donne le sentiment
 * d'être protégé, ce qui est pire, car on renonce alors à d'autres contrôles.
 *
 * **Les valeurs avant et après sont montrées telles quelles.** C'est ce qui
 * distingue un journal d'une liste d'événements : « le rôle a changé » ne dit
 * rien ; « MEMBER → SUPER_ADMIN » dit tout. L'adresse IP aussi — elle sépare
 * « le trésorier a annulé ce paiement » de « quelqu'un utilisant son compte
 * l'a annulé ».
 */
interface LigneAudit {
  id: number
  action: string
  entity: { type: string; id: number }
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  reason: string | null
  ip_address: string | null
  author: { uuid: string; name: string } | null
  created_at: string
}

/**
 * Les familles d'action, pour donner une couleur au coup d'œil.
 *
 * Le rouge est réservé à ce qui DÉFAIT : annulation, refus, suppression. Ce
 * sont les lignes qu'on cherche quand on ouvre ce journal.
 */
function tonDeLAction(action: string): string {
  if (/reversed|cancelled|rejected|deleted/.test(action)) {
    return 'text-[var(--cd-danger)]'
  }

  if (/role_changed/.test(action)) {
    return 'text-[var(--cd-orange-text)]'
  }

  return 'text-[var(--cd-text)]'
}

export function AuditLogPage() {
  const [action, setAction] = useState('')

  const actions = useQuery({
    queryKey: ['audit-actions'],
    queryFn: () => getData<Array<{ action: string; count: number }>>('/audit-logs/actions'),
  })

  const journal = useQuery({
    queryKey: ['audit-logs', action],
    queryFn: async () => {
      const reponse = await api.get<{
        data: LigneAudit[]
        meta: { total: number; has_more: boolean }
      }>('/audit-logs', { params: action === '' ? {} : { action } })

      return reponse.data
    },
  })

  const lignes = journal.data?.data ?? []

  return (
    <div className="space-y-5">
      <PageHeader
        title="Journal d'audit"
        description="Qui a fait quoi, quand, et ce que valait la donnée avant."
      />

      <p className="flex items-start gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4 text-xs text-[var(--cd-text-muted)]">
        <ShieldAlert size={15} className="mt-px shrink-0" />
        <span>
          Ce journal est en <strong>lecture seule</strong> : aucune ligne ne peut être
          modifiée ni effacée, y compris par un administrateur. Un journal qu'on peut
          retoucher ne prouve rien.
        </span>
      </p>

      {(actions.data ?? []).length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          <button
            type="button"
            onClick={() => setAction('')}
            aria-pressed={action === ''}
            className={`rounded-[var(--cd-radius-pill)] border px-3 py-1.5 text-xs font-medium ${
              action === ''
                ? 'border-[var(--cd-orange)] bg-[var(--cd-orange)] text-[var(--cd-black)]'
                : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]'
            }`}
          >
            Tout
          </button>

          {(actions.data ?? []).map((entree) => (
            <button
              key={entree.action}
              type="button"
              onClick={() => setAction(entree.action)}
              aria-pressed={action === entree.action}
              className={`rounded-[var(--cd-radius-pill)] border px-3 py-1.5 text-xs font-medium ${
                action === entree.action
                  ? 'border-[var(--cd-orange)] bg-[var(--cd-orange)] text-[var(--cd-black)]'
                  : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]'
              }`}
            >
              {entree.action}
              <span className="ml-1.5 opacity-70 tabular-nums">{entree.count}</span>
            </button>
          ))}
        </div>
      )}

      {journal.isPending && <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>}

      {!journal.isPending && lignes.length === 0 && (
        <div className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center">
          <ScrollText size={28} aria-hidden="true" className="mx-auto text-[var(--cd-text-muted)]" />
          <p className="mt-3 text-sm font-medium">Aucune trace pour ce filtre.</p>
        </div>
      )}

      {lignes.length > 0 && (
        <ul className="space-y-2">
          {lignes.map((ligne) => (
            <li
              key={ligne.id}
              className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4"
            >
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className={`font-mono text-sm font-semibold ${tonDeLAction(ligne.action)}`}>
                  {ligne.action}
                </p>
                <p className="text-xs text-[var(--cd-text-muted)]">
                  {new Date(ligne.created_at).toLocaleString('fr-FR')}
                </p>
              </div>

              <p className="mt-1 text-xs text-[var(--cd-text-muted)]">
                {ligne.entity.type} #{ligne.entity.id}
                {' · '}
                {ligne.author?.name ?? 'console'}
                {ligne.ip_address !== null && ` · ${ligne.ip_address}`}
              </p>

              {/* Le motif d'abord : c'est ce qu'on cherche à lire. */}
              {ligne.reason !== null && (
                <p className="mt-2 text-sm">{ligne.reason}</p>
              )}

              {(ligne.old_values !== null || ligne.new_values !== null) && (
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                  {ligne.old_values !== null && (
                    <Valeurs titre="Avant" valeurs={ligne.old_values} />
                  )}
                  {ligne.new_values !== null && (
                    <Valeurs titre="Après" valeurs={ligne.new_values} />
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {journal.data?.meta.has_more === true && (
        // On le DIT plutôt que de laisser croire à une liste complète : un
        // journal tronqué en silence ferait conclure à tort qu'il ne s'est
        // rien passé d'autre.
        <p className="text-xs text-[var(--cd-text-muted)]">
          {journal.data.meta.total} traces au total — seules les plus récentes sont
          affichées.
        </p>
      )}
    </div>
  )
}

function Valeurs({
  titre,
  valeurs,
}: {
  titre: string
  valeurs: Record<string, unknown>
}) {
  return (
    <div className="rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-bg)] p-3">
      <p className="text-xs font-semibold text-[var(--cd-text-muted)]">{titre}</p>
      <dl className="mt-1 space-y-0.5 text-xs">
        {Object.entries(valeurs).map(([cle, valeur]) => (
          <div key={cle} className="flex gap-2">
            <dt className="text-[var(--cd-text-muted)]">{cle}</dt>
            <dd className="font-mono break-all">{String(valeur)}</dd>
          </div>
        ))}
      </dl>
    </div>
  )
}
