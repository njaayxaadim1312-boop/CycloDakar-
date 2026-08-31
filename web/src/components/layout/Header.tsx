import { Menu, Moon, Sun, UserRound } from 'lucide-react'
import { useTheme } from '@/hooks/useTheme'

interface HeaderProps {
  title: string
  subtitle?: string
  onOpenMenu: () => void
}

/**
 * Barre supérieure : titre de la page courante, bascule de thème et accès au
 * compte. Le bouton hamburger n'apparaît que sous `lg`, où la barre latérale
 * devient un tiroir.
 */
export function Header({ title, subtitle, onOpenMenu }: HeaderProps) {
  const { isDark, toggle } = useTheme()

  return (
    <header className="sticky top-0 z-20 border-b border-[var(--cd-border)] bg-[var(--cd-surface)]/90 backdrop-blur">
      <div className="flex h-[var(--cd-header-h)] items-center gap-3 px-4 sm:px-6">
        <button
          type="button"
          onClick={onOpenMenu}
          className="cd-btn cd-btn-ghost !min-h-9 !w-9 !px-0 lg:hidden"
          aria-label="Ouvrir le menu"
        >
          <Menu size={18} />
        </button>

        <div className="min-w-0 flex-1">
          <h1 className="truncate text-base font-bold sm:text-lg">{title}</h1>
          {subtitle && (
            <p className="truncate text-xs text-[var(--cd-text-muted)]">{subtitle}</p>
          )}
        </div>

        <button
          type="button"
          onClick={toggle}
          className="cd-btn cd-btn-ghost !min-h-9 !w-9 !px-0"
          aria-label={isDark ? 'Passer en thème clair' : 'Passer en thème sombre'}
          title={isDark ? 'Thème clair' : 'Thème sombre'}
        >
          {isDark ? <Sun size={17} /> : <Moon size={17} />}
        </button>

        {/*
          Le compte réel arrive avec l'authentification (phase 2). En attendant,
          le bouton est désactivé plutôt qu'absent : la place lui est réservée
          et l'utilisateur n'a pas l'impression d'une fonction manquante.
        */}
        <button
          type="button"
          disabled
          className="cd-btn cd-btn-ghost !min-h-9 !px-3"
          title="Connexion — livrée en phase 2"
        >
          <UserRound size={17} />
          <span className="hidden text-sm sm:inline">Invité</span>
        </button>
      </div>
    </header>
  )
}
