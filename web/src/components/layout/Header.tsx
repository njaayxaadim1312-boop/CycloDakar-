import clsx from 'clsx'
import { ChevronDown, LogOut, Menu, Moon, Sun, UserRound } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTheme } from '@/hooks/useTheme'
import { useAuth, useCurrentUser } from '@/stores/auth'

interface HeaderProps {
  title: string
  subtitle?: string
  onOpenMenu: () => void
}

/**
 * Barre supérieure : titre de la page courante, bascule de thème et menu du
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

        <AccountMenu />
      </div>
    </header>
  )
}

/* -------------------------------------------------------------------------- */

function AccountMenu() {
  const user = useCurrentUser()
  const logout = useAuth((state) => state.logout)
  const navigate = useNavigate()

  const [open, setOpen] = useState(false)
  const [signingOut, setSigningOut] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  // Fermeture au clic extérieur et à Échap : comportements attendus d'un menu.
  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  if (!user) return null

  async function handleLogout(allDevices: boolean) {
    setSigningOut(true)
    await logout(allDevices)
    navigate('/login', { replace: true })
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="cd-btn cd-btn-ghost !min-h-9 !gap-1.5 !px-2.5"
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <span className="flex size-6 items-center justify-center rounded-full bg-[var(--cd-orange)] text-[var(--cd-black)]">
          <UserRound size={14} />
        </span>
        <span className="hidden max-w-[9rem] truncate text-sm font-semibold sm:inline">
          {user.name}
        </span>
        <ChevronDown
          size={14}
          className={clsx('transition-transform', open && 'rotate-180')}
        />
      </button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 z-30 mt-2 w-64 overflow-hidden rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-surface)] shadow-[var(--cd-shadow-lg)]"
        >
          <div className="border-b border-[var(--cd-border)] px-4 py-3">
            <p className="truncate font-semibold">{user.name}</p>
            <p className="truncate text-xs text-[var(--cd-text-muted)]">
              {user.email ?? user.phone_formatted ?? '—'}
            </p>
            <span className="cd-badge mt-2 bg-[var(--cd-orange-soft)] text-[var(--cd-orange-text)]">
              {user.role_label}
            </span>
          </div>

          <div className="p-1.5">
            <button
              type="button"
              role="menuitem"
              disabled={signingOut}
              onClick={() => void handleLogout(false)}
              className="flex w-full items-center gap-2.5 rounded-[var(--cd-radius-sm)] px-3 py-2 text-left text-sm font-medium hover:bg-[var(--cd-surface-2)] disabled:opacity-50"
            >
              <LogOut size={16} className="text-[var(--cd-text-muted)]" />
              Se déconnecter
            </button>

            {/*
              Utile quand on pense avoir laissé une session ouverte ailleurs,
              ou quand on a perdu son téléphone.
            */}
            <button
              type="button"
              role="menuitem"
              disabled={signingOut}
              onClick={() => void handleLogout(true)}
              className="flex w-full items-center gap-2.5 rounded-[var(--cd-radius-sm)] px-3 py-2 text-left text-sm font-medium text-[var(--cd-danger)] hover:bg-[var(--cd-danger-soft)] disabled:opacity-50"
            >
              <LogOut size={16} />
              Déconnecter tous les appareils
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
