import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowDownRight, ArrowUpRight, HandCoins, Wallet } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { IncomeDialog } from '@/components/finance/IncomeDialog'
import { PageHeader } from '@/components/ui/PageHeader'
import { formatFcfa } from '@/lib/format'
import { fetchDashboard } from '@/lib/finance'
import { fetchCashState } from '@/lib/payments'

/**
 * La caisse du club.
 *
 * TROIS NOMBRES QUI NE SE MÉLANGENT PAS, ET C'EST TOUT LE POINT.
 *
 * - **Le solde** : ce que le club a réellement, écriture par écriture.
 * - **L'engagé** : des dépenses décidées mais pas encore approuvées. Ce n'est
 *   pas de l'argent sorti — aucune ligne au grand livre (règle I4).
 * - **Le reste à percevoir** : des créances sur les collectes ouvertes. Ce
 *   n'est pas de la trésorerie.
 *
 * Les additionner ferait croire au bureau qu'il peut engager une dépense sur
 * de l'argent qui n'est pas arrivé. C'est l'erreur classique, et c'est celle
 * qui coule un club. Ils portent donc trois libellés distincts, et le troisième
 * est visuellement mis à l'écart.
 */
export function CashPage() {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const dashboard = useQuery({
    queryKey: ['finance-dashboard', from, to],
    queryFn: () =>
      fetchDashboard({
        ...(from !== '' && { from }),
        ...(to !== '' && { to }),
      }),
  })

  const cash = useQuery({ queryKey: ['cash'], queryFn: fetchCashState })

  const data = dashboard.data?.dashboard
  const period = dashboard.data?.period

  const drift =
    cash.data !== undefined && cash.data.balance !== cash.data.derived_balance

  return (
    <div className="space-y-5">
      <PageHeader
        title="Caisse"
        description="Ce que le club a, ce qu'il a engagé, et ce qu'il attend."
        actions={<IncomeDialog />}
      />

      {/* --- Un écart signale une écriture passée hors du chemin autorisé --- */}
      {drift && cash.data !== undefined && (
        <p className="flex items-start gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertTriangle size={18} className="mt-px shrink-0" />
          <span>
            Le solde en cache ({formatFcfa(cash.data.balance)}) diffère du grand
            livre ({formatFcfa(cash.data.derived_balance)}). Une écriture est passée
            hors du chemin autorisé — lancez{' '}
            <code className="font-mono">php artisan finance:recompute-balance</code>{' '}
            avant toute décision.
          </span>
        </p>
      )}

      {/* --- Le solde, et ce qui n'en fait PAS partie ---------------------- */}
      <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
        <div className="flex items-start gap-3">
          <Wallet size={20} className="mt-1 shrink-0 text-[var(--cd-orange)]" />
          <div>
            <p className="text-xs text-[var(--cd-text-muted)]">Solde de la caisse</p>
            <p
              className={[
                'text-4xl font-bold tabular-nums',
                (data?.balance ?? 0) < 0 ? 'text-[var(--cd-danger)]' : '',
              ].join(' ')}
            >
              {data === undefined ? '—' : formatFcfa(data.balance)}
            </p>
          </div>
        </div>

        {data !== undefined && (
          <dl className="mt-5 grid gap-4 border-t border-[var(--cd-border)] pt-4 sm:grid-cols-3">
            <div>
              <dt className="text-xs text-[var(--cd-text-muted)]">
                Engagé (dépenses en attente)
              </dt>
              <dd className="mt-1 text-xl font-semibold tabular-nums">
                {formatFcfa(data.committed)}
              </dd>
              {/* On dit explicitement que ce n'est pas sorti : sans cette
                  phrase, le chiffre se lit comme une dépense faite. */}
              <dd className="mt-1 text-xs text-[var(--cd-text-muted)]">
                Rien n’est encore sorti de la caisse.
              </dd>
            </div>

            <div>
              <dt className="text-xs text-[var(--cd-text-muted)]">Solde après engagements</dt>
              <dd className="mt-1 text-xl font-semibold tabular-nums">
                {formatFcfa(data.balance_after_commitments)}
              </dd>
              <dd className="mt-1 text-xs text-[var(--cd-text-muted)]">
                Si tout était approuvé aujourd’hui.
              </dd>
            </div>

            <div>
              <dt className="text-xs text-[var(--cd-text-muted)]">Reste à percevoir</dt>
              <dd className="mt-1 text-xl font-semibold tabular-nums text-[var(--cd-text-muted)]">
                {formatFcfa(data.receivable)}
              </dd>
              {/* Une créance n'est pas de la trésorerie. Le dire ici évite
                  qu'on l'ajoute mentalement au solde. */}
              <dd className="mt-1 text-xs text-[var(--cd-text-muted)]">
                Cet argent n’est <strong>pas</strong> en caisse.
              </dd>
            </div>
          </dl>
        )}
      </section>

      {/* --- La période ---------------------------------------------------- */}
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
        {period !== undefined && (
          <p className="ml-auto text-sm text-[var(--cd-text-muted)]">
            Mouvements du {new Date(period.from).toLocaleDateString('fr-FR')} au{' '}
            {new Date(period.to).toLocaleDateString('fr-FR')}
          </p>
        )}
      </div>

      {/* --- Les mouvements de la période ----------------------------------- */}
      {data !== undefined && (
        <div className="grid gap-4 sm:grid-cols-3">
          <Movement
            label="Recettes"
            value={data.income}
            icon={<ArrowUpRight size={18} />}
            tone="success"
          />
          <Movement
            label="Dépenses"
            value={data.expenses}
            icon={<ArrowDownRight size={18} />}
            tone="danger"
          />
          <Movement
            label="Résultat"
            value={data.net}
            icon={<HandCoins size={18} />}
            tone={data.net >= 0 ? 'success' : 'danger'}
          />
        </div>
      )}

      {/* --- La ventilation par poste --------------------------------------- */}
      {data !== undefined && data.by_category.length > 0 && (
        <section className="overflow-x-auto rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)]">
          <h2 className="p-5 pb-0 text-sm font-semibold">Par poste</h2>

          <table className="mt-4 w-full text-sm">
            <thead>
              <tr className="border-b border-[var(--cd-border)] text-left text-xs text-[var(--cd-text-muted)]">
                <th className="p-4 font-medium">Poste</th>
                <th className="p-4 text-right font-medium">Entrées</th>
                <th className="p-4 text-right font-medium">Sorties</th>
                <th className="p-4 text-right font-medium">Opérations</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--cd-border)]">
              {data.by_category.map((row) => (
                <tr key={`${row.code}-${row.direction}`}>
                  <td className="p-4 font-medium">{row.name}</td>
                  <td className="p-4 text-right tabular-nums text-[var(--cd-success)]">
                    {row.direction === 'IN' ? formatFcfa(row.amount) : '—'}
                  </td>
                  <td className="p-4 text-right tabular-nums text-[var(--cd-danger)]">
                    {row.direction === 'OUT' ? formatFcfa(row.amount) : '—'}
                  </td>
                  <td className="p-4 text-right tabular-nums text-[var(--cd-text-muted)]">
                    {row.operations}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      <p className="text-sm text-[var(--cd-text-muted)]">
        Le détail écriture par écriture se trouve dans le{' '}
        <Link to="/finance/transactions" className="underline">
          journal de caisse
        </Link>
        , et les dépenses à décider dans{' '}
        <Link to="/finance/expenses" className="underline">
          Dépenses
        </Link>
        .
      </p>
    </div>
  )
}

function Movement({
  label,
  value,
  icon,
  tone,
}: {
  label: string
  value: number
  icon: React.ReactNode
  tone: 'success' | 'danger'
}) {
  return (
    <div className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <p className="flex items-center gap-2 text-xs text-[var(--cd-text-muted)]">
        <span
          className={
            tone === 'success' ? 'text-[var(--cd-success)]' : 'text-[var(--cd-danger)]'
          }
        >
          {icon}
        </span>
        {label}
      </p>
      <p
        className={[
          'mt-1 text-2xl font-bold tabular-nums',
          tone === 'success' ? 'text-[var(--cd-success)]' : 'text-[var(--cd-danger)]',
        ].join(' ')}
      >
        {formatFcfa(value)}
      </p>
    </div>
  )
}
