import * as Location from 'expo-location'
import * as TaskManager from 'expo-task-manager'
import {
  appendPoint,
  countRawPoint,
  getLastPoint,
  getOngoingActivity,
} from '../lib/database'
import {
  DEFAULT_THRESHOLDS,
  elevationGain,
  filterPoint,
  haversine,
  isMoving,
  type GpsThresholds,
} from '../lib/gps'
import type { SportCode } from '../types/api'

/**
 * Tâche de localisation en arrière-plan.
 *
 * **Point crucial : ce fichier tourne dans un contexte JavaScript SÉPARÉ de
 * l'interface.** Quand l'écran est éteint ou que l'utilisateur a basculé sur
 * une autre application, React n'existe plus — mais cette tâche continue.
 *
 * Deux conséquences qui expliquent tout le reste du fichier :
 *
 *  - **aucun accès à l'état React.** La seule communication avec l'interface
 *    passe par SQLite. C'est pour cela que les statistiques courantes sont
 *    accumulées en base et non en mémoire ;
 *
 *  - **`defineTask` doit être appelé au chargement du module**, hors de tout
 *    composant. Android relance ce contexte à froid après avoir tué
 *    l'application : si l'enregistrement de la tâche dépendait du rendu d'un
 *    écran, la reprise échouerait silencieusement et la fin de la sortie
 *    serait perdue.
 *
 * Voir docs/mobile.md et docs/risques.md §G7.
 */

export const LOCATION_TASK = 'cyclo-dakar-location'

/** Seuils actifs, rafraîchis par l'interface depuis `GET /api/v1/config`. */
let activeThresholds: GpsThresholds | null = null

export function setActiveThresholds(sport: SportCode, thresholds?: GpsThresholds): void {
  activeThresholds = thresholds ?? DEFAULT_THRESHOLDS[sport]
}

/**
 * Etat de mesure, CONSERVE ENTRE LES LOTS.
 *
 * Android ne livre pas les positions une par une mais par paquets, et le
 * contexte de la tache est recree a chaque livraison. Tant que l'ancre etait
 * une variable locale, elle repartait de zero a chaque lot : sur un paquet
 * d'un seul point — le cas courant en mouvement — il n'y avait aucun point de
 * reference, donc AUCUNE distance comptee. Les metres d'une marche
 * disparaissaient purement et simplement.
 *
 * L'etat est indexe par sortie : changer de sortie repart proprement de zero,
 * sans quoi la premiere mesure de la nouvelle serait prise depuis la derniere
 * position de la precedente.
 */
type EtatMesure = {
  uuid: string
  anchor: {
    lat: number
    lng: number
    altitude_m: number | null
    accuracy_m: number | null
    recorded_at: string
  } | null
  lastSpeed: number | null
  /** Instant du franchissement en attente de confirmation. */
  pendingSinceMs: number | null
  /** Fenetre glissante du temps actif, independante de l'ancre. */
  fenetre: Array<{ lat: number; lng: number; timestampMs: number; accuracyM: number | null }>
}

let etat: EtatMesure | null = null

function etatPour(uuid: string): EtatMesure {
  if (etat === null || etat.uuid !== uuid) {
    etat = { uuid, anchor: null, lastSpeed: null, pendingSinceMs: null, fenetre: [] }
  }

  return etat
}

/** Remet l'etat a zero. Appele a l'ouverture d'une sortie et par les tests. */
export function resetMeasurementState(): void {
  etat = null
}

/**
 * Traite un lot de positions.
 *
 * Exporté pour être testable : la tâche elle-même ne peut pas être appelée
 * directement depuis un test.
 */
