import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ImagePlus, Trash2 } from 'lucide-react'
import { useRef } from 'react'
import { ApiError, api } from '@/lib/api'
import type { Member } from '@/types/api'

interface CoverPickerProps {
  member: Member
}

/**
 * Le fond d'écran du compte.
 *
 * CHACUN CHOISIT LE SIEN, ET C'EST TOUT L'INTÉRÊT.
 *
 * La photo de profil dit qui l'on est aux autres ; le fond d'écran dit à quoi
 * ressemble SON application quand on l'ouvre. Un membre qui met la corniche au
 * lever du jour derrière ses anneaux de la semaine s'approprie l'outil — et un
 * outil qu'on s'approprie s'ouvre plus souvent.
 *
 * L'APERÇU EST CE QUE L'ON VERRA VRAIMENT.
 *
 * Le bandeau reprend le format et l'assombrissement appliqués par
 * l'application : choisir une image sur une vignette claire pour découvrir
 * ensuite qu'elle passe sous un voile sombre est le genre de surprise qui fait
 * recommencer trois fois.
 */
export function CoverPicker({ member }: CoverPickerProps) {
  const queryClient = useQueryClient()
  const champ = useRef<HTMLInputElement>(null)

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['member'] })
    void queryClient.invalidateQueries({ queryKey: ['my-member'] })
  }

  const envoyer = useMutation({
    mutationFn: async (fichier: File) => {
      const corps = new FormData()
      corps.append('cover', fichier)

      // `multipart` laissé au navigateur : fixer l'en-tête à la main casse la
      // limite (`boundary`) qu'il génère lui-même.
      await api.post(`/members/${member.uuid}/cover`, corps)
    },
    onSuccess: refresh,
  })

  const retirer = useMutation({
    mutationFn: () => api.delete(`/members/${member.uuid}/cover`),
    onSuccess: refresh,
  })

  const error =
    envoyer.error instanceof ApiError
      ? envoyer.error
      : retirer.error instanceof ApiError
        ? retirer.error
        : null

  return (
    <section className="cd-card overflow-hidden">
      {/* --- L'aperçu, tel qu'il apparaîtra ------------------------------- */}
      <div
        className="relative flex h-32 items-end bg-[var(--cd-surface-2)] bg-cover bg-center sm:h-40"
        style={
          member.cover_url === null
            ? undefined
            : { backgroundImage: `url(${member.cover_url})` }
        }
      >
        {/* Le voile : c'est lui qui garantit qu'un texte clair reste lisible
            sur n'importe quelle image, y compris un ciel de midi. Sans lui,
            chaque membre découvrirait que son choix rend l'écran illisible. */}
        {member.cover_url !== null && (
          <div className="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent" />
        )}

        <div className="relative p-4">
          <p
            className={
              member.cover_url === null
                ? 'text-sm font-semibold text-[var(--cd-text-muted)]'
                : 'text-sm font-semibold text-white'
            }
          >
            {member.cover_url === null
              ? 'Aucun fond d’écran choisi'
              : 'Votre fond d’écran'}
          </p>
        </div>
      </div>

      <div className="p-5">
        <h3 className="text-base font-bold">Fond d’écran</h3>
        <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
          L’image qui accompagne vos écrans. Une photo large — un paysage, une
          sortie — passe mieux qu’un portrait.
        </p>

        {error !== null && (
          <p role="alert" className="mt-3 text-sm text-[var(--cd-danger)]">
            {error.fieldError('cover') ?? error.message}
          </p>
        )}

        <input
          ref={champ}
          type="file"
          accept="image/*"
          hidden
          onChange={(event) => {
            const fichier = event.target.files?.[0]
            if (fichier !== undefined) envoyer.mutate(fichier)
            // Remis à zéro : sans cela, rechoisir le MÊME fichier après une
            // erreur ne déclencherait aucun événement.
            event.target.value = ''
          }}
        />

        <div className="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => champ.current?.click()}
            disabled={envoyer.isPending}
            className="cd-btn cd-btn-primary"
          >
            <ImagePlus size={16} />
            {envoyer.isPending
              ? 'Envoi…'
              : member.cover_url === null
                ? 'Choisir une image'
                : 'Changer'}
          </button>

          {member.cover_url !== null && (
            <button
              type="button"
              onClick={() => retirer.mutate()}
              disabled={retirer.isPending}
              className="cd-btn cd-btn-ghost"
            >
              <Trash2 size={16} />
              {retirer.isPending ? '…' : 'Retirer'}
            </button>
          )}
        </div>
      </div>
    </section>
  )
}
