import { useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { findNavItem, isDelivered } from '@/config/navigation'
import { Header } from './Header'
import { RecordingBanner } from './RecordingBanner'
import { Sidebar } from './Sidebar'

/**
 * Coquille applicative : menu latéral fixe à gauche, en-tête collant, contenu.
 *
 * Le titre affiché dans l'en-tête est dérivé de la route via `findNavItem` :
 * chaque page n'a donc pas à le redéclarer, et le menu reste l'unique source
 * de vérité de la navigation.
 *
 * **Transition de page.** Le `key` posé sur `<main>` force React à remonter
 * le contenu à chaque changement de chemin, ce qui relance l'animation
 * d'entrée. Sans lui, React réutiliserait le nœud et la page suivante
 * apparaîtrait sans transition — le déplacement serait invisible, et l'on ne
 * saurait pas si le clic a été pris en compte.
 *
 * La durée est courte et la translation faible : une transition qu'on
 * remarque est une transition trop longue. Elle est neutralisée sous
 * `prefers-reduced-motion` par la règle globale de `tokens.css`.
 */
export function AppLayout() {
  const [menuOpen, setMenuOpen] = useState(false)
  const location = useLocation()

  // Sur mobile, changer de page doit refermer le tiroir — sinon il masque
  // la page qu'on vient d'ouvrir.
  useEffect(() => {
    setMenuOpen(false)
  }, [location.pathname])

  // Échap ferme le tiroir : réflexe attendu, et sortie de secours si le voile
  // de fond n'est pas atteignable.
  useEffect(() => {
    if (!menuOpen) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMenuOpen(false)
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [menuOpen])

  const current = findNavItem(location.pathname)

  return (
    <div className="min-h-dvh bg-[var(--cd-bg)]">
      <Sidebar open={menuOpen} onClose={() => setMenuOpen(false)} />

      <div className="lg:pl-[var(--cd-sidebar-w)]">
        <Header
          title={current?.label ?? 'Cyclo Dakar'}
          subtitle={
            current !== undefined && !isDelivered(current)
              ? `À venir en phase ${current.phase}`
              : undefined
          }
          onOpenMenu={() => setMenuOpen(true)}
        />

        {/* Sous l'en-tete, au-dessus du contenu : un enregistrement qui
            continue en coulisse doit se voir depuis n'importe quel ecran. */}
        <RecordingBanner />

        <main key={location.pathname} className="cd-rise mx-auto max-w-6xl px-4 py-6 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
