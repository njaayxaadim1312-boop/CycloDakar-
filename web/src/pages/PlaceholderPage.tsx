import { ArrowLeft, Construction } from 'lucide-react'
import { Link, useLocation } from 'react-router-dom'
import { findNavItem } from '@/config/navigation'

/**
 * Écran d'un module pas encore livré.
 *
 * Il annonce clairement la phase concernée et ce que le module fera, plutôt
 * que d'afficher une coquille vide ou de fausses données. Le contenu vient de
 * `config/navigation.ts` : ajouter une route au menu suffit, cette page se
 * remplit toute seule.
 */
export function PlaceholderPage() {
  const location = useLocation()
  const item = findNavItem(location.pathname)

  return (
    <div className="cd-card mx-auto max-w-xl p-8 text-center">
      <span className="mx-auto flex size-14 items-center justify-center rounded-full bg-[var(--cd-orange-soft)]">
        <Construction size={26} className="text-[var(--cd-orange-text)]" />
      </span>

      <h2 className="mt-4 text-xl">{item?.label ?? 'Module'}</h2>

      <p className="mt-1 text-sm font-semibold text-brand-text">
        Livré en phase {item?.phase ?? '?'}
      </p>

      {item?.summary && (
        <p className="mt-3 text-sm leading-relaxed text-[var(--cd-text-muted)]">
          {item.summary}
        </p>
      )}

      <Link to="/dashboard" className="cd-btn cd-btn-primary mt-6">
        <ArrowLeft size={16} />
        Retour au tableau de bord
      </Link>
    </div>
  )
}
