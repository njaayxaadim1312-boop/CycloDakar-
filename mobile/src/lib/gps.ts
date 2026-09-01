import type { SportCode } from '../types/api'

/**
 * Algorithmes GPS côté téléphone.
 *
 * **Miroir de `backend/app/Services/Gps/`.** Le mobile filtre pour afficher
 * des chiffres justes pendant la sortie ; le serveur refiltre et recalcule
 * tout à la finalisation. Les deux doivent donner le même résultat sur la même
 * trace — c'est ce que vérifient les tests de la phase 18.
 *
 * Les seuils viennent de `GET /api/v1/config` : une seule source de vérité,
 * ajustable par le club sans mettre à jour l'application.
 *
 * Documenté dans docs/gps.md.
 */

/** Rayon terrestre moyen (WGS-84), en mètres. */
const EARTH_RADIUS_M = 6_371_008.8

export interface GpsThresholds {
  maxAccuracyM: number
  maxSpeedMps: number
  maxAccelerationMps2: number
  idleSpeedMps: number
  minSegmentM: number
  elevationThresholdM: number
}

/** Repli utilisé tant que la configuration n'a pas été chargée. */
export const DEFAULT_THRESHOLDS: Record<SportCode, GpsThresholds> = {
  CYCLING: {
    maxAccuracyM: 25,
    maxSpeedMps: 25,
    maxAccelerationMps2: 5,
    idleSpeedMps: 0.8,
    minSegmentM: 1,
    elevationThresholdM: 10,
  },
  RUNNING: {
    maxAccuracyM: 20,
    maxSpeedMps: 12,
    maxAccelerationMps2: 5,
    idleSpeedMps: 0.8,
    minSegmentM: 1,
    elevationThresholdM: 10,
  },
  HIKING: {
    maxAccuracyM: 30,
    maxSpeedMps: 6,
    maxAccelerationMps2: 5,
    idleSpeedMps: 0.8,
    minSegmentM: 1,
    elevationThresholdM: 10,
  },
  /*
   * La marche est le cas le plus exigeant du filtre : a 1,4 m/s, le bruit de
   * position pese autant que le deplacement reel. `minSegmentM` monte donc a
   * 2 m et `idleSpeedMps` descend a 0,4 — sans quoi la trace se remplirait de
   * zigzags qui gonfleraient la distance, et la pause automatique se
   * declencherait sur un marcheur qui avance.
   */
  WALKING: {
    maxAccuracyM: 25,
    maxSpeedMps: 3.5,
    maxAccelerationMps2: 3,
    idleSpeedMps: 0.4,
    /*
     * 8 m, et non 2 : c'est le seuil sous lequel un deplacement n'en est pas
     * un a pied. A 1,2 m/s, le marcheur avance moins vite que ne bouge
     * l'incertitude de position. Mesure sur trace synthetique (72 m reels,
     * 3 m de tremblement lateral) : seuil 2 m -> 135 m mesures, seuil 8 m ->
     * 69 m. Aligne sur `cyclo.sports.WALKING.min_distance_m`.
     */
    minSegmentM: 8,
    elevationThresholdM: 10,
  },
}

export interface Candidate {
  lat: number
  lng: number
  timestampMs: number
  altitudeM: number | null
  speedMps: number | null
  accuracyM: number | null
  headingDeg: number | null
}

export interface Reference {
  lat: number
  lng: number
  timestampMs: number
  altitudeM: number | null
  /** Vitesse implicite du segment précédent, pour le test d'accélération. */
  lastSpeedMps: number | null
}

export type RejectionReason =
  | 'invalid_coordinates'
  | 'poor_accuracy'
  | 'out_of_order'
  | 'duplicate'
  | 'impossible_speed'
  | 'impossible_acceleration'

export type FilterOutcome =
  | { accepted: true; distanceM: number; elapsedMs: number; speedMps: number }
  | { accepted: false; reason: RejectionReason }

/** Distance entre deux coordonnées, en mètres (Haversine). */
export function haversine(
  lat1: number,
  lng1: number,
  lat2: number,
  lng2: number,
): number {
  if (lat1 === lat2 && lng1 === lng2) return 0

  const toRad = Math.PI / 180
  const dPhi = (lat2 - lat1) * toRad
  const dLambda = (lng2 - lng1) * toRad

  const a =
    Math.sin(dPhi / 2) ** 2 +
    Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin(dLambda / 2) ** 2

  return 2 * EARTH_RADIUS_M * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
}

