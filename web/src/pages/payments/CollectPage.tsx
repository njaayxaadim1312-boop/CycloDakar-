import { useQuery } from '@tanstack/react-query'
import { CreditCard, Search } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { PaymentDialog } from '@/components/payments/PaymentDialog'
import { Avatar } from '@/components/ui/Avatar'
import { PageHeader } from '@/components/ui/PageHeader'
import { formatFcfa } from '@/lib/format'
import { fetchMyAssignments } from '@/lib/participations'
import type { ParticipationLine } from '@/types/api'

/**
 * Tableau constant pour l'état « pas encore chargé ».
 *
 * Un littéral `[]` créerait une nouvelle référence à chaque rendu, ce qui
 * relancerait le filtrage mémoïsé sans que rien n'ait changé.
 */
const AUCUNE_LIGNE: ParticipationLine[] = []

/**
 * L'écran de terrain du collecteur.
 *
 * IL RÉPOND À UNE SEULE QUESTION : « QUI DOIS-JE ALLER VOIR ? »
 *
 * C'est la vraie question du jour de collecte, et elle traverse les campagnes.
 * Sans cet écran, un collecteur devrait ouvrir chaque collecte l'une après
 * l'autre pour y chercher son nom — sur un téléphone, au bord d'une route.
 *
 * Trois choix qui découlent de cet usage :
 *
 * - **Seules les dettes NON SOLDÉES apparaissent** (le serveur les filtre
 *   déjà). Une liste qui garde les lignes réglées grossit sans rien apporter,
 *   et fait défiler pour rien.
 * - **La recherche est locale, sans requête.** Le collecteur a sa liste en
 *   main, souvent quelques dizaines de noms ; interroger le serveur à chaque
 *   frappe échouerait précisément là où le réseau manque.
 * - **Le téléphone est affiché et cliquable.** Appeler avant de se déplacer
 *   évite un trajet inutile, et c'est le premier geste d'un collecteur.
 */
export function CollectPage() {
  const [term, setTerm] = useState('')

  const query = useQuery({
    queryKey: ['my-assignments'],
    queryFn: fetchMyAssignments,
  })

  const lines = query.data?.lines ?? AUCUNE_LIGNE

  const filtered = useMemo(() => {
    const needle = term.trim().toLowerCase()

    if (needle === '') return lines

    return lines.filter((line) => {
      const member = line.member

      if (member === undefined) return false

      return (
        member.full_name.toLowerCase().includes(needle) ||
        member.matricule.toLowerCase().includes(needle) ||
        (member.phone_formatted ?? '').toLowerCase().includes(needle)
      )
    })
  }, [lines, term])

  return (
    <div className="space-y-5">
      <PageHeader
        title="Encaissements"
        description="Les membres qui vous sont confiés, toutes collectes confondues."
      />

      {query.data !== undefined && lines.length > 0 && (
        <div className="grid gap-4 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5 sm:grid-cols-2">
          <div>
            <p className="text-xs text-[var(--cd-text-muted)]">Membres à voir</p>
            <p className="mt-1 text-2xl font-bold tabular-nums">{query.data.count}</p>
          </div>
          <div>
            <p className="text-xs text-[var(--cd-text-muted)]">Reste à collecter</p>
            <p className="mt-1 text-2xl font-bold tabular-nums text-[var(--cd-orange)]">
              {formatFcfa(query.data.remaining_amount)}
            </p>
          </div>
        </div>
      )}

      {lines.length > 0 && (
        <label className="relative block">
          <Search
            size={16}
            aria-hidden="true"
            className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-[var(--cd-text-muted)]"
          />
          <span className="sr-only">Rechercher un membre</span>
          <input
            type="search"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
            placeholder="Nom, matricule ou téléphone"
            className="w-full rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] bg-[var(--cd-surface)] py-2.5 pr-4 pl-9 text-[15px]"
          />
        </label>
      )}

      {query.isPending && (
        <p className="text-sm text-[var(--cd-text-muted)]">Chargement…</p>
      )}

      {!query.isPending && lines.length === 0 && (
        <div className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-8 text-center">
          <CreditCard
            size={28}
            aria-hidden="true"
            className="mx-auto text-[var(--cd-text-muted)]"
          />
          <p className="mt-3 text-sm font-medium">Rien à collecter pour l’instant.</p>
          {/* On explique la cause probable plutôt que de laisser un vide :
              « rien à faire » et « on ne vous a rien confié » ne se corrigent
              pas de la même façon. */}
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            Soit tout le monde est à jour, soit aucune collecte ouverte ne vous a
            été confiée. Le trésorier assigne les collecteurs depuis la fiche de
            chaque{' '}
            <Link to="/participations" className="underline">
              collecte
            </Link>
            .
          </p>
        </div>
      )}

      {filtered.length > 0 && (
        <ul className="space-y-2">
          {filtered.map((line) => (
            <AssignmentRow key={line.id} line={line} />
          ))}
        </ul>
      )}

      {lines.length > 0 && filtered.length === 0 && (
        <p className="text-sm text-[var(--cd-text-muted)]">
          Aucun membre ne correspond à « {term} ».
        </p>
      )}
    </div>
  )
}

function AssignmentRow({ line }: { line: ParticipationLine }) {
  const member = line.member
  const participation = line.participation

  if (member === undefined || participation === undefined) return null

  return (
    <li className="cd-rise flex items-center gap-3 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4">
      <Avatar initials={member.initials} photoUrl={member.photo_url} size={40} />

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold">{member.full_name}</p>
        <p className="truncate text-xs text-[var(--cd-text-muted)]">
          {participation.name} · {member.matricule}
        </p>
        {member.phone_formatted !== null && (
          // Appeler avant de se déplacer évite un trajet pour rien : c'est le
          // premier geste d'un collecteur, il mérite un lien direct.
          <a
            href={`tel:${member.phone_formatted.replace(/\s/g, '')}`}
            className="text-xs text-[var(--cd-orange)] underline"
          >
            {member.phone_formatted}
          </a>
        )}
      </div>

      <div className="shrink-0 text-right">
        <p className="text-sm font-bold tabular-nums">
          {formatFcfa(line.remaining_amount)}
        </p>
        <p className="text-xs text-[var(--cd-text-muted)]">
          sur {formatFcfa(line.expected_amount)}
        </p>
      </div>

      {line.can_pay && (
        <PaymentDialog participationUuid={participation.uuid} line={line} />
      )}
    </li>
  )
}
