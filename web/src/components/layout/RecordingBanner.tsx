import { Radio } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { formatDistance, formatDuration } from '@/lib/format'
import { useRecording } from '@/stores/recording'

/**
 * Bandeau « sortie en cours », visible depuis n'importe quel écran.
 *
 * IL EST LA CONTREPARTIE INDISPENSABLE DU CORRECTIF.
 *
 * L'enregistrement ne s'arrête plus quand on quitte l'écran de suivi — c'était
 * le défaut à réparer. Mais un enregistrement qui continue en coulisse SANS
 * rien montrer serait un autre défaut, plus sournois : le membre croirait
 * l'avoir arrêté en changeant de page, laisserait tourner le GPS des heures
 * durant, et découvrirait une sortie de quarante kilomètres dont trente-huit
 * en voiture.
 *
 * Le bandeau dit donc trois choses : que ça enregistre, depuis combien de
 * temps, et comment y retourner.
 *
 * Il ne s'affiche pas sur l'écran d'enregistrement lui-même : la même
 * information y occupe déjà tout l'écran, en beaucoup plus gros.
 */
export function RecordingBanner() {
  const navigate = useNavigate()
  const location = useLocation()

  // Sélecteurs étroits : l'horloge du magasin bat deux fois par seconde, et
  // seul ce qui est réellement affiché doit se redessiner à cette cadence.
  const phase = useRecording((etat) => etat.phase)
  const paused = useRecording((etat) => etat.paused)
  const distanceM = useRecording((etat) => etat.stats.distanceM)
  const durationS = useRecording((etat) => etat.stats.durationS)

  const enCours = phase === 'course' || phase === 'fin'

  if (!enCours || location.pathname === '/record') return null

  return (
    <button
      type="button"
      onClick={() => navigate('/record')}
      aria-live="polite"
      className="flex w-full items-center gap-3 border-b border-[var(--cd-border)] bg-[var(--cd-black)] px-4 py-2.5 text-left text-white transition-opacity hover:opacity-90 sm:px-6"
    >
      {/* Le point clignote tant que ça enregistre, et s'immobilise en pause :
          c'est le signal qu'on repère sans lire. */}
      <span className="relative flex size-2.5 shrink-0">
        {!paused && (
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-[var(--cd-danger)] opacity-75" />
        )}
        <span
          className="relative inline-flex size-2.5 rounded-full"
          style={{
            background: paused ? 'var(--cd-warning)' : 'var(--cd-danger)',
          }}
        />
      </span>

      <Radio size={16} aria-hidden="true" className="shrink-0 opacity-80" />

      <span className="text-sm font-bold">
        {phase === 'fin'
          ? 'Sortie à enregistrer'
          : paused
            ? 'Sortie en pause'
            : 'Sortie en cours'}
      </span>

      <span className="text-sm tabular-nums opacity-90">
        {formatDistance(distanceM)} · {formatDuration(durationS)}
      </span>

      <span className="ml-auto shrink-0 text-xs font-semibold underline underline-offset-2">
        Revenir
      </span>
    </button>
  )
}
