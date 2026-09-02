import { useMutation, useQuery } from '@tanstack/react-query'
import { Download, FileSpreadsheet, FileText, Table2 } from 'lucide-react'
import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { ApiError } from '@/lib/api'
import {
  downloadReport,
  fetchReport,
  type ReportFormat,
  type ReportPeriod,
  type ReportRange,
} from '@/lib/finance'
import { formatDate, formatFcfa } from '@/lib/format'
import type { FinancialReport } from '@/types/api'

/**
 * Les rapports financiers.
 *
 * L'ÉCRAN MONTRE EXACTEMENT CE QUE L'EXPORT CONTIENDRA.
 *
 * C'est la seule règle qui compte ici. Un rapport qu'on télécharge sans avoir
 * pu le regarder d'abord, on l'ouvre, on découvre qu'il ne couvre pas la bonne
 * période, et on recommence — la veille d'une assemblée générale. Le même
 * appel sert donc l'affichage et les trois formats de fichier.
 *
 * Trois formats, trois usages : le PDF se signe et se distribue, l'Excel se
 * retravaille, le CSV s'importe ailleurs. Ce n'est pas de la redondance.
 */
const PERIODES: Array<{ code: ReportPeriod; label: string }> = [
  { code: 'day', label: "Aujourd'hui" },
  { code: 'week', label: 'Cette semaine' },
  { code: 'month', label: 'Ce mois-ci' },
  { code: 'year', label: 'Cette année' },
  { code: 'custom', label: 'Période libre' },
]

const FORMATS: Array<{ code: ReportFormat; label: string; icon: typeof FileText; aide: string }> = [
  { code: 'pdf', label: 'PDF', icon: FileText, aide: 'À signer et à distribuer en assemblée' },
  { code: 'xlsx', label: 'Excel', icon: FileSpreadsheet, aide: 'À retravailler dans un tableur' },
  { code: 'csv', label: 'CSV', icon: Table2, aide: "À importer dans un autre logiciel" },
]

