import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, FileText, Paperclip, Receipt, X } from 'lucide-react'
import { useRef, useState } from 'react'
import { ExpenseDialog } from '@/components/finance/ExpenseDialog'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import {
  approveExpense,
  attachToExpense,
  fetchExpenses,
  rejectExpense,
} from '@/lib/finance'
import { formatDate, formatFcfa } from '@/lib/format'
import type { Expense, ExpenseStatusCode } from '@/types/api'

/**
 * Les dépenses du club.
 *
 * **Ce qui attend une décision arrive en premier**, et c'est le seul tri qui
 * compte : un trésorier ouvre cet écran pour décider, pas pour consulter. Les
 * dépenses déjà tranchées restent à un filtre près.
 *
 * Le bouton « Approuver » n'apparaît que si le SERVEUR l'autorise
 * (`permissions.approve`). On n'approuve pas sa propre dépense : deviner cette
 * règle côté client donnerait un bouton qui répond 403, et le trésorier
 * croirait à une panne au lieu de comprendre qu'il lui faut un second regard.
 */
const FILTRES: Array<{ code: ExpenseStatusCode | ''; label: string }> = [
  { code: 'PENDING', label: 'À décider' },
  { code: 'APPROVED', label: 'Approuvées' },
  { code: 'REJECTED', label: 'Refusées' },
  { code: '', label: 'Toutes' },
]

