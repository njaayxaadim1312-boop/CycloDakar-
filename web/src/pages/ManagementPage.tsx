import { ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { isDelivered, managementSections, type NavItem } from '@/config/navigation'
import { canAccess, useCurrentUser } from '@/stores/auth'

/**
 * Gestion du club — le sommaire de tout ce qui n'est pas du sport.
 *
 * Cet écran existe pour une raison précise : **regrouper l'argent et
 * l'administration derrière une seule porte**. Le club ne veut pas que sa
 * plateforme donne l'impression de tourner autour des cotisations. Le bureau y
 * accède en un clic depuis le menu ; le membre qui n'y a rien à faire ne voit
 * même pas le bouton, puisque chaque écran porte ses rôles.
 *
 * Chaque entrée affiche ce qu'elle fait. Un intitulé seul (« Participations »)
 * ne dit rien à quelqu'un qui vient d'être nommé trésorier.
 */
export function ManagementPage() {
  const user = useCurrentUser()

  const sections = managementSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => canAccess(user, item.roles)),
    }))
    .filter((section) => section.items.length > 0)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Gestion du club"
        description="Adhérents, participations, trésorerie et administration — les coulisses."
      />

      {sections.length === 0 && (
        <p className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-6 text-sm text-[var(--cd-text-muted)]">
          Vous n'avez accès à aucun outil de gestion. C'est normal pour un compte
          membre : cette application sert d'abord à enregistrer vos sorties.
        </p>
      )}

      {sections.map((section) => (
        <section key={section.title} className="cd-rise">
          <h2 className="mb-3 text-sm font-bold tracking-wide text-[var(--cd-text-muted)] uppercase">
            {section.title}
          </h2>

          <div className="cd-stagger grid gap-3 sm:grid-cols-2">
            {section.items.map((item) => (
              <ManagementCard key={item.to} item={item} />
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function ManagementCard({ item }: { item: NavItem }) {
  const delivered = isDelivered(item)

  return (
    <Link
      to={item.to}
      className="cd-lift flex items-start gap-3 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-4"
    >
      <span className="flex size-10 shrink-0 items-center justify-center rounded-[var(--cd-radius)] bg-[var(--cd-surface-2)]">
        <item.icon size={18} className="text-[var(--cd-text-muted)]" aria-hidden="true" />
      </span>

      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2">
          <span className="font-semibold">{item.label}</span>
          {/* Un écran non livré est signalé ici plutôt que découvert au clic. */}
          {!delivered && (
            <span className="rounded-full bg-[var(--cd-surface-2)] px-1.5 py-px text-[0.625rem] font-bold text-[var(--cd-text-muted)]">
              Phase {item.phase}
            </span>
          )}
        </span>
        <span className="mt-0.5 block text-sm leading-relaxed text-[var(--cd-text-muted)]">
          {item.summary}
        </span>
      </span>

      <ChevronRight size={16} className="mt-1 shrink-0 text-[var(--cd-text-muted)]" />
    </Link>
  )
}