export function ReportsPage() {
  const [period, setPeriod] = useState<ReportPeriod>('month')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const range: ReportRange = {
    period,
    ...(period === 'custom' && from !== '' && { from }),
    ...(period === 'custom' && to !== '' && { to }),
  }

  const query = useQuery({
    queryKey: ['finance-report', period, from, to],
    queryFn: () => fetchReport(range),
  })

  const telecharger = useMutation({
    mutationFn: (format: ReportFormat) => downloadReport(range, format),
  })

  const rapport = query.data
  const erreur =
    query.error instanceof ApiError
      ? query.error
      : telecharger.error instanceof ApiError
        ? telecharger.error
        : null

  return (
    <div className="space-y-5">
      <PageHeader
        title="Rapports financiers"
        description="Ce qui s'affiche ici est exactement ce que contiendra le fichier."
      />

      {/* --- Période --------------------------------------------------------- */}
      <section className="space-y-3 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4">
        <div className="flex flex-wrap gap-1.5">
          {PERIODES.map((choix) => (
            <button
              key={choix.code}
              type="button"
              onClick={() => setPeriod(choix.code)}
              aria-pressed={period === choix.code}
              className={`rounded-[var(--cd-radius-pill)] border px-3 py-1.5 text-xs font-medium transition-colors ${
                period === choix.code
                  ? 'border-[var(--cd-orange)] bg-[var(--cd-orange)] text-[var(--cd-black)]'
                  : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]'
              }`}
            >
              {choix.label}
            </button>
          ))}
        </div>

        {period === 'custom' && (
          <div className="flex flex-wrap items-end gap-3">
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
          </div>
        )}

        {rapport !== undefined && (
          <p className="text-sm text-[var(--cd-text-muted)]">
            Rapport : <strong className="text-[var(--cd-text)]">{rapport.period.label}</strong>
          </p>
        )}
      </section>

      {erreur !== null && (
        <p role="alert" className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          {erreur.message}
        </p>
      )}

      {/* --- Exports --------------------------------------------------------- */}
      <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <Download size={16} aria-hidden="true" />
          Télécharger
        </h2>

        <div className="mt-3 grid gap-2 sm:grid-cols-3">
          {FORMATS.map((format) => (
            <button
              key={format.code}
              type="button"
              onClick={() => telecharger.mutate(format.code)}
              disabled={telecharger.isPending || query.isError}
              className="rounded-[var(--cd-radius)] border border-[var(--cd-border)] p-3 text-left transition-colors hover:border-[var(--cd-orange)] disabled:opacity-50"
            >
              <span className="flex items-center gap-2 text-sm font-semibold">
                <format.icon size={16} aria-hidden="true" />
                {format.label}
              </span>
              {/* Dire à quoi sert chaque format évite le clic au hasard, puis
                  le fichier qu'on rouvre pour comprendre ce qu'on a exporté. */}
              <span className="mt-1 block text-xs text-[var(--cd-text-muted)]">
                {format.aide}
              </span>
            </button>
          ))}
        </div>

        {telecharger.isPending && (
          <p className="mt-3 text-xs text-[var(--cd-text-muted)]">Génération en cours…</p>
        )}
      </section>

      {query.isPending && <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>}

      {rapport !== undefined && <ReportBody rapport={rapport} />}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function ReportBody({ rapport }: { rapport: FinancialReport }) {
  return (
    <>
      {/* --- Synthèse : l'addition doit se refaire à l'œil ------------------- */}
      <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
        <h2 className="text-sm font-semibold">Synthèse</h2>

        <dl className="mt-4 space-y-2 text-sm">
          <Ligne label="Solde d'ouverture" value={rapport.summary.opening_balance} />
          <Ligne label="Recettes" value={rapport.summary.income} signe="+" tone="success" />
          <Ligne label="Dépenses" value={rapport.summary.expenses} signe="−" tone="danger" />
          <div className="border-t border-[var(--cd-border)] pt-2">
            <Ligne label="Solde de clôture" value={rapport.summary.closing_balance} fort />
          </div>
        </dl>

        {rapport.summary.committed_today > 0 && (
          // Hors de tous les totaux, et le rapport le dit : une dépense en
          // attente n'a aucune ligne au grand livre (règle I4).
          <p className="mt-4 text-xs text-[var(--cd-text-muted)]">
            À aujourd’hui, {formatFcfa(rapport.summary.committed_today)} de dépenses sont
            engagées mais pas encore approuvées. Elles ne figurent dans aucun chiffre
            ci-dessus, et rien n’est encore sorti de la caisse.
          </p>
        )}
      </section>

      {/* --- Ventilation ------------------------------------------------------ */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Ventilation titre="Recettes par poste" lignes={rapport.by_category.income} tone="success" />
        <Ventilation titre="Dépenses par poste" lignes={rapport.by_category.expenses} tone="danger" />
      </div>

      {/* --- Collectes -------------------------------------------------------- */}
      <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
        <h2 className="text-sm font-semibold">Collectes</h2>
        {/* Hors période, volontairement : une créance n'appartient pas à un
            mois, elle existe tant qu'elle n'est pas réglée. */}
        <p className="mt-1 text-xs text-[var(--cd-text-muted)]">
          Situation à aujourd’hui, toutes collectes confondues — indépendante de la
          période choisie.
        </p>

        <dl className="mt-4 space-y-2 text-sm">
          <Ligne label="Attendu des membres" value={rapport.participations.expected} />
          <Ligne label="Encaissé" value={rapport.participations.collected} tone="success" />
          <div className="border-t border-[var(--cd-border)] pt-2">
            <Ligne label="Reste à percevoir" value={rapport.participations.remaining} fort />
          </div>
        </dl>
      </section>

      {/* --- Opérations ------------------------------------------------------- */}
      <section className="overflow-x-auto rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)]">
        <h2 className="p-5 pb-0 text-sm font-semibold">
          Opérations
          <span className="ml-2 font-normal text-[var(--cd-text-muted)] tabular-nums">
            {rapport.entries.length}
          </span>
        </h2>

        {rapport.entries.length === 0 ? (
          <p className="p-5 text-sm text-[var(--cd-text-muted)]">
            Aucune opération sur cette période.
          </p>
        ) : (
          <table className="mt-4 w-full min-w-[42rem] text-sm">
            <thead>
              <tr className="border-b border-[var(--cd-border)] text-left text-xs text-[var(--cd-text-muted)]">
                <th className="p-4 font-medium">Date</th>
                <th className="p-4 font-medium">Opération</th>
                <th className="p-4 text-right font-medium">Entrée</th>
                <th className="p-4 text-right font-medium">Sortie</th>
                <th className="p-4 text-right font-medium">Solde</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--cd-border)]">
              {rapport.entries.map((ecriture, index) => (
                <tr key={`${ecriture.date}-${index}`}>
                  <td className="p-4 whitespace-nowrap tabular-nums text-[var(--cd-text-muted)]">
                    {formatDate(ecriture.date)}
                  </td>
                  <td className="p-4">
                    <p className="font-medium">{ecriture.label}</p>
                    <p className="text-xs text-[var(--cd-text-muted)]">
                      {ecriture.category} · {ecriture.author}
                    </p>
                    {ecriture.reason !== null && (
                      <p className="text-xs text-[var(--cd-danger)]">{ecriture.reason}</p>
                    )}
                  </td>
                  <td className="p-4 text-right tabular-nums text-[var(--cd-success)]">
                    {ecriture.income > 0 ? formatFcfa(ecriture.income) : ''}
                  </td>
                  <td className="p-4 text-right tabular-nums text-[var(--cd-danger)]">
                    {ecriture.expense > 0 ? formatFcfa(ecriture.expense) : ''}
                  </td>
                  <td className="p-4 text-right font-semibold tabular-nums">
                    {formatFcfa(ecriture.balance_after)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </>
  )
}

function Ligne({
  label,
  value,
  signe,
  tone,
  fort,
}: {
  label: string
  value: number
  signe?: string
  tone?: 'success' | 'danger'
  fort?: boolean
}) {
  const couleur =
    tone === 'success'
      ? 'text-[var(--cd-success)]'
      : tone === 'danger'
        ? 'text-[var(--cd-danger)]'
        : ''

  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className={fort === true ? 'font-semibold' : 'text-[var(--cd-text-muted)]'}>
        {label}
      </dt>
      <dd
        className={[
          'tabular-nums',
          fort === true ? 'text-lg font-bold' : 'font-semibold',
          couleur,
        ].join(' ')}
      >
        {signe !== undefined && `${signe} `}
        {formatFcfa(value)}
      </dd>
    </div>
  )
}

function Ventilation({
  titre,
  lignes,
  tone,
}: {
  titre: string
  lignes: Array<{ code: string; name: string; amount: number; operations: number }>
  tone: 'success' | 'danger'
}) {
  return (
    <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
      <h2 className="text-sm font-semibold">{titre}</h2>

      {lignes.length === 0 ? (
        <p className="mt-3 text-sm text-[var(--cd-text-muted)]">Aucun mouvement.</p>
      ) : (
        <dl className="mt-4 space-y-2 text-sm">
          {lignes.map((ligne) => (
            <div key={ligne.code} className="flex items-baseline justify-between gap-3">
              <dt className="text-[var(--cd-text-muted)]">
                {ligne.name}
                <span className="ml-1.5 text-xs">({ligne.operations})</span>
              </dt>
              <dd
                className={`font-semibold tabular-nums ${
                  tone === 'success' ? 'text-[var(--cd-success)]' : 'text-[var(--cd-danger)]'
                }`}
              >
                {formatFcfa(ligne.amount)}
              </dd>
            </div>
          ))}
        </dl>
      )}
    </section>
  )
}
