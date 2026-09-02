import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, Receipt } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { formatDate, formatFcfa } from '@/lib/format'
import { fetchMyDues } from '@/lib/payments'
import type { ParticipationLine, Payment } from '@/types/api'

/**
 * « Mes cotisations » — ce que je dois, ce que j'ai payé.
 *
 * POURQUOI CET ÉCRAN EXISTE
 *
 * Dans un club qui collecte en espèces, la première cause de friction n'est
 * pas l'argent : c'est de ne pas savoir où l'on en est. Un membre qui doute
 * appelle le trésorier, qui cherche dans un carnet. Ici, chacun vérifie son
 * compte seul, à toute heure, et retrouve le numéro de reçu qu'on lui a donné.
 *
 * C'est la SEULE page financière ouverte à un membre ordinaire, et elle ne
 * montre que lui : ni solde de caisse, ni versements des autres. Un reçu est
 * une pièce personnelle, la liste des versements du club n'est pas un
 * annuaire.
 *
 * **Les reçus annulés restent affichés**, barrés et motivés. Les cacher serait
 * le meilleur moyen qu'un membre se croie à jour alors qu'il ne l'est pas.
 */
export function MyDuesPage() {
  const query = useQuery({ queryKey: ['my-dues'], queryFn: fetchMyDues })

  const dues = query.data?.dues.dues ?? []
  const payments = query.data?.dues.payments ?? []
  const totals = query.data?.totals

  const aJour = totals !== undefined && totals.remaining_amount === 0

  return (
    <div className="space-y-5">
      <PageHeader
        title="Mes cotisations"
        description="Ce que le club attend de vous, et ce que vous avez déjà versé."
      />

      {query.isPending && <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>}

      {totals !== undefined && (
        <div className="grid gap-4 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5 sm:grid-cols-3">
          <Total label="Attendu" value={totals.expected_amount} />
          <Total label="Versé" value={totals.paid_amount} tone="success" />
          <Total label="Reste à payer" value={totals.remaining_amount} tone="accent" />
        </div>
      )}

      {aJour && dues.length > 0 && (
        <p className="flex items-center gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4 text-sm">
          <CheckCircle2 size={18} className="shrink-0 text-[var(--cd-success)]" />
          Vous êtes à jour de toutes vos cotisations. Merci.
        </p>
      )}

      {!query.isPending && dues.length === 0 && (
        <p className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center text-sm text-[var(--cd-text-muted)]">
          Aucune cotisation ne vous est demandée pour le moment.
        </p>
      )}

      {dues.length > 0 && (
        <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
          <h2 className="text-sm font-semibold">Mes participations</h2>

          <ul className="mt-4 divide-y divide-[var(--cd-border)]">
            {dues.map((due) => (
              <DueRow key={due.id} due={due} />
            ))}
          </ul>
        </section>
      )}

      {payments.length > 0 && (
        <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <Receipt size={16} aria-hidden="true" />
            Mes reçus
          </h2>

          <p className="mt-1 text-xs text-[var(--cd-text-muted)]">
            Le numéro de reçu fait foi en cas de contestation. Conservez-le.
          </p>

          <ul className="mt-4 divide-y divide-[var(--cd-border)]">
            {payments.map((payment) => (
              <ReceiptRow key={payment.uuid} payment={payment} />
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function Total({
  label,
  value,
  tone,
}: {
  label: string
  value: number
  tone?: 'success' | 'accent'
}) {
  const color =
    tone === 'success'
      ? 'text-[var(--cd-success)]'
      : tone === 'accent'
        ? 'text-[var(--cd-orange)]'
        : ''

  return (
    <div>
      <p className="text-xs text-[var(--cd-text-muted)]">{label}</p>
      <p className={`mt-1 text-2xl font-bold tabular-nums ${color}`}>{formatFcfa(value)}</p>
    </div>
  )
}

function DueRow({ due }: { due: ParticipationLine }) {
  const solde = due.remaining_amount === 0

  return (
    <li className="flex items-center gap-3 py-3">
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">
          {due.participation?.name ?? 'Collecte'}
        </p>
        <p className="text-xs text-[var(--cd-text-muted)]">
          {due.status_label}
          {due.participation?.due_on != null &&
            ` · échéance le ${formatDate(due.participation.due_on)}`}
          {/* Le collecteur est nommé : le membre sait à qui remettre son
              argent, ce qui évite qu'il paie à la mauvaise personne. */}
          {due.collector != null && ` · ${due.collector.name}`}
        </p>
      </div>

      <div className="shrink-0 text-right">
        <p
          className={[
            'text-sm font-semibold tabular-nums',
            solde ? 'text-[var(--cd-success)]' : 'text-[var(--cd-orange)]',
          ].join(' ')}
        >
          {solde ? 'À jour' : `${formatFcfa(due.remaining_amount)} à payer`}
        </p>
        <p className="text-xs tabular-nums text-[var(--cd-text-muted)]">
          {formatFcfa(due.paid_amount)} / {formatFcfa(due.expected_amount)}
        </p>
      </div>
    </li>
  )
}

function ReceiptRow({ payment }: { payment: Payment }) {
  return (
    <li className="py-3">
      <div className="flex items-center gap-3">
        <div className="min-w-0 flex-1">
          <p
            className={[
              'truncate font-mono text-sm',
              payment.cancelled ? 'text-[var(--cd-text-muted)] line-through' : '',
            ].join(' ')}
          >
            {payment.receipt_number}
          </p>
          <p className="truncate text-xs text-[var(--cd-text-muted)]">
            {payment.participation?.name ?? '—'} · {payment.method_label} ·{' '}
            {formatDate(payment.paid_on)}
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
      </div>

      {payment.cancelled && (
        <p className="mt-1 text-xs text-[var(--cd-danger)]">
          Reçu annulé{payment.cancellation_reason !== null && ` : ${payment.cancellation_reason}`}
        </p>
      )}
    </li>
  )
}
