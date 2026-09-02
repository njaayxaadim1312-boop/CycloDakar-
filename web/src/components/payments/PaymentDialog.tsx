import { useMutation, useQueryClient } from '@tanstack/react-query'
import { BadgeCheck, Banknote } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { ApiError } from '@/lib/api'
import { formatFcfa } from '@/lib/format'
import { collectPayment, newIdempotencyKey } from '@/lib/payments'
import type { Fcfa, ParticipationLine, PaymentMethodCode } from '@/types/api'

interface PaymentDialogProps {
  participationUuid: string
  line: ParticipationLine
  /** Bouton discret dans une liste, ou bouton principal sur une fiche. */
  variant?: 'inline' | 'primary'
}

/**
 * Encaisser un versement.
 *
 * DEUX DÉCISIONS QUI ONT L'AIR DE DÉTAILS ET N'EN SONT PAS.
 *
 * **La clé d'idempotence est fabriquée à l'OUVERTURE du dialogue, pas à
 * l'envoi.** C'est toute la protection contre le double débit : si le réseau
 * lâche entre la requête et la réponse — le cas courant sur la route du Lac
 * Rose — le collecteur réessaie, la même clé part, et le serveur retrouve le
 * paiement au lieu d'en créer un second. Une clé fabriquée à chaque clic
 * reviendrait à ne pas en avoir. Elle n'est renouvelée qu'après un
 * encaissement réussi, pour que le versement suivant soit bien un nouveau.
 *
 * **Le montant est pré-rempli avec le reste dû.** C'est le geste de très loin
 * le plus fréquent : le membre solde sa part. Le collecteur n'a qu'à corriger
 * pour un versement partiel, au lieu de saisir quatre chiffres sur un
 * téléphone tenu d'une main, au bord d'une route.
 */
