import type { ReactNode } from 'react'
import { Logo } from '@/components/Logo'

interface AuthLayoutProps {
  title: string
  subtitle?: string
  children: ReactNode
  footer?: ReactNode
}

/**
 * Coquille des écrans de connexion, d'inscription et de mot de passe oublié.
 *
 * Deux colonnes sur grand écran : le formulaire à gauche, un panneau orange
 * portant l'identité du club à droite. Sur mobile, le panneau disparaît — un
 * membre qui se connecte au bord de la route veut le formulaire, pas une
 * illustration qui pousse le champ mot de passe sous la ligne de flottaison.
 */
export function AuthLayout({ title, subtitle, children, footer }: AuthLayoutProps) {
  return (
    <div className="grid min-h-dvh lg:grid-cols-2">
      {/* --- Formulaire --------------------------------------------------- */}
      <div className="flex flex-col justify-center px-5 py-10 sm:px-10">
        <div className="mx-auto w-full max-w-sm">
          <Logo size={52} withWordmark className="mb-8" />

          <h1 className="text-2xl">{title}</h1>
          {subtitle && (
            <p className="mt-1.5 text-sm text-[var(--cd-text-muted)]">{subtitle}</p>
          )}

          <div className="mt-7">{children}</div>

          {footer && (
            <div className="mt-6 text-sm text-[var(--cd-text-muted)]">{footer}</div>
          )}
        </div>
      </div>

      {/* --- Panneau de marque -------------------------------------------- */}
      <div className="relative hidden overflow-hidden bg-[var(--cd-orange)] lg:block">
        <div className="flex h-full flex-col justify-end p-12">
          <p className="font-display text-5xl leading-[1.05] font-extrabold text-[var(--cd-black)]">
            Ensemble,
            <br />
            plus loin,
            <br />
            plus forts !
          </p>
          <p className="mt-5 max-w-md text-[15px] leading-relaxed text-black/70">
            Enregistrez vos sorties au GPS, suivez les événements du club, gérez les
            participations et la caisse — au même endroit, sur téléphone comme sur
            ordinateur.
          </p>
          <p className="mt-8 text-xs font-bold tracking-[0.14em] text-black/55 uppercase">
            Passion · Dépassement · Solidarité
          </p>
        </div>
      </div>
    </div>
  )
}
