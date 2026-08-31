import type { ReactNode } from 'react'

interface PageHeaderProps {
  title: string
  description?: string
  /** Actions alignées à droite : boutons, liens. */
  actions?: ReactNode
}

/**
 * En-tête de page.
 *
 * Extrait après avoir écrit trois fois la même structure (annuaire, fiche,
 * formulaire). Le passage à la ligne des actions sous le titre est voulu :
 * sur un téléphone, un bouton qui déborde vaut mieux qu'un titre tronqué.
 */
export function PageHeader({ title, description, actions }: PageHeaderProps) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h2 className="text-2xl">{title}</h2>
        {description && (
          <p className="mt-1 max-w-2xl text-sm text-[var(--cd-text-muted)]">
            {description}
          </p>
        )}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}
