import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Receipt, Undo2 } from 'lucide-react'
import { useState } from 'react'
import { ApiError } from '@/lib/api'
import { formatFcfa } from '@/lib/format'
import { cancelPayment, fetchPayments } from '@/lib/payments'
import type { Payment } from '@/types/api'

interface PaymentsSectionProps {
  participationUuid: string
  /** Trésorier et administration seulement : voir `PaymentPolicy`. */
  canCancel: boolean
}

/**
 * Le journal des encaissements d'une collecte.
 *
 * DEUX PARTIS PRIS QUI VIENNENT DU CONTRAT COMPTABLE.
 *
 * **Rien ne se supprime.** Le bouton dit « Annuler », et c'est ce qu'il fait :
 * une écriture de sens inverse est ajoutée au grand livre, le reçu reste
 * consultable et porte désormais son motif d'annulation. Un membre qui se
 * présente avec son papier le retrouve toujours — c'est exactement ce dont on
 * a besoin quand quelqu'un conteste.
 *
 * **Les annulations sont montrées, pas cachées.** Les masquer par défaut
 * allègerait la liste, mais les rendre introuvables empêcherait de comprendre
 * un écart de caisse. Elles restent donc visibles, barrées, avec leur raison.
 */
export function PaymentsSection({ participationUuid, canCancel }: PaymentsSectionProps) {
  const query = useQuery({
    queryKey: ['payments', participationUuid],
    queryFn: () => fetchPayments(participationUuid, { include_cancelled: true, per_page: 50 }),
  })

  const payments = query.data?.data ?? []

  return (
    <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="flex items-center gap-2 text-sm font-semibold">
        <Receipt size={16} aria-hidden="true" />
        Encaissements
        <span className="font-normal text-[var(--cd-text-muted)] tabular-nums">
          {payments.length}
        </span>
      </h2>

      {query.isPending && (
        <p className="mt-4 text-sm text-[var(--cd-text-muted)]">Chargement…</p>
      )}

      {!query.isPending && payments.length === 0 && (
        <p className="mt-4 text-sm text-[var(--cd-text-muted)]">
          Aucun versement pour l’instant. Le bouton « Encaisser » se trouve en face
          de chaque membre.
        </p>
      )}

      {payments.length > 0 && (
        <ul className="mt-4 divide-y divide-[var(--cd-border)]">
          {payments.map((payment) => (
            <PaymentRow
              key={payment.uuid}
              payment={payment}
              participationUuid={participationUuid}
              canCancel={canCancel}
            />
          ))}
        </ul>
      )}
    </section>
  )
}

function PaymentRow({
  payment,
  participationUuid,
  canCancel,
}: {
  payment: Payment
  participationUuid: string
  canCancel: boolean
}) {
  const queryClient = useQueryClient()
  const [asking, setAsking] = useState(false)
  const [reason, setReason] = useState('')

  const cancel = useMutation({
    mutationFn: () => cancelPayment(payment.uuid, reason),
    onSuccess: () => {
      setAsking(false)
      setReason('')
      void queryClient.invalidateQueries({ queryKey: ['payments', participationUuid] })
      void queryClient.invalidateQueries({ queryKey: ['participation', participationUuid] })
      void queryClient.invalidateQueries({ queryKey: ['participations'] })
    },
  })

  const error = cancel.error instanceof ApiError ? cancel.error : null

  return (
    <li className="py-3">
      <div className="flex items-center gap-3">
        <div className="min-w-0 flex-1">
          <p
            className={[
              'truncate text-sm font-medium',
              payment.cancelled ? 'text-[var(--cd-text-muted)] line-through' : '',
            ].join(' ')}
          >
            {payment.member?.full_name ?? '—'}
          </p>
          <p className="text-xs text-[var(--cd-text-muted)]">
            <span className="font-mono">{payment.receipt_number}</span> ·{' '}
            {payment.method_label}
            {payment.reference !== null && ` · ${payment.reference}`} ·{' '}
            {new Date(payment.paid_on).toLocaleDateString('fr-FR')}
            {payment.collector !== undefined && ` · ${payment.collector.name}`}
          </p>
        </div>

        <p
          className={[
            'shrink-0 text-sm font-semibold tabular-nums',
            payment.cancelled
              ? 'text-[var(--cd-text-muted)] line-through'
              : 'text-[var(--cd-success)]',
          ].join(' ')}
        >
          {formatFcfa(payment.amount)}
        </p>

        {canCancel && !payment.cancelled && (
          <button
            type="button"
            onClick={() => setAsking((open) => !open)}
            aria-label={`Annuler le reçu ${payment.receipt_number}`}
            title="Annuler — une contre-passation sera écrite, rien ne sera supprimé"
            className="flex size-9 shrink-0 items-center justify-center rounded-full border border-[var(--cd-border)] text-[var(--cd-text-muted)] transition-colors hover:border-[var(--cd-danger)] hover:text-[var(--cd-danger)]"
          >
            <Undo2 size={16} aria-hidden="true" />
          </button>
        )}
      </div>

      {payment.cancelled && payment.cancellation_reason !== null && (
        <p className="mt-1 text-xs text-[var(--cd-danger)]">
          Annulé : {payment.cancellation_reason}
        </p>
      )}

      {asking && (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            cancel.mutate()
          }}
          className="mt-3 space-y-2 rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-bg)] p-3"
        >
          <label className="block space-y-1">
            <span className="block text-xs font-semibold">Motif de l’annulation</span>
            <input
              type="text"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              required
              minLength={10}
              placeholder="Saisi deux fois lors de la sortie du 14 septembre"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-sm"
            />
            {/* Dix caractères n'obligent pas à une bonne explication — rien ne
                le peut — mais ils écartent le clic réflexe sur « ok ». */}
            <span className="block text-xs text-[var(--cd-text-muted)]">
              Ce motif restera attaché au reçu et sera lu en assemblée générale.
            </span>
          </label>

          {error !== null && (
            <p role="alert" className="text-xs text-[var(--cd-danger)]">
              {error.fieldError('reason') ?? error.message}
            </p>
          )}

          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => setAsking(false)}
              className="rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-3 py-1.5 text-xs font-medium"
            >
              Retour
            </button>
            <button
              type="submit"
              disabled={cancel.isPending}
              className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-danger)] px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
            >
              {cancel.isPending ? 'Annulation…' : 'Confirmer l’annulation'}
            </button>
          </div>
        </form>
      )}
    </li>
  )
}