/**
 * Applique les six tests du filtre à un point candidat.
 *
 *   1. VALIDITÉ      coordonnées hors bornes, ou (0,0)
 *   2. PRÉCISION     accuracy au-delà du seuil du sport
 *   3. CHRONOLOGIE   horodatage antérieur ou égal au point précédent
 *   4. DUPLICAT      immobile depuis moins d'une seconde
 *   5. VITESSE       vitesse implicite impossible  ← anti-multipath
 *   6. ACCÉLÉRATION  variation de vitesse impossible
 *
 * Le premier échec rejette le point. `reference` vaut `null` pour le tout
 * premier point d'une trace : il n'y a alors rien à comparer.
 */
export function filterPoint(
  candidate: Candidate,
  reference: Reference | null,
  thresholds: GpsThresholds,
): FilterOutcome {
  // 1. Validité.
  if (
    !Number.isFinite(candidate.lat) ||
    !Number.isFinite(candidate.lng) ||
    Math.abs(candidate.lat) > 90 ||
    Math.abs(candidate.lng) > 180 ||
    // « Null Island » : ce que renvoient certains appareils sans position.
    (candidate.lat === 0 && candidate.lng === 0)
  ) {
    return { accepted: false, reason: 'invalid_coordinates' }
  }

  // 2. Précision. Un point à ±80 m ne dit rien d'utile.
  if (candidate.accuracyM !== null && candidate.accuracyM > thresholds.maxAccuracyM) {
    return { accepted: false, reason: 'poor_accuracy' }
  }

  if (reference === null) {
    return { accepted: true, distanceM: 0, elapsedMs: 0, speedMps: 0 }
  }

  const elapsedMs = candidate.timestampMs - reference.timestampMs

  // 3. Chronologie : un point qui remonte le temps fausserait toutes les
  // vitesses calculées ensuite.
  if (elapsedMs <= 0) {
    return { accepted: false, reason: 'out_of_order' }
  }

  const distanceM = haversine(reference.lat, reference.lng, candidate.lat, candidate.lng)
  const elapsedS = elapsedMs / 1000

  // 4. Duplicat : à l'arrêt, le GPS produit des points quasi identiques.
  if (distanceM < 1 && elapsedS < 1) {
    return { accepted: false, reason: 'duplicate' }
  }

  const speedMps = distanceM / elapsedS

  // 5. Vitesse implicite — le test qui absorbe le multipath urbain.
  // Un saut de 150 m en 1 s donne 150 m/s : aucun cycliste ne fait cela.
  if (speedMps > thresholds.maxSpeedMps) {
    return { accepted: false, reason: 'impossible_speed' }
  }

  // 6. Accélération humainement impossible.
  if (reference.lastSpeedMps !== null) {
    const acceleration = Math.abs(speedMps - reference.lastSpeedMps) / elapsedS

    if (acceleration > thresholds.maxAccelerationMps2) {
      return { accepted: false, reason: 'impossible_acceleration' }
    }
  }

  return { accepted: true, distanceM, elapsedMs, speedMps }
}

/**
 * Le point contribue-t-il au temps ACTIF ?
 *
 * Sous la vitesse de marche lente, on est à l'arrêt : feu rouge,
 * ravitaillement, photo. Sans cette distinction, la vitesse moyenne d'une
 * sortie urbaine serait grossièrement sous-évaluée.
 */
export function isMoving(speedMps: number, thresholds: GpsThresholds): boolean {
  return speedMps >= thresholds.idleSpeedMps
}

/**
 * Dénivelé positif d'un segment, avec hystérésis.
 *
 * Version simplifiée de l'algorithme serveur : le téléphone ne dispose pas de
 * la trace complète pour lisser. On se contente donc du seuil, ce qui suffit
 * à un affichage en direct — la valeur définitive vient du serveur, qui lui
 * voit toute la trace.
 */
export function elevationGain(
  previousAltitudeM: number | null,
  currentAltitudeM: number | null,
  thresholds: GpsThresholds,
): number {
  if (previousAltitudeM === null || currentAltitudeM === null) return 0

  const delta = currentAltitudeM - previousAltitudeM

  return delta >= thresholds.elevationThresholdM ? delta : 0
}

/* -------------------------------------------------------------------------- */
/* Mise en forme                                                              */
/* -------------------------------------------------------------------------- */

/**
 * Lissage exponentiel de la vitesse affichée.
 *
 * Le chiffre brut saute d'une seconde à l'autre et devient illisible au
 * guidon. Le facteur 0,3 donne une valeur qui suit les vraies accélérations
 * sans trembler.
 */
export function smoothSpeed(previous: number | null, current: number): number {
  return previous === null ? current : previous * 0.7 + current * 0.3
}
