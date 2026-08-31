import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { AlertCircle, Search, UserPlus, Users, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Avatar } from '@/components/ui/Avatar'
import { Pagination } from '@/components/ui/Pagination'
import {
  MemberStatusBadge,
  NoAccountBadge,
  RoleBadge,
} from '@/components/ui/StatusBadge'
import { ApiError } from '@/lib/api'
import { fetchMembers } from '@/lib/members'
import { hasAtLeastRole, useCurrentUser } from '@/stores/auth'
import type { MemberFilters, MemberStatusCode, RoleCode } from '@/types/api'

const STATUSES: { value: MemberStatusCode | ''; label: string }[] = [
  { value: '', label: 'Tous les statuts' },
  { value: 'ACTIVE', label: 'Actifs' },
  { value: 'PENDING', label: 'En attente' },
  { value: 'SUSPENDED', label: 'Suspendus' },
  { value: 'FORMER', label: 'Anciens membres' },
]

const ROLES: { value: RoleCode | ''; label: string }[] = [
  { value: '', label: 'Tous les rôles' },
  { value: 'MEMBER', label: 'Membres' },
  { value: 'COLLECTOR', label: 'Collecteurs' },
  { value: 'TREASURER', label: 'Trésoriers' },
  { value: 'ADMIN', label: 'Administrateurs' },
  { value: 'SUPER_ADMIN', label: 'Super administrateurs' },
]

/**
 * Annuaire du club.
 *
 * Les filtres vivent dans l'URL : une recherche peut être partagée, mise en
 * favori, et le bouton « retour » du navigateur fait ce qu'on attend de lui.
 */
export function MembersPage() {
  const [params, setParams] = useSearchParams()
  const user = useCurrentUser()
  const canCreate = hasAtLeastRole(user, 'COLLECTOR')

  const filters: MemberFilters = {
    search: params.get('search') ?? '',
    status: (params.get('status') as MemberStatusCode | null) ?? '',
    role: (params.get('role') as RoleCode | null) ?? '',
    sort: (params.get('sort') as MemberFilters['sort']) ?? 'name',
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  // Champ de saisie local + envoi différé : sans cela, chaque frappe
  // déclencherait une requête, ce qui est intenable sur un réseau mobile.
  const [searchInput, setSearchInput] = useState(filters.search ?? '')

  useEffect(() => {
    const timer = setTimeout(() => {
      if (searchInput === (params.get('search') ?? '')) return

      const next = new URLSearchParams(params)
      searchInput ? next.set('search', searchInput) : next.delete('search')
      next.delete('page') // toute nouvelle recherche repart de la page 1
      setParams(next, { replace: true })
    }, 350)

    return () => clearTimeout(timer)
  }, [searchInput, params, setParams])

  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(params)
    value ? next.set(key, value) : next.delete(key)
    next.delete('page')
    setParams(next)
  }

  function setPage(page: number) {
    const next = new URLSearchParams(params)
    next.set('page', String(page))
    setParams(next)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const query = useQuery({
    queryKey: ['members', filters],
    queryFn: () => fetchMembers(filters),
    // Garde la page précédente affichée pendant le chargement de la suivante :
    // le tableau ne « saute » pas à chaque frappe.
    placeholderData: keepPreviousData,
  })

  const members = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <div className="space-y-5">
      {/* --- En-tête ------------------------------------------------------ */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-2xl">Annuaire du club</h2>
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            Recherchez par nom, matricule ou téléphone — dans n'importe quel format.
          </p>
        </div>

        {canCreate && (
          <Link to="/members/nouveau" className="cd-btn cd-btn-primary">
            <UserPlus size={17} />
            Ajouter un membre
          </Link>
        )}
      </div>

      {/* --- Filtres ------------------------------------------------------ */}
      <div className="cd-card p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div className="relative sm:col-span-2">
            <Search
              size={17}
              className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-[var(--cd-text-muted)]"
            />
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Kha, CD-000042, 77 123 45 67…"
              aria-label="Rechercher un membre"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] py-2.5 pr-9 pl-10 text-[15px] outline-none focus:border-[var(--cd-orange)]"
            />
            {searchInput && (
              <button
                type="button"
                onClick={() => setSearchInput('')}
                className="absolute top-1/2 right-2 -translate-y-1/2 rounded-full p-1 text-[var(--cd-text-muted)] hover:bg-[var(--cd-surface-2)]"
                aria-label="Effacer la recherche"
              >
                <X size={15} />
              </button>
            )}
          </div>

          <Select
            label="Statut"
            value={filters.status ?? ''}
            options={STATUSES}
            onChange={(v) => setFilter('status', v)}
          />
          <Select
            label="Rôle"
            value={filters.role ?? ''}
            options={ROLES}
            onChange={(v) => setFilter('role', v)}
          />
        </div>
      </div>

      {/* --- Résultats ---------------------------------------------------- */}
      {query.isError && (
        <div className="cd-card flex items-start gap-3 p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={18} className="mt-0.5 shrink-0" />
          <span>
            {query.error instanceof ApiError
              ? query.error.message
              : "L'annuaire n'a pas pu être chargé."}
          </span>
        </div>
      )}

      {query.isLoading && (
        <div className="cd-card divide-y divide-[var(--cd-border)]">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="flex animate-pulse items-center gap-3 p-4">
              <span className="size-10 rounded-full bg-[var(--cd-surface-2)]" />
              <span className="h-4 w-40 rounded bg-[var(--cd-surface-2)]" />
            </div>
          ))}
        </div>
      )}

      {query.isSuccess && members.length === 0 && (
        <div className="cd-card p-10 text-center">
          <Users size={32} className="mx-auto text-[var(--cd-text-muted)]" />
          <p className="mt-3 font-semibold">Aucun membre trouvé</p>
          <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
            {filters.search
              ? `Rien ne correspond à « ${filters.search} ».`
              : 'Aucun membre ne correspond à ces filtres.'}
          </p>
        </div>
      )}

      {members.length > 0 && (
        <>
          <div className="cd-card divide-y divide-[var(--cd-border)] overflow-hidden">
            {members.map((member) => (
              <Link
                key={member.uuid}
                to={`/members/${member.uuid}`}
                className="flex items-center gap-3 p-3.5 transition-colors hover:bg-[var(--cd-orange-soft)] sm:gap-4 sm:p-4"
              >
                <Avatar photoUrl={member.photo_url} initials={member.initials} size={44} />

                <div className="min-w-0 flex-1">
                  <p className="truncate font-semibold">{member.full_name}</p>
                  <p className="tabular truncate text-sm text-[var(--cd-text-muted)]">
                    {member.matricule}
                    {member.phone_formatted && ` · ${member.phone_formatted}`}
                  </p>
                </div>

                <div className="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                  {member.account ? (
                    <RoleBadge role={member.account.role} label={member.account.role_label} />
                  ) : (
                    <NoAccountBadge />
                  )}
                  <span className="hidden sm:inline">
                    <MemberStatusBadge status={member.status} label={member.status_label} />
                  </span>
                </div>
              </Link>
            ))}
          </div>

          {meta && (
            <Pagination
              currentPage={meta.current_page}
              lastPage={meta.last_page}
              total={meta.total}
              perPage={meta.per_page}
              onChange={setPage}
            />
          )}
        </>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function Select<T extends string>({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: T
  options: { value: T; label: string }[]
  onChange: (value: T) => void
}) {
  return (
    <select
      value={value}
      aria-label={label}
      onChange={(e) => onChange(e.target.value as T)}
      className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] px-3 py-2.5 text-[15px] outline-none focus:border-[var(--cd-orange)]"
    >
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  )
}
