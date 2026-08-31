import { ChevronLeft, ChevronRight } from 'lucide-react'

interface PaginationProps {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
  onChange: (page: number) => void
}

/**
 * Pagination.
 *
 * Volontairement sobre : deux flèches, la position, et le total. Une liste de
 * numéros de page n'apporte rien sur un annuaire qu'on parcourt surtout par
 * la recherche — et elle serait à l'étroit sur un téléphone.
 */
export function Pagination({
  currentPage,
  lastPage,
  total,
  perPage,
  onChange,
}: PaginationProps) {
  if (total === 0) return null

  const from = (currentPage - 1) * perPage + 1
  const to = Math.min(currentPage * perPage, total)

  return (
    <nav
      className="flex flex-wrap items-center justify-between gap-3 pt-1"
      aria-label="Pagination"
    >
      <p className="tabular text-sm text-[var(--cd-text-muted)]">
        {from}–{to} sur {total} membre{total > 1 ? 's' : ''}
      </p>

      {lastPage > 1 && (
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => onChange(currentPage - 1)}
            disabled={currentPage <= 1}
            className="cd-btn cd-btn-ghost !min-h-9 !w-9 !px-0"
            aria-label="Page précédente"
          >
            <ChevronLeft size={17} />
          </button>

          <span className="tabular text-sm font-semibold">
            {currentPage} / {lastPage}
          </span>

          <button
            type="button"
            onClick={() => onChange(currentPage + 1)}
            disabled={currentPage >= lastPage}
            className="cd-btn cd-btn-ghost !min-h-9 !w-9 !px-0"
            aria-label="Page suivante"
          >
            <ChevronRight size={17} />
          </button>
        </div>
      )}
    </nav>
  )
}