export function PaymentDialog({
  participationUuid,
  line,
  variant = 'inline',
}: PaymentDialogProps) {
  const dialog = useRef<HTMLDialogElement>(null)
  const queryClient = useQueryClient()

  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<PaymentMethodCode>('CASH')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey)

  const [receipt, setReceipt] = useState<{ number: string; amount: Fcfa; replayed: boolean } | null>(
    null,
  )

  const collect = useMutation({
    mutationFn: () =>
      collectPayment(participationUuid, {
        member: line.member?.uuid ?? '',
        amount: Number(amount),
        method,
        reference: reference.trim() === '' ? null : reference.trim(),
        note: note.trim() === '' ? null : note.trim(),
        idempotency_key: idempotencyKey,
      }),
    onSuccess: (result) => {
      setReceipt({
        number: result.payment.receipt_number,
        amount: result.payment.amount,
        replayed: result.replayed,
      })

      // Le versement suivant sera un vrai nouveau versement.
      setIdempotencyKey(newIdempotencyKey())

      void queryClient.invalidateQueries({ queryKey: ['participation', participationUuid] })
      void queryClient.invalidateQueries({ queryKey: ['payments', participationUuid] })
      void queryClient.invalidateQueries({ queryKey: ['participations'] })
      void queryClient.invalidateQueries({ queryKey: ['my-dues'] })
    },
  })

  const error = collect.error instanceof ApiError ? collect.error : null

  function open() {
    setAmount(String(line.remaining_amount))
    setReference('')
    setNote('')
    setReceipt(null)
    collect.reset()
    dialog.current?.showModal()
  }

  // Le reste dû peut changer sous nos pieds (un autre collecteur encaisse) :
  // tant que le dialogue est fermé, on suit la valeur du serveur.
  useEffect(() => {
    if (dialog.current?.open !== true) {
      setAmount(String(line.remaining_amount))
    }
  }, [line.remaining_amount])

  const solde = line.remaining_amount <= 0

  return (
    <>
      <button
        type="button"
        onClick={open}
        disabled={solde || line.status === 'ANNULE'}
        className={
          variant === 'primary'
            ? 'inline-flex items-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-50'
            : 'inline-flex items-center gap-1.5 rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-3 py-1.5 text-xs font-medium transition-colors hover:border-[var(--cd-orange)] disabled:opacity-40'
        }
      >
        <Banknote size={variant === 'primary' ? 16 : 14} aria-hidden="true" />
        {solde ? 'À jour' : 'Encaisser'}
      </button>

      <dialog
        ref={dialog}
        className="cd-pop m-auto w-[min(92vw,26rem)] rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-0 text-[var(--cd-text)] backdrop:bg-black/50 backdrop:backdrop-blur-sm"
        onClose={() => collect.reset()}
      >
        {receipt !== null ? (
          <div className="space-y-4 p-5">
            <div className="flex items-start gap-3">
              <BadgeCheck size={22} className="mt-0.5 shrink-0 text-[var(--cd-success)]" />
              <div>
                <h2 className="text-lg font-bold">
                  {receipt.replayed ? 'Déjà enregistré' : 'Paiement enregistré'}
                </h2>
                <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
                  {receipt.replayed
                    ? // Le collecteur doit comprendre que sa reprise a retrouvé
                      // le paiement, et non qu'il vient d'en créer un second.
                      'Ce versement avait déjà été reçu. Rien n’a été débité une seconde fois.'
                    : `${formatFcfa(receipt.amount)} reçus de ${line.member?.full_name ?? 'ce membre'}.`}
                </p>
              </div>
            </div>

            <div className="rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-bg)] p-4 text-center">
              <p className="text-xs uppercase tracking-wide text-[var(--cd-text-muted)]">
                Numéro de reçu
              </p>
              <p className="mt-1 font-mono text-lg font-bold">{receipt.number}</p>
            </div>

            <p className="text-xs text-[var(--cd-text-muted)]">
              Communiquez ce numéro au membre : il le retrouvera dans « Mes
              cotisations », et c’est lui qui fait foi en cas de contestation.
            </p>

            <div className="flex justify-end">
              <button
                type="button"
                onClick={() => dialog.current?.close()}
                className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)]"
              >
                Terminé
              </button>
            </div>
          </div>
        ) : (
          <form
            method="dialog"
            onSubmit={(event) => {
              event.preventDefault()
              collect.mutate()
            }}
            className="space-y-4 p-5"
          >
            <div>
              <h2 className="text-lg font-bold">Encaisser</h2>
              <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
                {line.member?.full_name} — reste{' '}
                <strong className="text-[var(--cd-text)]">
                  {formatFcfa(line.remaining_amount)}
                </strong>{' '}
                sur {formatFcfa(line.expected_amount)}.
              </p>
            </div>

            <label className="block space-y-1">
              <span className="block text-[13px] font-semibold">Montant reçu (FCFA)</span>
              <input
                type="number"
                inputMode="numeric"
                // `step=1` : le franc CFA n'a pas de centime, et un décimal
                // serait refusé par le serveur plutôt qu'arrondi en silence.
                step={1}
                min={1}
                max={line.remaining_amount}
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
                required
                autoFocus
                className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[17px] font-semibold tabular-nums"
              />
              {error?.fieldError('amount') !== undefined && (
                <span role="alert" className="block text-xs text-[var(--cd-danger)]">
                  {error.fieldError('amount')}
                </span>
              )}
            </label>

            <fieldset className="space-y-1">
              <legend className="text-[13px] font-semibold">Moyen de paiement</legend>
              <div className="flex flex-wrap gap-1.5 pt-1">
                {METHODS.map((option) => (
                  <button
                    key={option.code}
                    type="button"
                    onClick={() => setMethod(option.code)}
                    aria-pressed={method === option.code}
                    className={`rounded-[var(--cd-radius-pill)] border px-3 py-1.5 text-xs font-medium transition-colors ${
                      method === option.code
                        ? 'border-[var(--cd-orange)] bg-[var(--cd-orange)] text-[var(--cd-black)]'
                        : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]'
                    }`}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            </fieldset>

            {method !== 'CASH' && (
              <label className="block space-y-1">
                <span className="block text-[13px] font-semibold">
                  Référence de la transaction
                </span>
                <input
                  type="text"
                  value={reference}
                  onChange={(event) => setReference(event.target.value)}
                  placeholder="Identifiant Wave, Orange Money, bordereau…"
                  className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
                />
                {/* Attendue, jamais exigée : bloquer l'encaissement ferait
                    perdre la trace du paiement, ce qui est bien pire que de
                    consigner la référence plus tard. */}
                <span className="block text-xs text-[var(--cd-text-muted)]">
                  Facultatif — à compléter plus tard si vous ne l’avez pas sous les yeux.
                </span>
              </label>
            )}

            {error !== null && Object.keys(error.errors).length === 0 && (
              <p role="alert" className="text-sm text-[var(--cd-danger)]">
                {error.message}
              </p>
            )}

            <div className="flex justify-end gap-2">
              <button
                type="button"
                onClick={() => dialog.current?.close()}
                className="rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-4 py-2 text-sm font-medium"
              >
                Annuler
              </button>
              <button
                type="submit"
                disabled={collect.isPending}
                className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-60"
              >
                {collect.isPending ? 'Enregistrement…' : 'Enregistrer'}
              </button>
            </div>
          </form>
        )}
      </dialog>
    </>
  )
}

/**
 * Les moyens de paiement, dans l'ordre de leur fréquence réelle au Sénégal.
 *
 * Les espèces d'abord — c'est l'essentiel de la collecte de terrain — puis
 * Wave et Orange Money, qui pèsent plus lourd que le virement. L'ordre
 * alphabétique aurait mis « Free Money » en tête, ce qui ferait perdre un
 * appui à chaque encaissement.
 */
const METHODS: Array<{ code: PaymentMethodCode; label: string }> = [
  { code: 'CASH', label: 'Espèces' },
  { code: 'WAVE', label: 'Wave' },
  { code: 'ORANGE_MONEY', label: 'Orange Money' },
  { code: 'FREE_MONEY', label: 'Free Money' },
  { code: 'TRANSFER', label: 'Virement' },
  { code: 'OTHER', label: 'Autre' },
]
