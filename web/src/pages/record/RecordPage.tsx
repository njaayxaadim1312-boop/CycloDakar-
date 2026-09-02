import { useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, Pause, Play, Square, TriangleAlert } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  formatDistance,
  formatDuration,
  formatElevation,
  formatPace,
  formatSpeed,
} from '@/lib/format'
import { SPORTS, SPORT_COLOR, SPORT_ICON, SPORT_LABEL, sportTint } from '@/lib/sports'
import { useRecording } from '@/stores/recording'
import type { SportCode } from '@/types/api'

/** Motifs de rejet, expliqués au membre plutôt qu'affichés en code. */
const REJECTION_LABEL: Record<string, string> = {
  poor_accuracy: 'Signal GPS imprécis — cherchez le ciel dégagé.',
  impossible_speed: 'Saut de position ignoré (réverbération urbaine).',
  impossible_acceleration: 'Accélération impossible ignorée.',
  invalid_coordinates: 'Position invalide ignorée.',
  out_of_order: 'Point hors séquence ignoré.',
  duplicate: 'À l’arrêt.',
}

/**
 * Enregistrement d'une sortie depuis le navigateur.
 *
 * **Le mobile reste la bonne façon d'enregistrer** : lui seul suit la position
 * écran éteint. Le navigateur cesse de recevoir des positions dès que l'écran
 * s'éteint ou que l'onglet passe en arrière-plan. L'écran le dit franchement,
 * demande le verrou d'écran quand il existe, et signale l'interruption si elle
 * survient — plutôt que de rendre une trace tronquée sans prévenir.
 *
 * Les chiffres affichés sont **provisoires** : le serveur recalcule tout à la
 * finalisation, et c'est son résultat qui fait foi. C'est écrit à l'écran.
 */
