import { useQuery } from '@tanstack/react-query'
import { AlertCircle, ArrowLeft, Download, Pause, Play, RotateCcw } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { RouteMovie, type MovieMeta } from '@/components/replay/RouteMovie'
import { ApiError } from '@/lib/api'
import { fetchActivity } from '@/lib/activities'
import { formatDate } from '@/lib/format'
import { fetchReplay } from '@/lib/replay'
import { SPORT_COLOR } from '@/lib/sports'

/**
 * La sortie rejouée en vidéo.
 *
 * Le parcours se dessine du départ à l'arrivée, le marqueur avance à la
 * vitesse réelle, et les statistiques défilent avec lui. Les pauses se voient :
 * c'est ce qui distingue un vrai rejeu d'une simple animation le long d'un
 * tracé.
 *
 * **La vidéo se fabrique dans le navigateur**, pas sur un serveur. Le canevas
 * est enregistré par `MediaRecorder` : pas de file d'attente, pas de FFmpeg à
 * installer, pas d'attente. Le fichier obtenu se partage tel quel sur WhatsApp.
 *
 * REPORTÉ : le rendu serveur (`docs/video.md` §4) reste utile pour produire du
 * MP4 haute définition sur les navigateurs qui ne savent pas l'encoder, et
 * pour générer une vidéo sans garder l'onglet ouvert.
 */

const FORMATS = [
  { key: '9:16', label: 'Story', width: 720, height: 1280 },
  { key: '1:1', label: 'Carré', width: 900, height: 900 },
  { key: '16:9', label: 'Écran', width: 1280, height: 720 },
] as const

const DURATIONS = [15, 30, 60] as const

