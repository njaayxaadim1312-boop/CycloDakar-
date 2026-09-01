import { api } from '@/lib/api'
import {
  DEFAULT_THRESHOLDS,
  elevationGain,
  filterPoint,
  isMoving,
  smoothSpeed,
  type Candidate,
  type GpsThresholds,
  type Reference,
  type RejectionReason,
} from '@/lib/gps'
import type { SportCode } from '@/types/api'

/**
 * Enregistrement d'une sortie depuis le navigateur.
 *
 * Le mobile reste la bonne façon d'enregistrer : lui seul suit la position
 * écran éteint, via une tâche de fond. Le navigateur, lui, ne reçoit plus rien
 * dès que l'onglet passe en arrière-plan ou que l'écran s'éteint. C'est une
 * limite du navigateur, pas un défaut de ce code, et l'écran le dit clairement
 * plutôt que de laisser croire à une trace complète.
 *
 * Cela reste utile : cela permet d'enregistrer une vraie sortie sans rien
 * installer, et c'est le seul moyen de tester la chaîne complète depuis un
 * téléphone qui n'a pas la Development Build.
 *
 * Trois principes hérités du mobile :
 *
 * 1. **L'uuid est généré par le CLIENT** et sert de clé d'idempotence : un lot
 *    de points renvoyé deux fois ne crée pas de doublon.
 * 2. **Les points sont numérotés (`seq`)** et envoyés par lots. Le curseur
 *    n'avance qu'après confirmation du serveur, donc une coupure réseau ne
 *    perd rien.
 * 3. **Le client n'est jamais cru** : les statistiques affichées ici sont
 *    provisoires. Le serveur recalcule tout à la finalisation, et c'est SON
 *    résultat qui fait foi.
 */

/** Taille maximale d'un lot, imposée par l'API (`StorePointsRequest`). */
const BATCH_SIZE = 500

/** Un point retenu par le filtre, prêt à être envoyé. */
export interface RecordedPoint {
  seq: number
  lat: number
  lng: number
  recorded_at: string
  altitude_m: number | null
  accuracy_m: number | null
  speed_mps: number | null
  heading_deg: number | null
}

/** Ce que l'écran affiche pendant la sortie. */
export interface LiveStats {
  distanceM: number
  /** Temps écoulé depuis le départ, pauses comprises. */
  durationS: number
  /** Temps réellement en mouvement. */
  movingS: number
  currentSpeedMps: number
  avgSpeedMps: number
  maxSpeedMps: number
  elevationGainM: number
  pointsKept: number
  pointsRejected: number
  /** Motif du dernier rejet, pour expliquer un compteur qui n'avance pas. */
  lastRejection: RejectionReason | null
  accuracyM: number | null
}

export const EMPTY_STATS: LiveStats = {
  distanceM: 0,
  durationS: 0,
  movingS: 0,
  currentSpeedMps: 0,
  avgSpeedMps: 0,
  maxSpeedMps: 0,
  elevationGainM: 0,
  pointsKept: 0,
  pointsRejected: 0,
  lastRejection: null,
  accuracyM: null,
}

/**
 * Session d'enregistrement.
 *
 * Une classe plutôt qu'un hook : l'état doit survivre aux rendus de React et
 * ne jamais être recalculé. Un `useState` qui se réinitialiserait perdrait la
 * trace en cours, ce qui est le seul défaut impardonnable ici.
 */
export class RecordingSession {
  readonly uuid: string
  readonly sport: SportCode

  private readonly thresholds: GpsThresholds
  private readonly points: RecordedPoint[] = []
  private readonly trace: { lat: number; lng: number }[] = []

  /** Dernière altitude retenue comme référence du dénivelé. */
  private altitudeReference: number | null = null

  private reference: Reference | null = null
  private smoothedSpeed: number | null = null
  private seq = 0
  private syncedUpTo = 0
  private syncing = false

  private startedAtMs = 0
  private pausedMs = 0
  private pausedSinceMs: number | null = null
  private movingMs = 0

  private stats: LiveStats = { ...EMPTY_STATS }

