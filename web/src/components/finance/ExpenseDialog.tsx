import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PlusCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import { ApiError } from '@/lib/api'
import { createExpense, fetchLedgerCategories } from '@/lib/finance'
import { formatFcfa } from '@/lib/format'

/**
 * Saisie d'une dépense.
 *
 * Le dialogue annonce le seuil de validation À L'AVANCE, avant l'envoi. Le
 * trésorier sait donc, en tapant le montant, si sa dépense partira seule ou
 * attendra un second regard. Découvrir la règle après coup — sur une réponse
 * qu'on n'attendait pas — donne l'impression d'un refus, alors que c'est le
 * fonctionnement normal.
 *
 * Seuls les postes de sens `OUT` sont proposés, et le sens vient du serveur :
 * le deviner ici laisserait un jour « Dons » dans une liste de dépenses.
 */
const SEUIL = 25_000

export function ExpenseDialog() {
  const dialog = useRef<HTMLDialogElement>(null)
  const queryClient = useQueryClient()

  const [category, setCategory] = useState('')
  const [amount, setAmount] = useState('')
  const [label, setLabel] = useState('')
  const [supplier, setSupplier] = useState('')
  const [description, setDescription] = useState('')

  const categories = useQuery({
    queryKey: ['ledger-categories'],
    queryFn: fetchLedgerCategories,
  })

  const postes = (categories.data ?? []).filter((poste) => poste.direction === 'OUT')

  const save = useMutation({
    mutationFn: () =>
      createExpense({
        category,
        amount: Number(amount),
        label: label.trim(),
        supplier: supplier.trim() === '' ? null : supplier.trim(),
        description: description.trim() === '' ? null : description.trim(),
      }),
    onSuccess: () => {
      setAmount('')
      setLabel('')
      setSupplier('')
      setDescription('')
      void queryClient.invalidateQueries({ queryKey: ['expenses'] })
      void queryClient.invalidateQueries({ queryKey: ['finance-dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['cash'] })
      void queryClient.invalidateQueries({ queryKey: ['ledger'] })
      dialog.current?.close()
    },
  })

  const error = save.error instanceof ApiError ? save.error : null
  const montant = Number(amount)
  const passeSeule = montant > 0 && montant < SEUIL

  function open() {
    setCategory(postes[0]?.code ?? '')
    save.reset()
    dialog.current?.showModal()
  }

  return (
    <>
      <button
        type="button"
        onClick={open}
        className="inline-flex items-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)]"
      >
        <PlusCircle size={16} aria-hidden="true" />
        Saisir une dépense
      </button>

      <dialog
        ref={dialog}
        className="cd-pop m-auto w-[min(92vw,28rem)] rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-0 text-[var(--cd-text)] backdrop:bg-black/50 backdrop:backdrop-blur-sm"
        onClose={() => save.reset()}
      >
        <form
          method="dialog"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate()
          }}
          className="max-h-[85dvh] space-y-4 overflow-y-auto p-5"
        >
          <div>
            <h2 className="text-lg font-bold">Dépense</h2>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              Rien ne sort de la caisse tant qu’elle n’est pas approuvée.
            </p>
          </div>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">Poste</span>
            <select
              value={category}
              onChange={(event) => setCategory(event.target.value)}
              required
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            >
              {postes.map((poste) => (
                <option key={poste.code} value={poste.code}>
                  {poste.name}
                </option>
              ))}
            </select>
          </label>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">Montant (FCFA)</span>
            <input
              type="number"
              inputMode="numeric"
              step={1}
              min={1}
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              required
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[17px] font-semibold tabular-nums"
            />
            {/* La règle est annoncée AVANT l'envoi : la découvrir après coup
                donnerait l'impression d'un refus. */}
            <span className="block text-xs text-[var(--cd-text-muted)]">
              {passeSeule
                ? `Sous ${formatFcfa(SEUIL)}, cette dépense sera approuvée immédiatement.`
                : `À partir de ${formatFcfa(SEUIL)}, un second responsable doit approuver.`}
            </span>
            {error?.fieldError('amount') !== undefined && (
              <span role="alert" className="block text-xs text-[var(--cd-danger)]">
                {error.fieldError('amount')}
              </span>
            )}
          </label>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">À quoi correspond-elle ?</span>
            <input
              type="text"
              value={label}
              onChange={(event) => setLabel(event.target.value)}
              required
              maxLength={200}
              placeholder="Transport Lac Rose"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            />
            {error?.fieldError('label') !== undefined && (
              <span role="alert" className="block text-xs text-[var(--cd-danger)]">
                {error.fieldError('label')}
              </span>
            )}
          </label>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">Fournisseur</span>
            <input
              type="text"
              value={supplier}
              onChange={(event) => setSupplier(event.target.value)}
              maxLength={160}
              placeholder="Facultatif"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            />
          </label>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">Détail</span>
            <textarea
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              rows={3}
              maxLength={2000}
              placeholder="Facultatif — ce que l’assemblée aura besoin de comprendre"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            />
          </label>

          <p className="text-xs text-[var(--cd-text-muted)]">
            Le justificatif se joint après l’enregistrement, depuis la liste.
          </p>

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
              disabled={save.isPending}
              className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-4 py-2 text-sm font-semibold text-[var(--cd-black)] disabled:opacity-60"
            >
              {save.isPending ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>
        </form>
      </dialog>
    </>
  )
}