export function ExpensesPage() {
  const [status, setStatus] = useState<ExpenseStatusCode | ''>('PENDING')

  const query = useQuery({
    queryKey: ['expenses', status],
    queryFn: () => fetchExpenses(status === '' ? { scope: 'all' } : { status }),
  })

  const expenses = query.data?.data ?? []

  return (
    <div className="space-y-5">
      <PageHeader
        title="Dépenses"
        description="Une dépense en attente n'a encore rien sorti de la caisse."
        actions={<ExpenseDialog />}
      />

      <div className="flex flex-wrap gap-1.5">
        {FILTRES.map((filtre) => (
          <button
            key={filtre.code}
            type="button"
            onClick={() => setStatus(filtre.code)}
            aria-pressed={status === filtre.code}
            className={`rounded-[var(--cd-radius-pill)] border px-3 py-1.5 text-xs font-medium transition-colors ${
              status === filtre.code
                ? 'border-[var(--cd-orange)] bg-[var(--cd-orange)] text-[var(--cd-black)]'
                : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]'
            }`}
          >
            {filtre.label}
          </button>
        ))}
      </div>

      {query.isPending && <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>}

      {!query.isPending && expenses.length === 0 && (
        <div className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center">
          <Receipt size={28} aria-hidden="true" className="mx-auto text-[var(--cd-text-muted)]" />
          <p className="mt-3 text-sm font-medium">
            {status === 'PENDING' ? 'Rien à décider.' : 'Aucune dépense ici.'}
          </p>
        </div>
      )}

      {expenses.length > 0 && (
        <ul className="space-y-3">
          {expenses.map((expense) => (
            <ExpenseCard key={expense.uuid} expense={expense} />
          ))}
        </ul>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

const STATUS_STYLE: Record<ExpenseStatusCode, string> = {
  PENDING: 'bg-[var(--cd-orange-soft)] text-[var(--cd-orange-text)]',
  APPROVED: 'bg-[var(--cd-green-soft)] text-[var(--cd-green-hover)]',
  REJECTED: 'bg-[var(--cd-surface-2)] text-[var(--cd-text-muted)]',
}

function ExpenseCard({ expense }: { expense: Expense }) {
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)

  const [asking, setAsking] = useState(false)
  const [reason, setReason] = useState('')

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['expenses'] })
    void queryClient.invalidateQueries({ queryKey: ['finance-dashboard'] })
    void queryClient.invalidateQueries({ queryKey: ['cash'] })
    void queryClient.invalidateQueries({ queryKey: ['ledger'] })
  }

  const approve = useMutation({
    mutationFn: () => approveExpense(expense.uuid),
    onSuccess: refresh,
  })

  const reject = useMutation({
    mutationFn: () => rejectExpense(expense.uuid, reason),
    onSuccess: () => {
      setAsking(false)
      setReason('')
      refresh()
    },
  })

  const attach = useMutation({
    mutationFn: (file: File) => attachToExpense(expense.uuid, file),
    onSuccess: refresh,
  })

  const error =
    approve.error instanceof ApiError
      ? approve.error
      : reject.error instanceof ApiError
        ? reject.error
        : attach.error instanceof ApiError
          ? attach.error
          : null

  return (
    <li className="cd-rise rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-semibold">{expense.label}</p>
          <p className="mt-0.5 text-xs text-[var(--cd-text-muted)]">
            {expense.category?.name}
            {' · '}
            {formatDate(expense.spent_on)}
            {expense.supplier !== null && ` · ${expense.supplier}`}
            {expense.requested_by !== undefined && ` · demandé par ${expense.requested_by.name}`}
          </p>
        </div>

        <div className="text-right">
          <p className="text-xl font-bold tabular-nums">{formatFcfa(expense.amount)}</p>
          <span
            className={`mt-1 inline-block rounded-[var(--cd-radius-pill)] px-2 py-0.5 text-xs font-medium ${STATUS_STYLE[expense.status]}`}
          >
            {expense.status_label}
          </span>
        </div>
      </div>

      {expense.is_commitment && (
        // Dit explicitement, parce que le chiffre se lit sinon comme une
        // dépense faite : c'est la règle I4 rendue lisible.
        <p className="mt-3 text-xs text-[var(--cd-text-muted)]">
          Engagée, mais rien n’est encore sorti de la caisse.
        </p>
      )}

      {expense.description !== null && expense.description !== '' && (
        <p className="mt-3 text-sm leading-relaxed whitespace-pre-line">
          {expense.description}
        </p>
      )}

      {expense.status === 'REJECTED' && expense.decision_reason !== null && (
        <p className="mt-3 text-sm text-[var(--cd-danger)]">
          Refusée : {expense.decision_reason}
        </p>
      )}

      {expense.status === 'APPROVED' && expense.approved_by != null && (
        <p className="mt-3 text-xs text-[var(--cd-text-muted)]">
          Approuvée par {expense.approved_by.name}.
        </p>
      )}

      {/* --- Justificatifs ------------------------------------------------- */}
      {expense.attachments !== undefined && expense.attachments.length > 0 && (
        <ul className="mt-4 flex flex-wrap gap-2">
          {expense.attachments.map((piece) => (
            <li key={piece.uuid}>
              <a
                href={piece.url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1.5 rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-3 py-1.5 text-xs hover:border-[var(--cd-orange)]"
              >
                <FileText size={13} aria-hidden="true" />
                {piece.name}
              </a>
            </li>
          ))}
        </ul>
      )}

      {error !== null && (
        <p role="alert" className="mt-3 text-sm text-[var(--cd-danger)]">
          {error.fieldError('reason') ?? error.fieldError('file') ?? error.message}
        </p>
      )}

      {/* --- Actes ---------------------------------------------------------- */}
      <div className="mt-4 flex flex-wrap gap-2">
        <input
          ref={fileInput}
          type="file"
          accept="image/*,application/pdf"
          hidden
          onChange={(event) => {
            const file = event.target.files?.[0]
            if (file !== undefined) attach.mutate(file)
            event.target.value = ''
          }}
        />
        <button
          type="button"
          onClick={() => fileInput.current?.click()}
          disabled={attach.isPending}
          className="inline-flex items-center gap-1.5 rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-3 py-1.5 text-xs font-medium hover:border-[var(--cd-orange)] disabled:opacity-60"
        >
          <Paperclip size={13} aria-hidden="true" />
          {attach.isPending ? 'Envoi…' : 'Joindre un justificatif'}
        </button>

        {/* Le droit vient du SERVEUR : on n'approuve pas sa propre dépense. */}
        {expense.permissions?.approve === true && (
          <button
            type="button"
            onClick={() => approve.mutate()}
            disabled={approve.isPending}
            className="inline-flex items-center gap-1.5 rounded-[var(--cd-radius-pill)] bg-[var(--cd-green)] px-3 py-1.5 text-xs font-semibold text-[var(--cd-black)] disabled:opacity-60"
          >
            <Check size={13} aria-hidden="true" />
            {approve.isPending ? 'Approbation…' : 'Approuver'}
          </button>
        )}

        {expense.permissions?.reject === true && (
          <button
            type="button"
            onClick={() => setAsking((open) => !open)}
            className="inline-flex items-center gap-1.5 rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-3 py-1.5 text-xs font-medium hover:border-[var(--cd-danger)] hover:text-[var(--cd-danger)]"
          >
            <X size={13} aria-hidden="true" />
            Refuser
          </button>
        )}

        {expense.status === 'PENDING' && expense.permissions?.approve === false && (
          // On explique l'absence de bouton, sinon elle passe pour une panne.
          <p className="self-center text-xs text-[var(--cd-text-muted)]">
            Un autre responsable doit décider : on n’approuve pas sa propre dépense.
          </p>
        )}
      </div>

      {asking && (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            reject.mutate()
          }}
          className="mt-3 space-y-2 rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-bg)] p-3"
        >
          <label className="block space-y-1">
            <span className="block text-xs font-semibold">Motif du refus</span>
            <input
              type="text"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              required
              minLength={10}
              placeholder="Le transport est déjà couvert par le sponsor"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border)] bg-[var(--cd-surface)] px-3 py-2 text-sm"
            />
            {/* Celui qui a demandé mérite de savoir pourquoi on lui a dit non. */}
            <span className="block text-xs text-[var(--cd-text-muted)]">
              Ce motif sera lu par le demandeur.
            </span>
          </label>

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
              disabled={reject.isPending}
              className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-danger)] px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
            >
              {reject.isPending ? 'Refus…' : 'Confirmer le refus'}
            </button>
          </div>
        </form>
      )}
    </li>
  )
}