  constructor(sport: SportCode) {
    this.uuid = crypto.randomUUID()
    this.sport = sport
    this.thresholds = DEFAULT_THRESHOLDS[sport]
  }

  /* ---------------------------------------------------------------- API --- */

  /** Ouvre la sortie côté serveur. À faire avant le premier point. */
  async open(): Promise<void> {
    this.startedAtMs = Date.now()

    await api.post('/activities', {
      uuid: this.uuid,
      sport: this.sport,
      started_at: new Date(this.startedAtMs).toISOString(),
    })
  }

  /**
   * Envoie les points non encore confirmés.
   *
   * Le curseur `syncedUpTo` n'avance QU'APRÈS la réponse du serveur : si
   * l'envoi échoue, les mêmes points repartiront au prochain appel. Le
   * verrou `syncing` empêche deux envois simultanés d'expédier deux fois le
   * même lot.
   */
  async flush(): Promise<void> {
    if (this.syncing) return

    this.syncing = true

    try {
      // Par lots de 500 au plus : c'est la limite acceptée par l'API. Une
      // sortie de trois heures accumule des milliers de points, et tout
      // envoyer d'un coup serait refusé au pire moment — au retour du réseau.
      while (this.syncedUpTo < this.points.length) {
        const batch = this.points.slice(this.syncedUpTo, this.syncedUpTo + BATCH_SIZE)

        await api.post(`/activities/${this.uuid}/points`, { points: batch })

        // Le curseur n'avance qu'ICI, après confirmation : une coupure au
        // milieu d'un lot le fera repartir entier, sans perte ni doublon
        // (l'unicité `(activity_id, seq)` s'en charge côté serveur).
        this.syncedUpTo += batch.length
      }
    } finally {
      this.syncing = false
    }
  }

  /**
   * Termine la sortie.
   *
   * `expected_points_count` permet au serveur de constater qu'il lui manque
   * des points plutôt que de finaliser une trace tronquée en silence.
   */
  async finalize(title: string | null): Promise<string> {
    await this.flush()

    await api.post(`/activities/${this.uuid}/finalize`, {
      ended_at: new Date().toISOString(),
      expected_points_count: this.points.length,
    })

    // Le titre se pose APRÈS, par une modification : la finalisation ne
    // s'occupe que de figer la trace et de recalculer les statistiques.
    // L'y glisser ferait passer un champ d'affichage pour une donnée de
    // mesure.
    if (title !== null && title.trim() !== '') {
      await api.patch(`/activities/${this.uuid}`, { title: title.trim() })
    }

    return this.uuid
  }

  /** Abandon : la sortie est supprimée côté serveur. */
  async discard(): Promise<void> {
    await api.delete(`/activities/${this.uuid}`)
  }

  /* -------------------------------------------------------------- points --- */