export async function handleLocations(locations: Location.LocationObject[]): Promise<void> {
  const activity = await getOngoingActivity()

  // Aucune sortie en cours : la tâche a survécu à son utilité (arrêt de
  // l'application pendant l'enregistrement, par exemple). On l'arrête.
  if (activity === null) {
    await stopLocationUpdates()
    return
  }

  const thresholds =
    activeThresholds ?? DEFAULT_THRESHOLDS[activity.sport as SportCode] ?? DEFAULT_THRESHOLDS.CYCLING

  const isPaused = activity.status === 'PAUSED'
  let seq = activity.last_seq

  /*
   * L'etat de mesure survit au lot : voir `etatPour`. L'ancre est le dernier
   * point ayant produit un deplacement REEL, et non le point precedent.
   */
  const mesure = etatPour(activity.uuid)
  let { anchor, lastSpeed, pendingSinceMs } = mesure
  const fenetre = mesure.fenetre

  for (const location of locations) {
    await countRawPoint(activity.uuid)

    const candidate = {
      lat: location.coords.latitude,
      lng: location.coords.longitude,
      timestampMs: location.timestamp,
      altitudeM: location.coords.altitude ?? null,
      speedMps: location.coords.speed ?? null,
      accuracyM: location.coords.accuracy ?? null,
      headingDeg: location.coords.heading ?? null,
    }

    /*
     * ANCRE — le point depuis lequel on mesure.
     *
     * Ce n'est PAS le point precedent, mais le dernier qui a produit un
     * deplacement reel. De proche en proche, chaque tremblement du GPS etait
     * mesure depuis le tremblement d'avant, et tous ceux qui depassaient le
     * seuil s'additionnaient : 72 m parcourus a pied s'affichaient 135 m, et
     * 90 s d'arret inventaient 209 m.
     */
    const reference =
      anchor === null
        ? null
        : {
            lat: anchor.lat,
            lng: anchor.lng,
            timestampMs: Date.parse(anchor.recorded_at),
            altitudeM: anchor.altitude_m,
            lastSpeedMps: lastSpeed,
          }

    const outcome = filterPoint(candidate, reference, thresholds)

    if (!outcome.accepted) {
      // Le point est écarté mais bien compté : c'est ce qui permet
      // d'expliquer une trace courte au lieu de la subir.
      continue
    }

    seq += 1

    /*
     * Pendant une pause, le point est enregistré mais ne contribue NI à la
     * distance NI au temps actif.
     *
     * On l'enregistre quand même : sans lui, on ne saurait pas où le membre
     * s'est arrêté, et la carte montrerait un trou.
     */
    /*
     * Le seuil s'adapte a la precision annoncee : deux points donnes a
     * plus ou moins 15 m ne prouvent pas un deplacement de 9 m.
     */
    const threshold = Math.max(
      thresholds.minSegmentM,
      moyennePrecision(anchor?.accuracy_m ?? null, candidate.accuracyM),
    )

    /*
     * TROIS RAISONS DE NE RIEN COMPTER — ET DANS LES TROIS, L'ANCRE RESTE.
     *
     * Ce dernier point est la correction : l'ancre AVANCAIT quand la lenteur
     * tranchait, et les metres deja parcourus etaient perdus. Une marche
     * ponctuee d'arrets perdait la moitie de sa distance — 96 m reels comptes
     * 43 m — et une flanerie a 0,6 m/s n'etait jamais comptee, le seuil
     * uniforme de 0,8 m/s etant deja une allure de marche.
     *
     * 1. Sous le seuil : c'est le tremblement du GPS. On garde l'ancre, et
     *    une marche lente finit par le franchir.
     * 2. Pas encore CONFIRME : un deplacement reel s'eloigne de l'ancre et y
     *    reste ; une derive franchit le seuil puis revient.
     * 3. Trop LENT, au seuil du sport. Une derive n'atteint pas l'allure de
     *    quelqu'un qui marche : 20 m en 4 minutes font 0,08 m/s.
     */
    const assezLoin = reference !== null && outcome.distanceM >= threshold

    if (!assezLoin) {
      // Un franchissement qui retombe etait un sursaut de derive.
      pendingSinceMs = null
    } else {
      pendingSinceMs ??= candidate.timestampMs
    }

    const confirme =
      assezLoin &&
      pendingSinceMs !== null &&
      candidate.timestampMs - pendingSinceMs >= CONFIRM_MOVE_MS

    const assezVite = isMoving(outcome.speedMps, thresholds)

    const reelDeplacement = confirme && assezVite

    if (reelDeplacement) {
      pendingSinceMs = null
    }

    /*
     * LE TEMPS ACTIF EST UNE AUTRE QUESTION.
     *
     * L'ancre de distance reste volontairement en place pendant un arret —
     * c'est ce qui evite de perdre les metres deja parcourus — mais le temps
     * ecoule depuis elle inclut alors l'arret entier. Un feu rouge de trois
     * minutes entrait ainsi dans le temps de roulage.
     *
     * On compare donc a la position occupee TRENTE SECONDES plus tot, dans
     * une fenetre independante de l'ancre.
     */
    fenetre.push({
      lat: candidate.lat,
      lng: candidate.lng,
      timestampMs: candidate.timestampMs,
      accuracyM: candidate.accuracyM,
    })

    while (
      fenetre.length > 1 &&
      candidate.timestampMs - (fenetre[1]?.timestampMs ?? 0) >= STOP_WINDOW_MS
    ) {
      fenetre.shift()
    }

    const debutFenetre = fenetre[0]
    const enMouvement =
      !isPaused &&
      debutFenetre !== undefined &&
      haversine(debutFenetre.lat, debutFenetre.lng, candidate.lat, candidate.lng) >=
        Math.max(
          thresholds.minSegmentM,
          moyennePrecision(debutFenetre.accuracyM, candidate.accuracyM),
        )

    const moving = enMouvement

    await appendPoint(
      {
        activity_uuid: activity.uuid,
        seq,
        lat: candidate.lat,
        lng: candidate.lng,
        altitude_m: candidate.altitudeM,
        speed_mps: candidate.speedMps,
        accuracy_m: candidate.accuracyM,
        heading_deg: candidate.headingDeg,
        recorded_at: new Date(candidate.timestampMs).toISOString(),
        is_paused: isPaused ? 1 : 0,
      },
      {
        // Sous le seuil : le point est enregistre — la carte en a besoin —
        // mais il n'ajoute AUCUNE distance, et l'ancre ne bouge pas.
        distanceM: isPaused || !reelDeplacement ? 0 : outcome.distanceM,
        movingMs: moving ? outcome.elapsedMs : 0,
        pausedMs: moving ? 0 : outcome.elapsedMs,
        speedMps: reelDeplacement && !isPaused ? outcome.speedMps : 0,
        elevationGainM: isPaused
          ? 0
          : elevationGain(anchor?.altitude_m ?? null, candidate.altitudeM, thresholds),
      },
    )

    /*
     * L'ancre n'avance QUE sur un deplacement reel. C'est toute la
     * correction : la garder immobile empeche le bruit de s'accumuler, un
     * tremblement apres l'autre.
     *
     * Une pause la remet a zero — le trajet parcouru pendant la pause, le
     * membre peut rentrer en taxi, ne doit pas etre mesure d'un bloc a la
     * reprise.
     */
    if (isPaused) {
      anchor = null
      pendingSinceMs = null
      fenetre.length = 0
    } else if (reelDeplacement || anchor === null) {
      /*
       * L'ancre n'avance QUE sur un deplacement reel — ou au tout premier
       * point. Dans tous les autres cas elle reste : les metres deja
       * parcourus attendent au lieu d'etre perdus.
       */
      anchor = {
        lat: candidate.lat,
        lng: candidate.lng,
        altitude_m: candidate.altitudeM,
        accuracy_m: candidate.accuracyM,
        recorded_at: new Date(candidate.timestampMs).toISOString(),
      }
      lastSpeed = outcome.speedMps
    }
  }

  // On repose l'etat pour le lot suivant.
  mesure.anchor = anchor
  mesure.lastSpeed = lastSpeed
  mesure.pendingSinceMs = pendingSinceMs
}

