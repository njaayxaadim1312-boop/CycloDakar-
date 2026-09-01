import { useEffect, useRef } from 'react'
import { formatDistance, formatDuration, formatSpeed } from '@/lib/format'
import {
  fitBounds,
  frameAt,
  toScreen,
  type ReplayPoint,
  type Viewport,
} from '@/lib/replay'
import type { SportCode } from '@/types/api'

/**
 * Rendu de la vidéo du parcours, sur un canevas.
 *
 * Un canevas plutôt que Leaflet, pour une raison décisive : `captureStream()`
 * n'existe que sur un `<canvas>`. C'est lui qui permet d'exporter un vrai
 * fichier vidéo depuis le téléphone, sans serveur de rendu ni FFmpeg.
 *
 * Le déroulé suit `docs/video.md` :
 *
 *   0 s      ouverture — sport, titre, date
 *   0,5–90 % la trace se dessine, le marqueur avance, statistiques incrustées
 *   90–100 % écran final — résumé et devise du club
 *
 * Les tuiles OpenStreetMap sont dessinées sous la trace. Elles sont chargées
 * en `crossOrigin` : sans cela le canevas deviendrait « souillé » et
 * `captureStream()` — donc tout l'export — échouerait silencieusement.
 */

export interface MovieMeta {
  title: string
  sport: SportCode
  sportLabel: string
  date: string
  distanceM: number
  durationS: number
  elevationM: number
  zones: string[]
}

interface RouteMovieProps {
  points: ReplayPoint[]
  meta: MovieMeta
  /** Progression du film, de 0 à 1. */
  progress: number
  width: number
  height: number
  color: string
  onCanvas?: (canvas: HTMLCanvasElement | null) => void
}

/** Part du film consacrée au trajet ; le reste est l'écran final. */
const TRACK_SHARE = 0.9
const INTRO_SHARE = 0.04

export function RouteMovie({
  points,
  meta,
  progress,
  width,
  height,
  color,
  onCanvas,
}: RouteMovieProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const tiles = useRef(new Map<string, HTMLImageElement>())

  useEffect(() => {
    onCanvas?.(canvasRef.current)
  }, [onCanvas])

  useEffect(() => {
    const canvas = canvasRef.current
    const context = canvas?.getContext('2d')

    if (canvas === null || context === null || context === undefined) return
    if (points.length < 2) return

    const bounds = boundsOf(points)
    const viewport = fitBounds(bounds, width, height)

    draw(context, {
      points,
      meta,
      progress,
      width,
      height,
      color,
      viewport,
      tiles: tiles.current,
      redraw: () => {
        // Une tuile arrive après coup : on redessine, sinon le fond resterait
        // troué jusqu'à la frame suivante.
        const ctx = canvasRef.current?.getContext('2d')

        if (ctx) {
          draw(ctx, {
            points,
            meta,
            progress,
            width,
            height,
            color,
            viewport,
            tiles: tiles.current,
            redraw: () => undefined,
          })
        }
      },
    })
  }, [points, meta, progress, width, height, color])

  return (
    <canvas
      ref={canvasRef}
      width={width}
      height={height}
      className="w-full rounded-[var(--cd-radius-lg)] bg-[var(--cd-surface-2)]"
      role="img"
      aria-label={`Parcours de ${meta.title} : ${formatDistance(meta.distanceM)} en ${formatDuration(meta.durationS)}`}
    />
  )
}

/* -------------------------------------------------------------------------- */

interface DrawContext {
  points: ReplayPoint[]
  meta: MovieMeta
  progress: number
  width: number
  height: number
  color: string
  viewport: Viewport
  tiles: Map<string, HTMLImageElement>
  redraw: () => void
}

function draw(ctx: CanvasRenderingContext2D, c: DrawContext): void {
  const { width, height, progress } = c

  ctx.clearRect(0, 0, width, height)

  // --- Ouverture ---------------------------------------------------------
  if (progress < INTRO_SHARE) {
    drawIntro(ctx, c, progress / INTRO_SHARE)

    return
  }

  const trackProgress = Math.min(
    1,
    Math.max(0, (progress - INTRO_SHARE) / (TRACK_SHARE - INTRO_SHARE)),
  )

  drawTiles(ctx, c)
  drawTrack(ctx, c, trackProgress)
  drawOverlay(ctx, c, trackProgress)

  // --- Écran final -------------------------------------------------------
  if (progress > TRACK_SHARE) {
    drawOutro(ctx, c, (progress - TRACK_SHARE) / (1 - TRACK_SHARE))
  }
}

/**
 * Fond de carte.
 *
 * Les tuiles manquantes ne sont pas attendues : on dessine ce qu'on a et on
 * redessine à leur arrivée. Bloquer l'animation sur le réseau la rendrait
 * saccadée sur une connexion mobile — exactement le contexte d'usage.
 */
