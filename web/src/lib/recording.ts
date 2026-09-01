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

/**
 * Combien de fois la précision annoncée un déplacement doit-il valoir pour
 * être crédible ?
 *
 * Deux points donnés chacun à ±8 m peuvent se trouver à 16 m l'un de l'autre
 * sans que personne n'ait bougé : le facteur 1 serait naïf. Mesuré sur traces
 * synthétiques (téléphone posé, dérive de 10 m sur 5 min) — 1,5 laissait
 * encore passer 13 m ; 2,0 n'en laisse aucun sans rien coûter aux vraies
 * sorties ; 2,5 dégraderait la marche de 4 à 8 % d'erreur.
 *
 * Miroir de `cyclo.gps.accuracy_factor`.
 */
const ACCURACY_FACTOR = 2.0

/**
 * Incertitude combinée de deux points, en mètres.
 *
 * Deux points annoncés à ±10 m peuvent se trouver à 20 m l'un de l'autre sans
 * que personne n'ait bougé. Un appareil qui n'annonce rien ne relève pas le
 * seuil : on ne pénalise pas un téléphone discret.
 */
function averageAccuracy(a: number | null, b: number | null): number {
  const values = [a, b].filter((value): value is number => value !== null && value > 0)

  if (values.length === 0) return 0

  return (values.reduce((sum, value) => sum + value, 0) / values.length) * ACCURACY_FACTOR
}

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

  /** Dernier point ayant produit un déplacement réel. Voir `push()`. */
  private anchor: Reference | null = null

  /** Précision de l'ancre, pour adapter le seuil au signal. */
  private anchorAccuracyM: number | null = null
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

    const outcome = filterPoint(candidate, this.anchor, this.thresholds)

    if (!outcome.accepted) {
      this.stats.pointsRejected++
      this.stats.lastRejection = outcome.reason

      return false
    }

    this.stats.lastRejection = null

    /*
     * ANCRE — la correction du sur-comptage à la marche.
     *
     * On ne mesure pas depuis le point précédent, mais depuis le dernier
     * point qui a produit un déplacement RÉEL. De proche en proche, chaque
     * tremblement du GPS était mesuré depuis le tremblement d'avant, et tous
     * ceux qui dépassaient le seuil s'additionnaient : 72 m parcourus à pied
     * s'affichaient 135 m, et 90 s d'arrêt inventaient 209 m.
     *
     * Le seuil s'adapte à la précision annoncée par l'appareil : deux points
     * donnés à ±15 m ne prouvent pas un déplacement de 9 m.
     */
    const threshold = Math.max(
      this.thresholds.minSegmentM,
      averageAccuracy(this.anchorAccuracyM, candidate.accuracyM),
    )

    /*
     * Deux raisons de ne rien compter, et de GARDER l'ancre.
     *
     * 1. Le déplacement est sous le seuil : c'est le tremblement du GPS.
     *
     * 2. Il est trop LENT pour en être un. Le seuil de distance seul ne
     *    suffit pas : un récepteur immobile ne tremble pas au hasard, il
     *    dérive lentement de plusieurs mètres par minute. La dérive finit
     *    donc par franchir n'importe quel seuil de distance, l'ancre suit, et
     *    le cycle recommence — 50 m accumulés en cinq minutes sur une table.
     *    La vitesse tranche : 10 m en 60 s font 0,17 m/s, ce qui n'est ni
     *    rouler ni marcher.
     */
    const tooShort = outcome.distanceM < threshold
    const tooSlow = !isMoving(outcome.speedMps, this.thresholds)

    if (this.anchor !== null && (tooShort || tooSlow)) {
      // Le point est tout de même transmis : il décrit où l'on était, la
      // carte en a besoin, et le serveur refiltrera de toute façon.
      this.storePoint(candidate)

      /*
       * L'ancre AVANCE quand c'est la lenteur qui a tranché, et seulement
       * dans ce cas.
       *
       * Une dérive repartira de sa nouvelle position sans jamais rien
       * accumuler, puisqu'elle reste lente. Surtout, un ARRÊT RÉEL suivi
       * d'un départ ne verra pas son temps crédité au premier segment
       * parcouru — sans quoi un feu rouge de trois minutes compterait comme
       * du temps actif.
       *
       * Le tremblement vif, lui, garde l'ancre : c'est le seuil de distance
       * qui l'a rejeté, et il faut continuer d'accumuler la preuve d'un
       * déplacement réel.
       */
      if (tooSlow && !tooShort) {
        this.anchor = {
          lat: candidate.lat,
          lng: candidate.lng,
          timestampMs: candidate.timestampMs,
          altitudeM: candidate.altitudeM,
          lastSpeedMps: 0,
        }
        this.anchorAccuracyM = candidate.accuracyM
        this.smoothedSpeed = 0
        this.stats.currentSpeedMps = 0
      }

      return true
    }

    // La vitesse est lissée avant tout usage : la valeur brute d'un GPS
    // saute de plusieurs km/h d'une seconde à l'autre, et un compteur qui
    // clignote est illisible au guidon.
    this.smoothedSpeed = smoothSpeed(this.smoothedSpeed, outcome.speedMps)

    if (this.anchor !== null) {
      // Tout ce qui parvient ici bouge réellement : la dérive et les arrêts
      // ont été écartés au-dessus.
      this.stats.distanceM += outcome.distanceM
      this.movingMs += outcome.elapsedMs

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

    // Le déplacement est réel : l'ancre suit.
    this.anchor = {
      lat: candidate.lat,
      lng: candidate.lng,
      timestampMs: candidate.timestampMs,
      altitudeM: candidate.altitudeM,
      lastSpeedMps: this.smoothedSpeed,
    }
    this.anchorAccuracyM = candidate.accuracyM

    this.storePoint(candidate)
    this.stats.currentSpeedMps = this.smoothedSpeed

    return true
  }

  /**
   * Enregistre un point retenu par le filtre.
   *
   * Appelé même quand le déplacement est sous le seuil : le point décrit où
   * l'on se trouvait, la carte en a besoin, et le serveur refiltrera de toute
   * façon. Seul le CUMUL de distance distingue les deux cas.
   */
  private storePoint(candidate: Candidate): void {
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
  }

  /* --------------------------------------------------------------- pause --- */

  pause(): void {
    if (this.pausedSinceMs === null) {
      this.pausedSinceMs = Date.now()
      // On oublie l'ancre : à la reprise, le segment franchi pendant la
      // pause ne doit pas être compté comme une distance parcourue.
      this.anchor = null
      this.anchorAccuracyM = null
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