/*
 * Enregistrement de la tâche, AU CHARGEMENT DU MODULE.
 *
 * Ce n'est pas un détail de style : Android relance ce contexte à froid après
 * avoir tué l'application, et cherche immédiatement une tâche portant ce nom.
 * Si l'enregistrement attendait le rendu d'un composant, la reprise échouerait
 * en silence — et le membre perdrait la fin de sa sortie sans savoir pourquoi.
 */
TaskManager.defineTask(LOCATION_TASK, async ({ data, error }) => {
  if (error) {
    // On ne peut rien afficher ici : personne ne regarde. On sort proprement
    // plutôt que de laisser l'exception tuer la tâche.
    console.warn('[GPS] erreur de la tâche de localisation', error.message)
    return
  }

  const locations = (data as { locations?: Location.LocationObject[] } | null)?.locations

  if (!locations?.length) return

  try {
    await handleLocations(locations)
  } catch (caught) {
    console.warn('[GPS] échec du traitement des positions', caught)
  }
})

/* -------------------------------------------------------------------------- */
/* Pilotage                                                                   */
/* -------------------------------------------------------------------------- */

export async function isTracking(): Promise<boolean> {
  return TaskManager.isTaskRegisteredAsync(LOCATION_TASK).then((registered) =>
    registered ? Location.hasStartedLocationUpdatesAsync(LOCATION_TASK) : false,
  )
}