function drawTiles(ctx: CanvasRenderingContext2D, c: DrawContext): void {
  const { viewport, width, height, tiles, redraw } = c

  ctx.fillStyle = '#e8e6e1'
  ctx.fillRect(0, 0, width, height)

  const n = 2 ** viewport.zoom
  const scale = Math.min(width, height) / viewport.span
  const tileSize = scale / n

  if (!Number.isFinite(tileSize) || tileSize < 1) return

  const x0 = Math.floor(viewport.x0 * n)
  const y0 = Math.floor(viewport.y0 * n)
  const x1 = Math.ceil((viewport.x0 + (width / scale)) * n)
  const y1 = Math.ceil((viewport.y0 + (height / scale)) * n)

  // Garde-fou : un cadrage aberrant demanderait des milliers de tuiles à
  // OpenStreetMap, ce qui ferait bannir l'application.
  if ((x1 - x0) * (y1 - y0) > 400) return

  for (let x = x0; x <= x1; x++) {
    for (let y = y0; y <= y1; y++) {
      if (x < 0 || y < 0 || x >= n || y >= n) continue

      const key = `${viewport.zoom}/${x}/${y}`
      let image = tiles.get(key)

      if (image === undefined) {
        image = new Image()
        // Indispensable : sans `crossOrigin`, le canevas devient « souillé »
        // et `captureStream()` échoue — l'export vidéo entier tomberait.
        image.crossOrigin = 'anonymous'
        image.src = `https://tile.openstreetmap.org/${viewport.zoom}/${x}/${y}.png`
        image.addEventListener('load', redraw, { once: true })
        tiles.set(key, image)
      }

      if (image.complete && image.naturalWidth > 0) {
        ctx.drawImage(
          image,
          (x / n - viewport.x0) * scale,
          (y / n - viewport.y0) * scale,
          tileSize,
          tileSize,
        )
      }
    }
  }

  // Voile clair : les tuiles sont bavardes, la trace doit rester le sujet.
  ctx.fillStyle = 'rgba(255,255,255,0.35)'
  ctx.fillRect(0, 0, width, height)
}

function drawTrack(ctx: CanvasRenderingContext2D, c: DrawContext, ratio: number): void {
  const { points, viewport, width, height, color } = c

  const last = points[points.length - 1]
  const first = points[0]

  if (first === undefined || last === undefined) return

  const elapsed = first.t + (last.t - first.t) * ratio
  const frame = frameAt(points, elapsed)

  // Trace complète, en filigrane : on voit où l'on va, ce qui donne le
  // sentiment d'un parcours plutôt que d'un trait qui pousse au hasard.
  ctx.strokeStyle = 'rgba(26,26,26,0.16)'
  ctx.lineWidth = Math.max(2, width / 220)
  ctx.lineJoin = 'round'
  ctx.lineCap = 'round'
  ctx.beginPath()

  points.forEach((point, index) => {
    const p = toScreen(point.lat, point.lng, viewport, width, height)
    index === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y)
  })

  ctx.stroke()

  // Portion parcourue, en couleur du sport.
  ctx.strokeStyle = color
  ctx.lineWidth = Math.max(3, width / 150)
  ctx.beginPath()

  for (let i = 0; i <= frame.index; i++) {
    const point = points[i] as ReplayPoint
    const p = toScreen(point.lat, point.lng, viewport, width, height)
    i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y)
  }

  const head = toScreen(frame.lat, frame.lng, viewport, width, height)
  ctx.lineTo(head.x, head.y)
  ctx.stroke()

  // Départ, en vert.
  const start = toScreen(first.lat, first.lng, viewport, width, height)
  dot(ctx, start.x, start.y, width / 90, '#32CD32')

  // Arrivée, dès que l'animation l'atteint : l'afficher plus tôt vendrait la
  // fin avant que le film ne l'ait racontée.
  if (ratio > 0.98) {
    const end = toScreen(last.lat, last.lng, viewport, width, height)
    dot(ctx, end.x, end.y, width / 90, '#DC2626')
  }

  // Marqueur mobile, avec son halo.
  ctx.fillStyle = 'rgba(255,255,255,0.85)'
  ctx.beginPath()
  ctx.arc(head.x, head.y, width / 55, 0, Math.PI * 2)
  ctx.fill()
  dot(ctx, head.x, head.y, width / 90, color)
}