export function RecordPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  /*
   | TOUT L'ÉTAT DE L'ENREGISTREMENT VIT DANS LE MAGASIN, PAS ICI.
   |
   | Il vivait dans cette page : la session, le suivi GPS et les minuteries
   | étaient des `useRef` du composant, et un effet les relâchait au
   | démontage. Toucher n'importe quel bouton de navigation coupait donc le
   | GPS en silence, et la sortie s'arrêtait là.
   |
   | Ce n'était pas un défaut de nettoyage — le nettoyage faisait ce qu'on lui
   | demandait. C'était un défaut de propriété : une chose qui doit survivre à
   | l'affichage ne peut pas appartenir à l'affichage.
   |
   | Cette page n'est plus qu'une VUE. La quitter ne relâche plus rien.
   */
  const phase = useRecording((etat) => etat.phase)
  const sport = useRecording((etat) => etat.sport)
  const stats = useRecording((etat) => etat.stats)
  const paused = useRecording((etat) => etat.paused)
  const interrupted = useRecording((etat) => etat.interrupted)
  const error = useRecording((etat) => etat.error)
  const orpheline = useRecording((etat) => etat.orpheline)

  const {
    choisirSport,
    demarrer,
    basculerPause,
    arreter,
    enregistrer,
    abandonner,
    chercherOrpheline,
    terminerOrpheline,
    oublierOrpheline,
  } = useRecording.getState()

  const [title, setTitle] = useState('')

  // Une sortie laissée ouverte par un rechargement de page : on la cherche à
  // l'ouverture de cet écran, jamais pendant qu'on enregistre.
  useEffect(() => chercherOrpheline(), [chercherOrpheline])

  async function save() {
    const uuid = await enregistrer(title)

    if (uuid !== null) {
      void queryClient.invalidateQueries({ queryKey: ['stats'] })
      void queryClient.invalidateQueries({ queryKey: ['activities'] })

      setTitle('')
      navigate(`/activities/${uuid}`)
    }
  }

  async function discard() {
    await abandonner()
    setTitle('')
    navigate('/')
  }

  async function terminerLaPrecedente() {
    const uuid = await terminerOrpheline()

    if (uuid !== null) {
      void queryClient.invalidateQueries({ queryKey: ['activities'] })
      navigate(`/activities/${uuid}`)
    }
  }

  /* --------------------------------------------------------------- rendu */

  if (phase === 'inactif' || phase === 'demarrage') {
    return (
      <>
        {orpheline !== null && (
          <SortieRestee
            debutMs={orpheline.debutMs}
            onTerminer={terminerLaPrecedente}
            onEffacer={() => void oublierOrpheline()}
          />
        )}

        <SportChooser
          sport={sport}
          onSelect={choisirSport}
          onStart={() => void demarrer()}
          starting={phase === 'demarrage'}
          error={error}
        />
      </>
    )
  }

  const usesPace = sport !== 'CYCLING'

  return (
    <div className="space-y-4">
      {error !== null && (
        <p className="cd-rise flex items-center gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertTriangle size={16} aria-hidden="true" />
          {error}
        </p>
      )}

      {interrupted && phase === 'course' && (
        <p className="flex items-start gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-warning)] bg-[var(--cd-warning-soft)] p-4 text-sm text-[var(--cd-warning)]">
          <TriangleAlert size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>
            L'écran s'est éteint ou l'onglet a changé : le navigateur a cessé de
            suivre votre position pendant ce temps. La trace comportera un trou.
            Pour un enregistrement continu, utilisez l'application mobile.
          </span>
        </p>
      )}

      {/* --- Le chiffre principal ------------------------------------- */}
      <section
        className="cd-pop rounded-[var(--cd-radius-lg)] p-6 text-center"
        style={{ background: sportTint(sport, 12) }}
      >
        <p className="text-xs font-bold tracking-[0.14em] text-[var(--cd-text-muted)] uppercase">
          {SPORT_LABEL[sport]}
          {paused && ' · en pause'}
        </p>

        <p
          className="mt-2 font-display text-6xl font-extrabold tabular-nums sm:text-7xl"
          style={{ color: SPORT_COLOR[sport] }}
        >
          {formatDistance(stats.distanceM)}
        </p>

        <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
          Chiffres provisoires — recalculés par le serveur à l'arrivée.
        </p>
      </section>

      {/* --- Les mesures --------------------------------------------- */}
      <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Metric label="Durée" value={formatDuration(stats.durationS)} />
        <Metric label="En mouvement" value={formatDuration(stats.movingS)} />
        <Metric
          label={usesPace ? 'Allure' : 'Vitesse'}
          value={
            usesPace
              ? stats.currentSpeedMps > 0.3
                ? formatPace(1000 / stats.currentSpeedMps)
                : '—'
              : formatSpeed(stats.currentSpeedMps)
          }
          highlight
        />
        <Metric label="Moyenne" value={formatSpeed(stats.avgSpeedMps)} />
        <Metric label="Maximum" value={formatSpeed(stats.maxSpeedMps)} />
        <Metric label="Dénivelé" value={formatElevation(stats.elevationGainM)} />
        <Metric
          label="Précision"
          value={stats.accuracyM !== null ? `± ${Math.round(stats.accuracyM)} m` : '—'}
        />
        <Metric label="Points" value={String(stats.pointsKept)} />
      </section>

      {/* --- Qualité du signal ---------------------------------------- */}
      {stats.lastRejection !== null && (
        <p className="text-center text-sm text-[var(--cd-text-muted)]">
          {REJECTION_LABEL[stats.lastRejection] ?? 'Point écarté.'}
          {stats.pointsRejected > 0 && ` (${stats.pointsRejected} écartés au total)`}
        </p>
      )}

      {/* --- Commandes ------------------------------------------------ */}
      {phase === 'course' ? (
        <div className="grid grid-cols-2 gap-3">
          <button
            type="button"
            onClick={basculerPause}
            className="flex min-h-[72px] items-center justify-center gap-2 rounded-[var(--cd-radius-pill)] border border-[var(--cd-border-strong)] text-lg font-bold transition-colors hover:border-[var(--cd-orange)]"
          >
            {paused ? <Play size={22} /> : <Pause size={22} />}
            {paused ? 'Reprendre' : 'Pause'}
          </button>

          <button
            type="button"
            onClick={arreter}
            className="flex min-h-[72px] items-center justify-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-black)] text-lg font-bold text-white transition-opacity hover:opacity-90"
          >
            <Square size={20} fill="currentColor" />
            Terminer
          </button>
        </div>
      ) : (
        <section className="cd-rise space-y-4 rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
          <div>
            <h2 className="text-lg font-bold">Sortie terminée</h2>
            <p className="mt-1 text-sm text-[var(--cd-text-muted)]">
              {stats.pointsKept} points retenus. Le serveur va recalculer la
              distance, le dénivelé et les allures à partir de la trace complète.
            </p>
          </div>

          <label className="block space-y-1.5">
            <span className="block text-sm font-semibold">Titre (facultatif)</span>
            <input
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              maxLength={120}
              placeholder="Corniche matin"
              className="w-full rounded-[var(--cd-radius-sm)] border border-[var(--cd-border-strong)] bg-[var(--cd-surface)] px-3 py-2.5 text-[15px] outline-none focus:border-[var(--cd-orange)]"
            />
          </label>

          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={() => void save()}
              className="flex items-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-6 py-3 font-bold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)]"
            >
              <Check size={18} />
              Enregistrer
            </button>

            <button
              type="button"
              onClick={() => void discard()}
              className="rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-6 py-3 font-medium transition-colors hover:border-[var(--cd-danger)] hover:text-[var(--cd-danger)]"
            >
              Abandonner
            </button>
          </div>
        </section>
      )}
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function SportChooser({
  sport,
  onSelect,
  onStart,
  starting,
  error,
}: {
  sport: SportCode
  onSelect: (sport: SportCode) => void
  onStart: () => void
  starting: boolean
  error: string | null
}) {
  return (
    <div className="mx-auto max-w-lg space-y-6">
      <div className="text-center">
        <h1 className="font-display text-3xl font-extrabold">Démarrer une sortie</h1>
        <p className="mt-2 text-sm text-[var(--cd-text-muted)]">
          Choisissez votre activité. Le GPS démarre dès que vous touchez le bouton.
        </p>
      </div>

      {error !== null && (
        <p className="flex items-center gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertTriangle size={16} aria-hidden="true" />
          {error}
        </p>
      )}

      <div className="cd-stagger grid grid-cols-2 gap-3">
        {SPORTS.map((code) => {
          const Icon = SPORT_ICON[code]
          const active = code === sport

          return (
            <button
              key={code}
              type="button"
              onClick={() => onSelect(code)}
              aria-pressed={active}
              className={[
                'flex flex-col items-center gap-2 rounded-[var(--cd-radius-lg)] border-2 p-5 transition-colors',
                active
                  ? 'border-transparent'
                  : 'border-[var(--cd-border)] hover:border-[var(--cd-border-strong)]',
              ].join(' ')}
              style={
                active
                  ? { background: sportTint(code, 16), borderColor: SPORT_COLOR[code] }
                  : undefined
              }
            >
              <Icon size={30} style={{ color: SPORT_COLOR[code] }} aria-hidden="true" />
              <span className="font-semibold">{SPORT_LABEL[code]}</span>
            </button>
          )
        })}
      </div>

      <button
        type="button"
        onClick={onStart}
        disabled={starting}
        className="flex min-h-[72px] w-full items-center justify-center gap-3 rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] text-xl font-extrabold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-60"
      >
        <Play size={24} fill="currentColor" />
        {starting ? 'Démarrage…' : 'Démarrer'}
      </button>

      {/* Dire la limite AVANT de partir, pas après trois heures de sortie. */}
      <p className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface-2)] p-4 text-sm leading-relaxed text-[var(--cd-text-muted)]">
        <strong className="text-[var(--cd-text)]">Gardez l'écran allumé</strong> et
        cette page au premier plan : un navigateur cesse de suivre la position
        dès que l'écran s'éteint. Pour un enregistrement en arrière-plan,
        utilisez l'application mobile.
      </p>
    </div>
  )
}