/**
 * Démarre la capture.
 *
 * `foregroundService` est OBLIGATOIRE sous Android 10+ pour la localisation en
 * arrière-plan. Sa notification permanente n'est pas une gêne : c'est le
 * témoin visible que l'enregistrement tourne, et le moyen d'y revenir en un
 * geste depuis n'importe quelle application.
 */
export async function startLocationUpdates(
  sport: SportCode,
  intervalS: number,
  minDistanceM: number,
): Promise<void> {
  if (await isTracking()) return

  await Location.startLocationUpdatesAsync(LOCATION_TASK, {
    // `BestForNavigation` est le seul réglage qui donne une trace exploitable :
    // les modes économes lissent la position et coupent les virages.
    accuracy: Location.Accuracy.BestForNavigation,

    timeInterval: intervalS * 1000,
    // Filtre matériel : l'appareil ne réveille pas l'application tant qu'on
    // n'a pas bougé d'autant. C'est la principale économie de batterie.
    distanceInterval: minDistanceM,

    // Les positions sont livrées par petits paquets plutôt qu'une par une :
    // moins de réveils du processeur, donc moins de batterie.
    deferredUpdatesInterval: 5000,
    deferredUpdatesDistance: 25,

    activityType:
      sport === 'CYCLING'
        ? Location.ActivityType.OtherNavigation
        : Location.ActivityType.Fitness,

    // Sans cela, iOS suspend les mises à jour quand il juge l'utilisateur
    // immobile — et rate le redémarrage après un feu rouge.
    pausesUpdatesAutomatically: false,
    showsBackgroundLocationIndicator: true,

    foregroundService: {
      notificationTitle: 'Cyclo Dakar — sortie en cours',
      notificationBody: 'Votre parcours est enregistré. Appuyez pour revenir.',
      notificationColor: '#FF8C00',
      killServiceOnDestroy: false,
    },
  })
}

export async function stopLocationUpdates(): Promise<void> {
  if (await isTracking()) {
    await Location.stopLocationUpdatesAsync(LOCATION_TASK)
  }
}

/**
 * Combien de fois la precision annoncee un deplacement doit-il valoir pour
 * etre credible ?
 *
 * Deux points donnes chacun a plus ou moins 8 m peuvent se trouver a 16 m l'un
 * de l'autre sans que personne n'ait bouge. Mesure : 1,5 laissait passer 13 m
 * en cinq minutes sur une table ; 2,0 n'en laisse aucun.
 *
 * Miroir de `cyclo.gps.accuracy_factor`.
 */
const ACCURACY_FACTOR = 2.0

/**
 * Duree pendant laquelle un franchissement doit se maintenir avant d'etre
 * compte. Miroir de `cyclo.gps.confirm_move_s`.
 */
const CONFIRM_MOVE_MS = 2_000

/**
 * Fenetre glissante du temps actif. Il en faut autant pour qu'une flanerie a
 * 0,6 m/s franchisse les 16 m qui prouvent un deplacement sous 8 m de
 * precision. Miroir de `cyclo.gps.stop_window_s`.
 */
const STOP_WINDOW_MS = 30_000

/**
 * Incertitude combinee de deux points, en metres.
 *
 * Deux points annonces a plus ou moins 10 m peuvent se trouver a 20 m l'un de
 * l'autre sans que personne n'ait bouge. Un appareil qui n'annonce rien ne
 * releve pas le seuil : on ne penalise pas un telephone discret.
 */
function moyennePrecision(a: number | null, b: number | null): number {
  const valeurs = [a, b].filter((v): v is number => v !== null && v > 0)

  if (valeurs.length === 0) return 0

  return (valeurs.reduce((somme, v) => somme + v, 0) / valeurs.length) * ACCURACY_FACTOR
}
