import { getData } from '@/lib/api'

/**
 * Rejeu animé d'une sortie.
 *
 * La polyligne stockée suffit à DESSINER un parcours, pas à le REJOUER : elle
 * ne porte aucun temps. Le serveur renvoie donc des points horodatés, et ce
 * module les transforme en images successives.
 */

/** Un point de la trace, tel que le serveur le renvoie. */
export interface ReplayPoint {
  lat: number
  lng: number
  /** Secondes depuis le départ, pauses comprises. */
  t: number
  /** Distance cumulée, en mètres. */
  d: number
  /** Vitesse du segment, en m/s. */
  v: number
  /** Altitude, en mètres. */
  a: number | null
}

export interface ReplayBounds {
  min_lat: number
  max_lat: number
  min_lng: number
  max_lng: number
}

export type ReplayTrack =
  | { available: false; reason: string; points: [] }
  | {
      available: true
      points: ReplayPoint[]
      duration_s: number
      distance_m: number
      bounds: ReplayBounds
      zones: string[]
    }

export function fetchReplay(uuid: string): Promise<ReplayTrack> {
  return getData<ReplayTrack>(`/activities/${uuid}/replay`)
}

/* -------------------------------------------------------------------------- */
/* Projection                                                                 */
/* -------------------------------------------------------------------------- */

/**
 * Projection de Mercator sphérique (EPSG:3857), en coordonnées normalisées.
 *
 * C'est la projection des tuiles OpenStreetMap. Utiliser une simple règle de
 * trois sur la latitude déformerait le tracé — à Dakar l'écart resterait
 * discret, mais la trace ne coïnciderait plus avec les rues du fond de carte,
 * ce qui se voit immédiatement.
 */
export function project(lat: number, lng: number): { x: number; y: number } {
  const sin = Math.sin((lat * Math.PI) / 180)

  return {
    x: (lng + 180) / 360,
    y: 0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI),
  }
}

/**
 * État de la sortie à un instant donné.
 *
 * Interpolé entre deux points : sans cela, le marqueur sauterait d'un point à
 * l'autre et l'animation paraîtrait saccadée, alors que la trace est
 * volontairement décimée.
 */
export interface ReplayFrame {
  lat: number
  lng: number
  distanceM: number
  speedMps: number
  elapsedS: number
  /** Indice du dernier point atteint — la trace se dessine jusque-là. */
  index: number
}

export function frameAt(points: ReplayPoint[], elapsedS: number): ReplayFrame {
  const first = points[0]
  const last = points[points.length - 1]

  if (first === undefined || last === undefined) {
    return { lat: 0, lng: 0, distanceM: 0, speedMps: 0, elapsedS: 0, index: 0 }
  }

  if (elapsedS <= first.t) {
    return { ...first, distanceM: first.d, speedMps: first.v, elapsedS: first.t, index: 0 }
  }

  if (elapsedS >= last.t) {
    return {
      ...last,
      distanceM: last.d,
      speedMps: last.v,
      elapsedS: last.t,
      index: points.length - 1,
    }
  }

  // Recherche dichotomique : sur 600 points appelés 60 fois par seconde, un
  // parcours linéaire ferait 36 000 comparaisons par seconde pour rien.
  let low = 0
  let high = points.length - 1

  while (high - low > 1) {
    const middle = (low + high) >> 1

    if ((points[middle] as ReplayPoint).t <= elapsedS) {
      low = middle
    } else {
      high = middle
    }
  }

  const a = points[low] as ReplayPoint
  const b = points[high] as ReplayPoint
  const span = b.t - a.t
  const ratio = span > 0 ? (elapsedS - a.t) / span : 0

  return {
    lat: a.lat + (b.lat - a.lat) * ratio,
    lng: a.lng + (b.lng - a.lng) * ratio,
    distanceM: Math.round(a.d + (b.d - a.d) * ratio),
    // La vitesse n'est PAS interpolée : elle appartient au segment parcouru.
    // Interpoler entre deux segments inventerait une valeur intermédiaire qui
    // n'a jamais existé.
    speedMps: b.v,
    elapsedS,
    index: low,
  }
}

/* -------------------------------------------------------------------------- */
/* Cadrage                                                                    */
/* -------------------------------------------------------------------------- */

export interface Viewport {
  /** Coin haut-gauche, en coordonnées projetées normalisées. */
  x0: number
  y0: number
  /** Étendue projetée couverte par le canevas. */
  span: number
  zoom: number
}

/**
 * Cadre le canevas sur les limites du parcours.
 *
 * La marge de 12 % évite que la trace ne colle aux bords, où l'incrustation
 * des statistiques la recouvrirait.
 *
 * Le zoom est celui des tuiles : un entier, car les tuiles n'existent qu'à ces
 * niveaux. On prend le plus grand qui contient encore tout le parcours.
 */
export function fitBounds(
  bounds: ReplayBounds,
  width: number,
  height: number,
  margin = 0.12,
): Viewport {
  const topLeft = project(bounds.max_lat, bounds.min_lng)
  const bottomRight = project(bounds.min_lat, bounds.max_lng)

  const spanX = Math.max(bottomRight.x - topLeft.x, 1e-9)
  const spanY = Math.max(bottomRight.y - topLeft.y, 1e-9)

  // Le canevas est carré ou non : on prend l'étendue la plus contraignante,
  // rapportée à la dimension correspondante.
  const span = Math.max(spanX / (width / Math.min(width, height)), spanY) * (1 + margin * 2)

  const centerX = (topLeft.x + bottomRight.x) / 2
  const centerY = (topLeft.y + bottomRight.y) / 2

  const scale = Math.min(width, height) / span
  const zoom = Math.max(1, Math.min(18, Math.floor(Math.log2(scale / 256))))

  return {
    x0: centerX - (span * (width / Math.min(width, height))) / 2,
    y0: centerY - span / 2,
    span,
    zoom,
  }
}

/** Coordonnées écran d'un point, en pixels du canevas. */
export function toScreen(
  lat: number,
  lng: number,
  viewport: Viewport,
  width: number,
  height: number,
): { x: number; y: number } {
  const p = project(lat, lng)
  const scale = Math.min(width, height) / viewport.span

  return {
    x: (p.x - viewport.x0) * scale,
    y: (p.y - viewport.y0) * scale,
  }
}
