import { useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, Pause, Play, Square, TriangleAlert } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiError } from '@/lib/api'
import {
  formatDistance,
  formatDuration,
  formatElevation,
  formatPace,
  formatSpeed,
} from '@/lib/format'
import {
  EMPTY_STATS,
  RecordingSession,
  type LiveStats,
} from '@/lib/recording'
import { SPORTS, SPORT_COLOR, SPORT_ICON, SPORT_LABEL, sportTint } from '@/lib/sports'
import type { SportCode } from '@/types/api'

type Phase = 'choix' | 'demarrage' | 'course' | 'fin'

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

  const [phase, setPhase] = useState<Phase>('choix')
  const [sport, setSport] = useState<SportCode>('CYCLING')
  const [stats, setStats] = useState<LiveStats>(EMPTY_STATS)
  const [paused, setPaused] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [interrupted, setInterrupted] = useState(false)
  const [title, setTitle] = useState('')

  const session = useRef<RecordingSession | null>(null)
  const watchId = useRef<number | null>(null)
  const wakeLock = useRef<WakeLockSentinel | null>(null)

  /* ------------------------------------------------------------ arrêt --- */

  const stopWatching = useCallback(() => {
    if (watchId.current !== null) {
      navigator.geolocation.clearWatch(watchId.current)
      watchId.current = null
    }

    void wakeLock.current?.release().catch(() => undefined)
    wakeLock.current = null
  }, [])

  // Filet de sécurité : quitter la page pendant une sortie relâcherait le
  // GPS sans que rien ne l'arrête proprement.
  useEffect(() => stopWatching, [stopWatching])

  /* ---------------------------------------------------- horloge d'affichage */

  useEffect(() => {
    if (phase !== 'course') return

    const timer = window.setInterval(() => {
      const current = session.current

      if (current !== null) {
        setStats(current.snapshot())
      }
    }, 1000)

    return () => window.clearInterval(timer)
  }, [phase])

  /* --------------------------------------------- envoi périodique des points */

  useEffect(() => {
    if (phase !== 'course') return

    // Toutes les 30 s : assez souvent pour ne rien perdre si le navigateur
    // est fermé brutalement, assez rare pour ne pas peser sur une connexion
    // mobile pendant trois heures.
    const timer = window.setInterval(() => {
      void session.current?.flush().catch(() => undefined)
    }, 30_000)

    return () => window.clearInterval(timer)
  }, [phase])

  /* ------------------------------------------- détection des interruptions */

  useEffect(() => {
    if (phase !== 'course') return

    const onHidden = () => {
      if (document.hidden) {
        // On ne prétend pas continuer : le navigateur va cesser de nous
        // livrer des positions, et la trace aura un trou.
        setInterrupted(true)
      }
    }

    document.addEventListener('visibilitychange', onHidden)

    return () => document.removeEventListener('visibilitychange', onHidden)
  }, [phase])

  /* ------------------------------------------------------------ démarrage */

  async function start() {
    if (!('geolocation' in navigator)) {
      setError("Ce navigateur ne donne pas accès à la position.")

      return
    }

    setError(null)
    setPhase('demarrage')

    const created = new RecordingSession(sport)

    try {
      await created.open()
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? caught.message
          : "Impossible d'ouvrir la sortie. Vérifiez votre connexion.",
      )
      setPhase('choix')

      return
    }

    session.current = created

    // Le verrou d'écran empêche la mise en veille. Il n'existe pas partout
    // (Safari iOS l'a depuis peu, certains navigateurs pas du tout) : son
    // absence ne doit pas empêcher d'enregistrer.
    try {
      wakeLock.current = await navigator.wakeLock?.request('screen')
    } catch {
      wakeLock.current = null
    }

    watchId.current = navigator.geolocation.watchPosition(
      (position) => {
        created.push(position)
        setStats(created.snapshot())
      },
      (geoError) => {
        setError(
          geoError.code === geoError.PERMISSION_DENIED
            ? "Position refusée. Autorisez la localisation pour ce site, puis réessayez."
            : 'Position indisponible pour le moment.',
        )
      },
      {
        // Le GPS réel, pas la triangulation réseau : sans cela, la précision
        // tourne autour de 1 km et le filtre rejette tout.
        enableHighAccuracy: true,
        maximumAge: 0,
        timeout: 20_000,
      },
    )

    setPhase('course')
  }

  function togglePause() {
    const current = session.current
    if (current === null) return

    if (current.isPaused) {
      current.resume()
      setPaused(false)
    } else {
      current.pause()
      setPaused(true)
    }
  }

  async function stop() {
    stopWatching()
    setPhase('fin')
  }

  async function save() {
    const current = session.current
    if (current === null) return

    setError(null)

    try {
      const uuid = await current.finalize(title)

      void queryClient.invalidateQueries({ queryKey: ['stats'] })
      void queryClient.invalidateQueries({ queryKey: ['activities'] })

      navigate(`/activities/${uuid}`)
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? caught.message
          : "L'enregistrement a échoué. Réessayez : rien n'est perdu.",
      )
    }
  }

  async function discard() {
    await session.current?.discard().catch(() => undefined)
    session.current = null

    navigate('/')
  }

  /* --------------------------------------------------------------- rendu */

  if (phase === 'choix' || phase === 'demarrage') {
    return (
      <SportChooser
        sport={sport}
        onSelect={setSport}
        onStart={start}
        starting={phase === 'demarrage'}
        error={error}
      />
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
            onClick={togglePause}
            className="flex min-h-[72px] items-center justify-center gap-2 rounded-[var(--cd-radius-pill)] border border-[var(--cd-border-strong)] text-lg font-bold transition-colors hover:border-[var(--cd-orange)]"
          >
            {paused ? <Play size={22} /> : <Pause size={22} />}
            {paused ? 'Reprendre' : 'Pause'}
          </button>

          <button
            type="button"
            onClick={stop}
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
              onClick={save}
              className="flex items-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-6 py-3 font-bold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)]"
            >
              <Check size={18} />
              Enregistrer
            </button>

            <button
              type="button"
              onClick={discard}
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
