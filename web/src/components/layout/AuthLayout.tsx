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
 * **L'écran est fixe : tout s'affiche d'un coup, sans défilement.** Une page de
 * connexion qui oblige à faire défiler pour atteindre le bouton donne
 * l'impression que quelque chose manque, et c'est le tout premier écran que
 * voit un membre.
 *
 * Trois moyens y concourent :
 *
 *  - la hauteur est celle de la fenêtre (`h-dvh`), pas un minimum ;
 *  - le formulaire vit dans une **carte bordée**, dimensionnée pour tenir
 *    entièrement — champs compacts, titres resserrés ;
 *  - l'affiche s'adapte à la place restante au lieu de l'imposer.
 *
 * Sur une fenêtre **courte** (moins de 700 px : petit téléphone, ordinateur
 * portable à faible résolution), les blancs se resserrent d'eux-mêmes. C'est la
 * respiration qu'on sacrifie en premier — jamais la taille du texte ni celle
 * des cibles tactiles, qui décideraient à la place du lecteur et de son doigt.
 *
 * Un garde-fou subsiste : `overflow-y-auto` sur la colonne du formulaire. En
 * paysage, ou avec un texte agrandi par accessibilité, la hauteur peut manquer
 * malgré tout — et un bouton inatteignable serait bien pire qu'un léger
 * défilement dans ce cas précis.
 */
export function AuthLayout({ title, subtitle, children, footer }: AuthLayoutProps) {
  return (
    <div className="grid h-dvh overflow-hidden bg-[var(--cd-bg)] lg:grid-cols-2">
      {/* --- Formulaire --------------------------------------------------- */}
      {/*
        `items-start` + `my-auto`, et NON `items-center`.

        Un conteneur qui centre son contenu et qui defile coupe le HAUT quand
        le contenu deborde — et cette partie devient inatteignable, le
        defilement ne remontant jamais au-dessus de zero. Sur un petit
        telephone, le logo et le titre disparaissaient ainsi sans recours.

        `my-auto` donne le meme centrage tant qu'il y a de la place, et rend
        la main au defilement normal des qu'il n'y en a plus.
      */}
      <div className="flex items-start justify-center overflow-y-auto px-4 py-6 sm:px-8 [@media(max-height:700px)]:py-3">
        <div className="my-auto w-full max-w-[22rem]">
          {/*
            La carte bordée : elle délimite la zone de saisie au lieu de la
            laisser flotter au milieu du vide, et donne à l'écran l'assise
            qu'un formulaire nu n'a pas.
          */}
          <div className="cd-fade rounded-[var(--cd-radius-lg)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] p-6 shadow-[var(--cd-shadow-lg)] [@media(max-height:700px)]:p-5">
            <Logo size={40} withWordmark className="mb-5 [@media(max-height:700px)]:mb-3" />

            <h1 className="text-xl">{title}</h1>
            {subtitle && (
              <p className="mt-1 text-[13px] leading-snug text-[var(--cd-text-muted)]">
                {subtitle}
              </p>
            )}

            <div className="mt-5 [@media(max-height:700px)]:mt-4">{children}</div>

            {footer && (
              <div className="mt-4 text-[13px] text-[var(--cd-text-muted)]">{footer}</div>
            )}
          </div>
        </div>
      </div>

      {/* --- Affiche du club ----------------------------------------------
          Montrée ENTIÈRE, jamais recadrée : plein cadre, `object-cover`
          coupait l'en-tête « CYCLO DAKAR », le médaillon et le bandeau des
          valeurs — une bonne part de ce que l'affiche a à dire.

          `min-h-0` sur l'enveloppe est indispensable : sans lui, un élément
          de grille refuse de descendre sous la taille de son contenu, et
          l'image imposerait sa hauteur à toute la page — ce qui ferait
          réapparaître le défilement qu'on cherche à supprimer. */}
      <div className="hidden min-h-0 bg-[var(--cd-orange)] lg:block">
        <div className="flex h-full min-h-0 flex-col items-center justify-center gap-5 p-6 xl:p-8">
          <div className="flex min-h-0 flex-1 items-center">
            <img
              src="/brand/hero.jpg"
              alt="Affiche du club : un membre de Cyclo Dakar à vélo, gilet de sécurité et casque audio, sous le mot d'ordre « Ensemble, pédalons plus loin ! »"
              className="max-h-full w-auto max-w-full rounded-[var(--cd-radius)] object-contain shadow-2xl ring-1 ring-black/10"
            />
          </div>

          {/* La seule information que l'affiche n'apporte pas. Sur le fond
              orange, le noir translucide reste lisible là où du blanc ne le
              serait pas. */}
          <p className="max-w-sm shrink-0 text-center text-[13px] leading-snug text-black/70">
            Enregistrez vos sorties au GPS, suivez les événements du club et gérez
            les participations — au même endroit.
          </p>
        </div>
      </div>
    </div>
  )
}