  /**
   * Soumet une position au filtre.
   *
   * Renvoie `true` si le point a été retenu — l'appelant peut alors
   * rafraîchir la carte.
   */
  push(position: GeolocationPosition): boolean {
    const c = position.coords

    const candidate: Candidate = {
      lat: c.latitude,
      lng: c.longitude,
      timestampMs: position.timestamp,
      altitudeM: c.altitude ?? null,
      speedMps: c.speed ?? null,
      accuracyM: c.accuracy ?? null,
      headingDeg: c.heading ?? null,
    }

    this.stats.accuracyM = candidate.accuracyM

    const outcome = filterPoint(candidate, this.reference, this.thresholds)

    if (!outcome.accepted) {
      this.stats.pointsRejected++
      this.stats.lastRejection = outcome.reason

      return false
    }

    this.stats.lastRejection = null

    // La vitesse est lissée avant tout usage : la valeur brute d'un GPS
    // saute de plusieurs km/h d'une seconde à l'autre, et un compteur qui
    // clignote est illisible au guidon.
    this.smoothedSpeed = smoothSpeed(this.smoothedSpeed, outcome.speedMps)

    if (this.reference !== null) {
      this.stats.distanceM += outcome.distanceM

      if (isMoving(this.smoothedSpeed, this.thresholds)) {
        this.movingMs += outcome.elapsedMs
      }

      if (this.smoothedSpeed > this.stats.maxSpeedMps) {
        this.stats.maxSpeedMps = this.smoothedSpeed
      }
    }

    // Dénivelé : on cumule les montées franchies depuis la DERNIÈRE altitude
    // de référence, et non depuis le point précédent. Sur un GPS, l'altitude
    // dérive lentement de plusieurs mètres ; comparer deux points voisins
    // accumulerait ce bruit et donnerait des centaines de mètres de faux
    // dénivelé sur un parcours plat — le défaut corrigé en phase 6.
    if (candidate.altitudeM !== null) {
      const gain = elevationGain(this.altitudeReference, candidate.altitudeM, this.thresholds)

      if (gain > 0 || this.altitudeReference === null) {
        this.stats.elevationGainM += gain
        this.altitudeReference = candidate.altitudeM
      } else if (candidate.altitudeM < this.altitudeReference) {
        // En descente, la référence suit : sinon une longue descente
        // empêcherait de compter la montée suivante.
        this.altitudeReference = candidate.altitudeM
      }
    }

    this.reference = {
      lat: candidate.lat,
      lng: candidate.lng,
      timestampMs: candidate.timestampMs,
      altitudeM: candidate.altitudeM,
      lastSpeedMps: this.smoothedSpeed,
    }

    this.points.push({
      seq: this.seq++,
      lat: Number(candidate.lat.toFixed(7)),
      lng: Number(candidate.lng.toFixed(7)),
      recorded_at: new Date(candidate.timestampMs).toISOString(),
      altitude_m: candidate.altitudeM,
      accuracy_m: candidate.accuracyM,
      speed_mps: candidate.speedMps,
      heading_deg: candidate.headingDeg,
    })

    this.trace.push({ lat: candidate.lat, lng: candidate.lng })
    this.stats.pointsKept = this.points.length
    this.stats.currentSpeedMps = this.smoothedSpeed

    return true
  }

  /* --------------------------------------------------------------- pause --- */

  pause(): void {
    if (this.pausedSinceMs === null) {
      this.pausedSinceMs = Date.now()
      // On oublie la référence : à la reprise, le segment franchi pendant la
      // pause ne doit pas être compté comme une distance parcourue.
      this.reference = null
      this.smoothedSpeed = null
      this.stats.currentSpeedMps = 0
    }
  }

  resume(): void {
    if (this.pausedSinceMs !== null) {
      this.pausedMs += Date.now() - this.pausedSinceMs
      this.pausedSinceMs = null
    }
  }

  get isPaused(): boolean {
    return this.pausedSinceMs !== null
  }

  /* ------------------------------------------------------------ lectures --- */

  /** Instantané des statistiques, recalculé à chaque tick d'horloge. */
  snapshot(): LiveStats {
    const now = Date.now()
    const paused = this.pausedMs + (this.pausedSinceMs !== null ? now - this.pausedSinceMs : 0)

    const durationS = Math.max(0, Math.round((now - this.startedAtMs - paused) / 1000))
    const movingS = Math.round(this.movingMs / 1000)

    return {
      ...this.stats,
      durationS,
      movingS,
      // Moyenne sur le temps EN MOUVEMENT, comme le serveur : sinon une
      // pause déjeuner ferait chuter la moyenne d'une sortie parfaite.
      avgSpeedMps: movingS > 0 ? this.stats.distanceM / movingS : 0,
    }
  }

  /** Trace pour la carte, décimée : 400 points suffisent à l'écran. */
  traceForMap(maxPoints = 400): { lat: number; lng: number }[] {
    if (this.trace.length <= maxPoints) return [...this.trace]

    const step = Math.ceil(this.trace.length / maxPoints)
    const reduced = this.trace.filter((_, index) => index % step === 0)
    const last = this.trace[this.trace.length - 1]

    if (last !== undefined && reduced[reduced.length - 1] !== last) {
      reduced.push(last)
    }

    return reduced
  }

  get pointCount(): number {
    return this.points.length
  }

  get pendingCount(): number {
    return this.points.length - this.syncedUpTo
  }
}