function drawOverlay(ctx: CanvasRenderingContext2D, c: DrawContext, ratio: number): void {
  const { points, meta, width, height, color } = c

  const first = points[0]
  const last = points[points.length - 1]

  if (first === undefined || last === undefined) return

  const frame = frameAt(points, first.t + (last.t - first.t) * ratio)
  const pad = width * 0.045
  const barHeight = height * 0.13

  // Bandeau sombre : les chiffres doivent rester lisibles quelle que soit la
  // tuile qui passe dessous.
  const gradient = ctx.createLinearGradient(0, height - barHeight * 1.6, 0, height)
  gradient.addColorStop(0, 'rgba(0,0,0,0)')
  gradient.addColorStop(1, 'rgba(0,0,0,0.78)')
  ctx.fillStyle = gradient
  ctx.fillRect(0, height - barHeight * 1.6, width, barHeight * 1.6)

  const values: [string, string][] = [
    [formatDistance(frame.distanceM), 'distance'],
    [formatDuration(frame.elapsedS), 'durée'],
    [formatSpeed(frame.speedMps), 'vitesse'],
  ]

  const columnWidth = (width - pad * 2) / values.length

  values.forEach(([value, label], index) => {
    const x = pad + columnWidth * index

    ctx.textAlign = 'left'
    ctx.fillStyle = '#FFFFFF'
    ctx.font = `800 ${Math.round(width * 0.052)}px Inter, system-ui, sans-serif`
    ctx.fillText(value, x, height - pad * 1.5)

    ctx.fillStyle = 'rgba(255,255,255,0.65)'
    ctx.font = `500 ${Math.round(width * 0.026)}px Inter, system-ui, sans-serif`
    ctx.fillText(label.toUpperCase(), x, height - pad * 0.6)
  })

  // Titre en haut, sur son propre voile.
  ctx.fillStyle = 'rgba(0,0,0,0.55)'
  ctx.fillRect(0, 0, width, height * 0.1)

  ctx.textAlign = 'left'
  ctx.fillStyle = '#FFFFFF'
  ctx.font = `700 ${Math.round(width * 0.036)}px Inter, system-ui, sans-serif`
  ctx.fillText(meta.title, pad, height * 0.062)

  ctx.textAlign = 'right'
  ctx.fillStyle = color
  ctx.font = `700 ${Math.round(width * 0.03)}px Inter, system-ui, sans-serif`
  ctx.fillText(meta.sportLabel.toUpperCase(), width - pad, height * 0.062)
}

function drawIntro(ctx: CanvasRenderingContext2D, c: DrawContext, ratio: number): void {
  const { width, height, meta, color } = c

  ctx.fillStyle = '#1A1A1A'
  ctx.fillRect(0, 0, width, height)

  ctx.globalAlpha = Math.min(1, ratio * 2)
  ctx.textAlign = 'center'

  ctx.fillStyle = color
  ctx.font = `800 ${Math.round(width * 0.075)}px Inter, system-ui, sans-serif`
  ctx.fillText('CYCLO DAKAR', width / 2, height * 0.44)

  ctx.fillStyle = '#FFFFFF'
  ctx.font = `700 ${Math.round(width * 0.045)}px Inter, system-ui, sans-serif`
  ctx.fillText(meta.title, width / 2, height * 0.53)

  ctx.fillStyle = 'rgba(255,255,255,0.6)'
  ctx.font = `500 ${Math.round(width * 0.03)}px Inter, system-ui, sans-serif`
  ctx.fillText(`${meta.sportLabel} · ${meta.date}`, width / 2, height * 0.59)

  ctx.globalAlpha = 1
}

function drawOutro(ctx: CanvasRenderingContext2D, c: DrawContext, ratio: number): void {
  const { width, height, meta, color } = c

  // Fondu au noir plutôt que coupure sèche : la carte reste visible dessous
  // pendant la première moitié.
  ctx.fillStyle = `rgba(26,26,26,${Math.min(0.94, ratio * 1.6)})`
  ctx.fillRect(0, 0, width, height)

  if (ratio < 0.25) return

  ctx.globalAlpha = Math.min(1, (ratio - 0.25) * 3)
  ctx.textAlign = 'center'

  ctx.fillStyle = color
  ctx.font = `800 ${Math.round(width * 0.09)}px Inter, system-ui, sans-serif`
  ctx.fillText(formatDistance(meta.distanceM), width / 2, height * 0.38)

  ctx.fillStyle = '#FFFFFF'
  ctx.font = `600 ${Math.round(width * 0.04)}px Inter, system-ui, sans-serif`
  ctx.fillText(
    `${formatDuration(meta.durationS)}  ·  +${meta.elevationM} m`,
    width / 2,
    height * 0.46,
  )

  if (meta.zones.length > 0) {
    ctx.fillStyle = 'rgba(255,255,255,0.65)'
    ctx.font = `500 ${Math.round(width * 0.028)}px Inter, system-ui, sans-serif`
    ctx.fillText(meta.zones.join(' · '), width / 2, height * 0.53)
  }

  ctx.fillStyle = color
  ctx.font = `700 ${Math.round(width * 0.034)}px Inter, system-ui, sans-serif`
  ctx.fillText('Ensemble, plus loin, plus forts !', width / 2, height * 0.64)

  ctx.globalAlpha = 1
}

function dot(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  radius: number,
  fill: string,
): void {
  ctx.fillStyle = fill
  ctx.beginPath()
  ctx.arc(x, y, radius, 0, Math.PI * 2)
  ctx.fill()

  ctx.strokeStyle = '#FFFFFF'
  ctx.lineWidth = Math.max(1.5, radius / 3)
  ctx.stroke()
}

function boundsOf(points: ReplayPoint[]) {
  const lats = points.map((p) => p.lat)
  const lngs = points.map((p) => p.lng)

  return {
    min_lat: Math.min(...lats),
    max_lat: Math.max(...lats),
    min_lng: Math.min(...lngs),
    max_lng: Math.max(...lngs),
  }
}
