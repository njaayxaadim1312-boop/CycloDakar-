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
 * Deux colonnes sur grand écran : le formulaire à gauche, l'affiche du club à
 * droite. Sur mobile, le panneau disparaît — un membre qui se connecte au bord
 * de la route veut le formulaire, pas une image qui pousse le champ mot de
 * passe sous la ligne de flottaison.
 *
 * L'affiche porte déjà son propre mot d'ordre (« Ensemble, pédalons plus
 * loin ! ») : on ne superpose donc aucune reprise de la devise, qui ferait
 * doublon avec ce que l'image dit déjà. Seule reste, en bas, la phrase qui
 * explique ce que fait l'application — la seule information que l'affiche
 * n'apporte pas.
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

      {/* --- Affiche du club ----------------------------------------------
          L'orange reste sous l'image : il tient la colonne pendant le
          chargement et si le fichier venait à manquer, plutôt qu'un rectangle
          blanc au milieu de l'écran. */}
      <div className="relative hidden overflow-hidden bg-[var(--cd-orange)] lg:block">
        <img
          src="/brand/hero.jpg"
          alt="Affiche du club : un membre de Cyclo Dakar à vélo, gilet de sécurité et casque audio, sous le mot d'ordre « Ensemble, pédalons plus loin ! »"
          className="absolute inset-0 size-full object-cover object-center"
        />

        {/* Dégradé de lisibilité : le texte blanc doit tenir quel que soit
            l'endroit où l'affiche est recadrée par `object-cover`. */}
        <div
          className="absolute inset-x-0 bottom-0 h-2/5 bg-gradient-to-t from-black/80 to-transparent"
          aria-hidden="true"
        />

        <p className="absolute inset-x-0 bottom-0 p-10 text-[15px] leading-relaxed text-white/90">
          Enregistrez vos sorties au GPS, suivez les événements du club, gérez les
          participations et la caisse — au même endroit, sur téléphone comme sur
          ordinateur.
        </p>
      </div>
    </div>
  )
}
