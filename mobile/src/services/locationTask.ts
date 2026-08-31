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
  let previous = await getLastPoint(activity.uuid)
  let lastSpeed: number | null = null

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

    const reference =
      previous === null
        ? null
        : {
            lat: previous.lat,
            lng: previous.lng,
            timestampMs: Date.parse(previous.recorded_at),
            altitudeM: previous.altitude_m,
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
    const moving = !isPaused && isMoving(outcome.speedMps, thresholds)

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
        distanceM: isPaused || outcome.distanceM < thresholds.minSegmentM ? 0 : outcome.distanceM,
        movingMs: moving ? outcome.elapsedMs : 0,
        pausedMs: moving ? 0 : outcome.elapsedMs,
        speedMps: moving ? outcome.speedMps : 0,
        elevationGainM: isPaused
          ? 0
          : elevationGain(previous?.altitude_m ?? null, candidate.altitudeM, thresholds),
      },
    )

    previous = {
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
    }
    lastSpeed = outcome.speedMps
  }
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
