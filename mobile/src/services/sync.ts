import { api, ApiError, postData } from '../lib/api'
import {
  countPoints,
  getPendingActivities,
  getPointsToSync,
  markFinalized,
  markServerCreated,
  markSyncedUpTo,
  purgeSyncedActivities,
  type LocalActivity,
  type LocalPoint,
} from '../lib/database'
import type { Activity } from '../types/api'

/**
 * Transmission des sorties au serveur.
 *
 * Conçue pour un réseau qui tombe et revient — le cas normal sur la Corniche,
 * pas l'exception. Trois principes :
 *
 * 1. **Rien ne part avant d'être écrit localement.** La base locale est la
 *    source ; le serveur en reçoit une copie.
 *
 * 2. **Chaque étape est reprenable.** L'ouverture, chaque lot et la
 *    finalisation sont marqués en base au fur et à mesure. Une coupure au
 *    milieu ne fait pas tout recommencer : on repart du dernier `seq` que le
 *    serveur a confirmé.
 *
 * 3. **Une coupure n'est pas une erreur.** Elle interrompt la transmission
 *    sans rien perdre ni alerter le membre : la sortie repartira à la
 *    prochaine occasion.
 */

/** Taille des lots. Le serveur en refuse davantage. */
const BATCH_SIZE = 500

export interface SyncOutcome {
  uuid: string
  status: 'synced' | 'partial' | 'offline' | 'failed'
  pointsSent: number
  message?: string
}

/**
 * Transmet une sortie : ouverture, points, finalisation.
 *
 * Peut être appelée autant de fois qu'on veut sur la même sortie — chaque
 * étape est idempotente côté serveur.
 */
export async function syncActivity(activity: LocalActivity): Promise<SyncOutcome> {
  let pointsSent = 0

  try {
    // --- 1. Ouverture ------------------------------------------------------
    if (activity.server_created === 0) {
      await postData<Activity>('/activities', {
        uuid: activity.uuid,
        sport: activity.sport,
        started_at: activity.started_at,
      })

      await markServerCreated(activity.uuid)
    }

    // --- 2. Points, par lots ----------------------------------------------
    let afterSeq = activity.synced_seq

    for (;;) {
      const batch = await getPointsToSync(activity.uuid, afterSeq, BATCH_SIZE)

      if (batch.length === 0) break

      await postData(`/activities/${activity.uuid}/points`, {
        points: batch.map(toApiPoint),
      })

      // On n'avance le curseur QU'APRÈS confirmation du serveur : si la
      // réponse se perd, le lot repartira, et le serveur l'ignorera.
      const lastSeq = batch[batch.length - 1]!.seq
      await markSyncedUpTo(activity.uuid, lastSeq)

      afterSeq = lastSeq
      pointsSent += batch.length
    }

    // --- 3. Finalisation ---------------------------------------------------
    if (activity.status === 'FINISHED') {
      const total = await countPoints(activity.uuid)

      await postData(`/activities/${activity.uuid}/finalize`, {
        ended_at: activity.ended_at,
        // Le serveur refuse de finaliser s'il n'a pas tout reçu : c'est ce
        // qui empêche de figer une distance fausse.
        expected_points_count: total,
      })

      await markFinalized(activity.uuid)

      return { uuid: activity.uuid, status: 'synced', pointsSent }
    }

    return { uuid: activity.uuid, status: 'partial', pointsSent }
  } catch (error) {
    if (error instanceof ApiError && error.isNetworkError) {
      // Hors ligne : ce n'est pas un échec. La sortie reste intacte en local
      // et repartira à la prochaine occasion.
      return { uuid: activity.uuid, status: 'offline', pointsSent }
    }

    return {
      uuid: activity.uuid,
      status: 'failed',
      pointsSent,
      message: error instanceof ApiError ? error.message : 'Erreur inattendue.',
    }
  }
}

/**
 * Transmet toutes les sorties en attente.
 *
 * Appelée au retour de l'application au premier plan et après un arrêt de
 * sortie. Les sorties sont traitées dans l'ordre chronologique : la plus
 * ancienne d'abord, celle que le membre attend depuis le plus longtemps.
 */
export async function syncPending(): Promise<SyncOutcome[]> {
  const pending = await getPendingActivities()
  const results: SyncOutcome[] = []

  for (const activity of pending) {
    const outcome = await syncActivity(activity)
    results.push(outcome)

    // Inutile d'insister sur les suivantes si le réseau est coupé : on
    // épuiserait la batterie en tentatives vouées à l'échec.
    if (outcome.status === 'offline') break
  }

  // Les traces transmises depuis plus d'une semaine sont purgées : le serveur
  // en détient la copie de référence, et les téléphones du club ont 32 Go.
  if (results.some((r) => r.status === 'synced')) {
    await purgeSyncedActivities(7)
  }

  return results
}

/**
 * Le serveur est-il joignable ?
 *
 * On interroge `/health` plutôt que de se fier à l'état de l'interface réseau :
 * être connecté au Wi-Fi d'un hôtel sans Internet est un cas très courant, et
 * l'indicateur système répondrait « connecté ».
 */
export async function isServerReachable(): Promise<boolean> {
  try {
    await api.get('/health', { timeout: 4000 })
    return true
  } catch {
    return false
  }
}

/* -------------------------------------------------------------------------- */

/**
 * Convertit un point local vers la charge utile attendue par l'API.
 * Les clés diffèrent volontairement de celles de SQLite : le schéma local
 * n'a pas à contraindre le contrat d'API, ni l'inverse.
 */
function toApiPoint(point: LocalPoint): Record<string, unknown> {
  return {
    seq: point.seq,
    lat: point.lat,
    lng: point.lng,
    altitude_m: point.altitude_m,
    speed_mps: point.speed_mps,
    accuracy_m: point.accuracy_m,
    heading_deg: point.heading_deg,
    recorded_at: point.recorded_at,
    is_paused: point.is_paused === 1,
  }
}