export function ActivityMoviePage() {
  const { uuid = '' } = useParams()

  const [format, setFormat] = useState<(typeof FORMATS)[number]>(FORMATS[0])
  const [duration, setDuration] = useState<number>(30)
  const [progress, setProgress] = useState(0)
  const [playing, setPlaying] = useState(false)
  const [recording, setRecording] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  const canvasRef = useRef<HTMLCanvasElement | null>(null)
  const frameRef = useRef<number>(0)
  const startedAt = useRef<number>(0)

  const activity = useQuery({
    queryKey: ['activity', uuid],
    queryFn: () => fetchActivity(uuid),
  })

  const replay = useQuery({
    queryKey: ['replay', uuid],
    queryFn: () => fetchReplay(uuid),
  })

  /* -------------------------------------------------------- déroulement --- */

  useEffect(() => {
    if (!playing) return

    startedAt.current = performance.now() - progress * duration * 1000

    const tick = () => {
      const elapsed = (performance.now() - startedAt.current) / 1000
      const next = Math.min(1, elapsed / duration)

      setProgress(next)

      if (next >= 1) {
        setPlaying(false)

        return
      }

      frameRef.current = requestAnimationFrame(tick)
    }

    frameRef.current = requestAnimationFrame(tick)

    return () => cancelAnimationFrame(frameRef.current)
    // `progress` est volontairement exclu : il change à chaque image, et le
    // remettre en dépendance relancerait la boucle soixante fois par seconde.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [playing, duration])

  const onCanvas = useCallback((canvas: HTMLCanvasElement | null) => {
    canvasRef.current = canvas
  }, [])

  /* ------------------------------------------------------------ export --- */

  async function record() {
    const canvas = canvasRef.current

    if (canvas === null) return

    if (typeof MediaRecorder === 'undefined') {
      setNotice(
        "Ce navigateur ne sait pas enregistrer de vidéo. Essayez Chrome sur Android, ou filmez l'écran.",
      )

      return
    }

    // MP4 d'abord : c'est le seul format que WhatsApp et iOS lisent partout.
    // WebM est le repli, largement accepté sur Android.
    const mimeType = [
      'video/mp4;codecs=avc1',
      'video/webm;codecs=vp9',
      'video/webm',
    ].find((type) => MediaRecorder.isTypeSupported(type))

    if (mimeType === undefined) {
      setNotice("Aucun format vidéo n'est disponible sur ce navigateur.")

      return
    }

    const stream = canvas.captureStream(30)
    const recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: 6_000_000 })
    const chunks: Blob[] = []

    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) chunks.push(event.data)
    }

    const finished = new Promise<void>((resolve) => {
      recorder.onstop = () => {
        const blob = new Blob(chunks, { type: mimeType })
        const url = URL.createObjectURL(blob)
        const extension = mimeType.startsWith('video/mp4') ? 'mp4' : 'webm'

        const link = document.createElement('a')
        link.href = url
        link.download = `cyclo-dakar-${uuid.slice(0, 8)}.${extension}`
        link.click()

        // Sans révocation, le blob resterait en mémoire jusqu'au
        // rechargement de la page — plusieurs mégaoctets pour rien.
        setTimeout(() => URL.revokeObjectURL(url), 10_000)
        resolve()
      }
    })

    setNotice(null)
    setRecording(true)
    setProgress(0)
    recorder.start()

    // On rejoue le film depuis le début, en temps réel : `captureStream`
    // enregistre ce que le canevas affiche réellement, il n'y a pas de rendu
    // hors écran plus rapide.
    setPlaying(true)

    await new Promise((resolve) => setTimeout(resolve, duration * 1000 + 400))

    recorder.stop()
    await finished

    setRecording(false)
    setNotice('Vidéo enregistrée dans vos téléchargements.')
  }

  /* ------------------------------------------------------------- rendu --- */

  if (activity.isLoading || replay.isLoading) {
    return <p className="text-sm text-[var(--cd-text-muted)]">Préparation du parcours…</p>
  }

  const error = [activity.error, replay.error].find(
    (caught): caught is ApiError => caught instanceof ApiError,
  )

  if (error !== undefined || activity.data === undefined || replay.data === undefined) {
    return (
      <div className="space-y-4">
        <BackLink uuid={uuid} />
        <p className="flex items-center gap-2 rounded-[var(--cd-radius-lg)] border border-[var(--cd-danger)] bg-[var(--cd-surface)] p-4 text-sm text-[var(--cd-danger)]">
          <AlertCircle size={16} aria-hidden="true" />
          {error?.message ?? 'Cette sortie est introuvable.'}
        </p>
      </div>
    )
  }

  if (!replay.data.available) {
    return (
      <div className="space-y-4">
        <BackLink uuid={uuid} />
        <p className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-6 text-sm text-[var(--cd-text-muted)]">
          Cette sortie n'a pas de trace GPS enregistrée : il n'y a rien à rejouer.
          Les sorties du jeu de démonstration sont dans ce cas — enregistrez une
          vraie sortie pour voir le film.
        </p>
      </div>
    )
  }

  const meta: MovieMeta = {
    title: activity.data.title,
    sport: activity.data.sport,
    sportLabel: activity.data.sport_label,
    date: activity.data.started_at !== null ? formatDate(activity.data.started_at) : '',
    distanceM: activity.data.distance_m,
    durationS: activity.data.moving_time_s,
    elevationM: activity.data.elevation_gain_m,
    zones: replay.data.zones,
  }

  return (
    <div className="space-y-5">
      <BackLink uuid={uuid} />

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        {/* --- Le film --------------------------------------------------- */}
        <div className="mx-auto w-full max-w-md">
          <RouteMovie
            points={replay.data.points}
            meta={meta}
            progress={progress}
            width={format.width}
            height={format.height}
            color={SPORT_COLOR[activity.data.sport]}
            onCanvas={onCanvas}
          />

          <input
            type="range"
            min={0}
            max={1000}
            value={Math.round(progress * 1000)}
            onChange={(event) => {
              setPlaying(false)
              setProgress(Number(event.target.value) / 1000)
            }}
            disabled={recording}
            aria-label="Position dans le film"
            className="mt-3 w-full accent-[var(--cd-orange)]"
          />

          <div className="mt-2 flex justify-center gap-3">
            <button
              type="button"
              onClick={() => {
                if (progress >= 1) setProgress(0)
                setPlaying((current) => !current)
              }}
              disabled={recording}
              className="flex min-h-12 items-center gap-2 rounded-[var(--cd-radius-pill)] bg-[var(--cd-orange)] px-6 font-bold text-[var(--cd-black)] transition-colors hover:bg-[var(--cd-orange-hover)] disabled:opacity-60"
            >
              {playing ? <Pause size={18} /> : <Play size={18} fill="currentColor" />}
              {playing ? 'Pause' : 'Lire'}
            </button>

            <button
              type="button"
              onClick={() => {
                setPlaying(false)
                setProgress(0)
              }}
              disabled={recording}
              aria-label="Revenir au début"
              className="flex size-12 items-center justify-center rounded-full border border-[var(--cd-border)] text-[var(--cd-text-muted)] transition-colors hover:border-[var(--cd-orange)] disabled:opacity-60"
            >
              <RotateCcw size={18} />
            </button>
          </div>
        </div>

        {/* --- Réglages --------------------------------------------------- */}
        <aside className="space-y-5">
          <section className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface)] p-5">
            <h2 className="text-sm font-semibold">Format</h2>
            <div className="mt-3 grid grid-cols-3 gap-2">
              {FORMATS.map((entry) => (
                <button
                  key={entry.key}
                  type="button"
                  onClick={() => setFormat(entry)}
                  disabled={recording}
                  aria-pressed={format.key === entry.key}
                  className={[
                    'rounded-[var(--cd-radius)] border px-2 py-2 text-xs font-semibold transition-colors disabled:opacity-60',
                    format.key === entry.key
                      ? 'border-[var(--cd-orange)] bg-[var(--cd-orange-soft)] text-[var(--cd-orange-text)]'
                      : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]',
                  ].join(' ')}
                >
                  {entry.label}
                  <span className="block font-normal opacity-70">{entry.key}</span>
                </button>
              ))}
            </div>

            <h2 className="mt-5 text-sm font-semibold">Durée</h2>
            <div className="mt-3 grid grid-cols-3 gap-2">
              {DURATIONS.map((seconds) => (
                <button
                  key={seconds}
                  type="button"
                  onClick={() => setDuration(seconds)}
                  disabled={recording}
                  aria-pressed={duration === seconds}
                  className={[
                    'rounded-[var(--cd-radius)] border px-2 py-2 text-sm font-semibold transition-colors disabled:opacity-60',
                    duration === seconds
                      ? 'border-[var(--cd-orange)] bg-[var(--cd-orange-soft)] text-[var(--cd-orange-text)]'
                      : 'border-[var(--cd-border)] text-[var(--cd-text-muted)]',
                  ].join(' ')}
                >
                  {seconds} s
                </button>
              ))}
            </div>

            {/* Le facteur d'accélération dit ce que la vidéo fait subir au
                temps : une sortie de 2 h en 30 s, c'est ×240. */}
            <p className="mt-3 text-xs text-[var(--cd-text-muted)]">
              Sortie de {Math.round(replay.data.duration_s / 60)} min condensée en{' '}
              {duration} s, soit ×{Math.max(1, Math.round(replay.data.duration_s / duration))}.
            </p>
          </section>

          <button
            type="button"
            onClick={() => void record()}
            disabled={recording}
            className="flex min-h-[72px] w-full items-center justify-center gap-3 rounded-[var(--cd-radius-pill)] bg-[var(--cd-black)] text-lg font-extrabold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
          >
            <Download size={22} />
            {recording ? `Enregistrement… ${Math.round(progress * 100)} %` : 'Créer la vidéo'}
          </button>

          {notice !== null && (
            <p className="rounded-[var(--cd-radius-lg)] border border-[var(--cd-border)] bg-[var(--cd-surface-2)] p-4 text-sm">
              {notice}
            </p>
          )}

          <p className="text-xs leading-relaxed text-[var(--cd-text-muted)]">
            La vidéo se fabrique <strong>dans votre navigateur</strong>, en temps
            réel : gardez cette page au premier plan pendant l'enregistrement.
            Le fichier part ensuite dans vos téléchargements, prêt à partager.
          </p>
        </aside>
      </div>
    </div>
  )
}

function BackLink({ uuid }: { uuid: string }) {
  return (
    <Link
      to={`/activities/${uuid}`}
      className="inline-flex items-center gap-1.5 text-sm text-[var(--cd-text-muted)] hover:text-[var(--cd-text)]"
    >
      <ArrowLeft size={15} aria-hidden="true" />
      Retour à la sortie
    </Link>
  )
}
