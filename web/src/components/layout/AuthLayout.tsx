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
 * doublon avec ce que l'image dit déjà. Sous elle, une seule phrase, celle qui
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
          L'affiche est montrée ENTIÈRE, jamais recadrée. Un `object-cover`
          plein cadre coupait l'en-tête « CYCLO DAKAR », le médaillon et le
          bandeau « Passion · Dépassement · Solidarité » — c'est-à-dire une
          bonne part de ce que l'affiche a à dire. Elle est donc posée comme
          une affiche : entière, sur le fond orange du club.

          `flex-1` + `min-h-0` sur l'enveloppe, `max-h-full w-auto` sur l'image :
          elle occupe la hauteur disponible quelle que soit la fenêtre, sans
          hauteur fixe en `vh` qui déborderait sur un écran bas. Et comme la
          boîte de l'image épouse alors les pixels peints, l'arrondi et l'ombre
          tombent sur l'affiche, et non sur une zone vide autour d'elle. */}
      <div className="hidden bg-[var(--cd-orange)] lg:block">
        <div className="flex h-full flex-col items-center justify-center gap-8 p-10 xl:p-12">
          <div className="flex min-h-0 flex-1 items-center">
            <img
              src="/brand/hero.jpg"
              alt="Affiche du club : un membre de Cyclo Dakar à vélo, gilet de sécurité et casque audio, sous le mot d'ordre « Ensemble, pédalons plus loin ! »"
              className="max-h-full w-auto max-w-full rounded-2xl object-contain shadow-2xl ring-1 ring-black/10"
            />
          </div>

          {/* La seule information que l'affiche n'apporte pas. Sur le fond
              orange, le noir translucide reste lisible là où du blanc ne le
              serait pas. */}
          <p className="max-w-md shrink-0 text-center text-[15px] leading-relaxed text-black/70">
            Enregistrez vos sorties au GPS, suivez les événements du club, gérez les
            participations et la caisse — au même endroit, sur téléphone comme sur
            ordinateur.
          </p>
        </div>
      </div>
    </div>
  )
}