function Metric({
  label,
  value,
  highlight,
}: {
  label: string
  value: string
  highlight?: boolean
}) {
  return (
    <div className="rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-3 text-center">
      <p className="text-xs text-[var(--cd-text-muted)]">{label}</p>
      <p
        className={[
          'mt-0.5 tabular-nums font-bold',
          highlight ? 'text-2xl text-[var(--cd-orange-text)]' : 'text-xl',
        ].join(' ')}
      >
        {value}
      </p>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * Une sortie laissée ouverte par un rechargement de page.
 *
 * Le magasin survit à la navigation, pas à un rechargement : la mémoire de
 * l'onglet repart à zéro alors que la sortie reste ouverte côté serveur. Sans
 * cet écran, elle y resterait indéfiniment et le membre n'en saurait rien.
 *
 * ON NE PROPOSE PAS DE « REPRENDRE ». Les points non encore envoyés — au pire
 * les dix dernières secondes — sont perdus avec l'onglet, et rien ne peut les
 * retrouver. Reprendre donnerait une trace avec un trou silencieux au milieu ;
 * terminer avec ce que le serveur détient est la seule issue honnête.
 */
function SortieRestee({
  debutMs,
  onTerminer,
  onEffacer,
}: {
  debutMs: number
  onTerminer: () => void
  onEffacer: () => void
}) {
  const debut = new Date(debutMs)

  return (
    <section className="cd-rise mx-auto mb-5 max-w-lg rounded-[var(--cd-radius-lg)] border border-[var(--cd-warning)] bg-[var(--cd-warning-soft)] p-5">
      <p className="flex items-start gap-2 text-sm font-semibold text-[var(--cd-warning)]">
        <TriangleAlert size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
        Une sortie démarrée à {debut.toLocaleTimeString('fr-FR', {
          hour: '2-digit',
          minute: '2-digit',
        })} n’a pas été terminée.
      </p>

      <p className="mt-2 text-sm text-[var(--cd-text-muted)]">
        La page a été rechargée pendant l’enregistrement. Le club a déjà reçu la
        trace jusqu’aux dernières secondes ; le reste est perdu avec l’onglet.
      </p>

      <div className="mt-4 flex flex-wrap gap-3">
        <button
          type="button"
          onClick={onTerminer}
          className="rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-5 py-2.5 text-sm font-bold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)]"
        >
          Terminer cette sortie
        </button>

        <button
          type="button"
          onClick={onEffacer}
          className="rounded-[var(--cd-radius-pill)] border border-[var(--cd-border)] px-5 py-2.5 text-sm font-medium transition-colors hover:border-[var(--cd-danger)] hover:text-[var(--cd-danger)]"
        >
          L’effacer
        </button>
      </div>
    </section>
  )
}
