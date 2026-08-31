import clsx from 'clsx'
import { X } from 'lucide-react'
import { NavLink } from 'react-router-dom'
import { Logo } from '@/components/Logo'
import { navigation } from '@/config/navigation'

interface SidebarProps {
  /** Ouvert en superposition sur mobile ; toujours visible à partir de `lg`. */
  open: boolean
  onClose: () => void
}

/**
 * Menu latéral principal.
 *
 * Identité orange et blanc : en-tête orange plein portant le logo, surface
 * blanche pour la liste, item actif en orange avec texte noir.
 *
 * Le texte de l'item actif est noir et non blanc : #FF8C00 avec du blanc ne
 * donne que 2,5:1 de contraste, illisible ; avec du noir on atteint 7,9:1.
 *
 * Sous `lg`, la barre devient un tiroir superposé — le web est aussi consulté
 * depuis un téléphone par les membres du bureau.
 */
export function Sidebar({ open, onClose }: SidebarProps) {
  return (
    <>
      {/* Voile de fond, mobile uniquement */}
      <div
        className={clsx(
          'fixed inset-0 z-30 bg-black/40 transition-opacity lg:hidden',
          open ? 'opacity-100' : 'pointer-events-none opacity-0',
        )}
        onClick={onClose}
        aria-hidden="true"
      />

      <aside
        className={clsx(
          'fixed inset-y-0 left-0 z-40 flex w-[var(--cd-sidebar-w)] flex-col',
          'border-r border-[var(--cd-border)] bg-[var(--cd-surface)]',
          'transition-transform duration-200 lg:translate-x-0',
          open ? 'translate-x-0' : '-translate-x-full',
        )}
        aria-label="Navigation principale"
      >
        {/* --- En-tête orange ------------------------------------------------ */}
        <div className="flex items-center justify-between gap-2 bg-[var(--cd-orange)] px-4 py-3.5">
          <Logo size={38} withWordmark variant="onOrange" />
          <button
            type="button"
            onClick={onClose}
            className="rounded-full p-1.5 text-[var(--cd-black)] hover:bg-black/10 lg:hidden"
            aria-label="Fermer le menu"
          >
            <X size={18} />
          </button>
        </div>

        {/* --- Liste de navigation ------------------------------------------- */}
        <nav className="flex-1 overflow-y-auto px-3 py-4">
          {navigation.map((section) => (
            <div key={section.title} className="mb-5 last:mb-0">
              <p className="px-3 pb-1.5 text-[0.6875rem] font-bold tracking-[0.08em] text-[var(--cd-text-muted)] uppercase">
                {section.title}
              </p>
              <ul className="space-y-0.5">
                {section.items.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      onClick={onClose}
                      // `end` sur /finance : sans lui, l'onglet Caisse resterait
                      // actif quand on est sur /finance/expenses.
                      end={item.to === '/finance'}
                      className={({ isActive }) =>
                        clsx(
                          'flex items-center gap-2.5 rounded-[var(--cd-radius-sm)] px-3 py-2 text-sm font-medium transition-colors',
                          isActive
                            ? 'bg-[var(--cd-orange)] text-[var(--cd-black)]'
                            : 'text-[var(--cd-text)] hover:bg-[var(--cd-orange-soft)]',
                        )
                      }
                    >
                      {({ isActive }) => (
                        <>
                          <item.icon
                            size={17}
                            className={clsx(
                              'shrink-0',
                              !isActive && 'text-[var(--cd-text-muted)]',
                            )}
                          />
                          <span className="flex-1 truncate">{item.label}</span>
                          {/* Repère discret des écrans pas encore livrés. */}
                          {item.phase > 1 && (
                            <span
                              className={clsx(
                                'shrink-0 rounded-full px-1.5 py-px text-[0.625rem] font-bold',
                                isActive
                                  ? 'bg-black/15 text-[var(--cd-black)]'
                                  : 'bg-[var(--cd-surface-2)] text-[var(--cd-text-muted)]',
                              )}
                              title={`Livré en phase ${item.phase}`}
                            >
                              P{item.phase}
                            </span>
                          )}
                        </>
                      )}
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>

        {/* --- Pied ---------------------------------------------------------- */}
        <div className="border-t border-[var(--cd-border)] px-4 py-3">
          <p className="text-[0.6875rem] leading-relaxed text-[var(--cd-text-muted)]">
            Cyclo Dakar · v1.0
            <br />
            <span className="text-[var(--cd-orange-text)]">
              Ensemble, plus loin, plus forts !
            </span>
          </p>
        </div>
      </aside>
    </>
  )
}
