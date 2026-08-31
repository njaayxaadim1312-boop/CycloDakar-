import { useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { findNavItem, isDelivered } from '@/config/navigation'
import { Header } from './Header'
import { Sidebar } from './Sidebar'

/**
 * Coquille applicative : menu latéral fixe à gauche, en-tête collant, contenu.
 *
 * Le titre affiché dans l'en-tête est dérivé de la route via `findNavItem` :
 * chaque page n'a donc pas à le redéclarer, et le menu reste l'unique source
 * de vérité de la navigation.
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

        <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
