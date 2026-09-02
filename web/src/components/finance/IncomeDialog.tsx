import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PlusCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import { ApiError } from '@/lib/api'
import { createIncome, fetchLedgerCategories } from '@/lib/finance'

/**
 * Saisie d'une recette manuelle : don, sponsoring, vente de maillots.
 *
 * Elle entre **directement** au grand livre, sans circuit de validation —
 * contrairement à une dépense. L'asymétrie est voulue : de l'argent qui entre
 * ne peut pas appauvrir le club, et exiger un double regard pour enregistrer un
 * don ferait perdre la trace du don.
 *
 * Seuls les postes de sens `IN` sont proposés. Le sens vient du serveur : le
 * deviner ici laisserait un jour « Transport » dans une liste de recettes, et
 * le rapport annuel serait faux sans que rien ne s'en aperçoive.
 */
export function IncomeDialog() {
  const dialog = useRef<HTMLDialogElement>(null)
  const queryClient = useQueryClient()

  const [category, setCategory] = useState('')
  const [amount, setAmount] = useState('')
  const [label, setLabel] = useState('')

  const categories = useQuery({
    queryKey: ['ledger-categories'],
    queryFn: fetchLedgerCategories,
  })

  const postes = (categories.data ?? []).filter((poste) => poste.direction === 'IN')

  const save = useMutation({
    mutationFn: () =>
      createIncome({
        category,
        amount: Number(amount),
        label: label.trim(),
      }),
    onSuccess: () => {
      setAmount('')
      setLabel('')
      void queryClient.invalidateQueries({ queryKey: ['finance-dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['cash'] })
      void queryClient.invalidateQueries({ queryKey: ['ledger'] })
      dialog.current?.close()
    },
  })

  const error = save.error instanceof ApiError ? save.error : null

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
        Saisir une recette
      </button>

      <dialog
        ref={dialog}
        className="cd-pop m-auto w-[min(92vw,26rem)] rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-0 text-[var(--cd-text)] backdrop:bg-black/50 backdrop:backdrop-blur-sm"
        onClose={() => save.reset()}
      >
        <form
          method="dialog"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate()
          }}
          className="space-y-4 p-5"
        >
          <div>
            <h2 className="text-lg font-bold">Recette</h2>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              Don, sponsoring, vente d’équipement. L’écriture part immédiatement au
              grand livre.
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
              // `step=1` : le franc CFA n'a pas de centime, et un décimal
              // serait refusé par le serveur plutôt qu'arrondi en silence.
              step={1}
              min={1}
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              required
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[17px] font-semibold tabular-nums"
            />
            {error?.fieldError('amount') !== undefined && (
              <span role="alert" className="block text-xs text-[var(--cd-danger)]">
                {error.fieldError('amount')}
              </span>
            )}
          </label>

          <label className="block space-y-1">
            <span className="block text-[13px] font-semibold">D’où vient cet argent ?</span>
            <input
              type="text"
              value={label}
              onChange={(event) => setLabel(event.target.value)}
              required
              maxLength={200}
              placeholder="Don de la mairie de Dakar"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-[15px]"
            />
            {/* Sans libellé, dans six mois personne ne saura d'où venaient
                150 000 FCFA — et un don anonyme n'est pas auditable. */}
            <span className="block text-xs text-[var(--cd-text-muted)]">
              Cette mention est ce qui rendra l’écriture explicable en assemblée.
            </span>
            {error?.fieldError('label') !== undefined && (
              <span role="alert" className="block text-xs text-[var(--cd-danger)]">
                {error.fieldError('label')}
              </span>
            )}
          </label>

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
