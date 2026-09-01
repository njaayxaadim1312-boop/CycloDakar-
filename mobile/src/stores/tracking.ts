import * as Crypto from 'expo-crypto'
import * as Location from 'expo-location'
import { create } from 'zustand'
import {
  createLocalActivity,
  finishLocalActivity,
  getLocalActivity,
  getOngoingActivity,
  setActivityStatus,
  type LocalActivity,
} from '../lib/database'
import { DEFAULT_THRESHOLDS } from '../lib/gps'
import {
  setActiveThresholds,
  startLocationUpdates,
  stopLocationUpdates,
} from '../services/locationTask'
import { syncPending } from '../services/sync'
import type { SportCode } from '../types/api'

/**
 * Pilotage de l'enregistrement d'une sortie.
 *
 * Ce magasin ne DÉTIENT pas les données : elles vivent dans SQLite, écrites
 * par la tâche de fond. Il ne fait que piloter (démarrer, mettre en pause,
 * arrêter) et republier périodiquement ce que la base contient, pour
 * l'affichage.
 *
 * C'est ce découplage qui permet à l'enregistrement de survivre à l'écran
 * éteint et à la mort de l'application : l'interface peut disparaître, la
 * tâche de fond et la base continuent.
 */

/** Cadence de rafraîchissement de l'affichage, en millisecondes. */
const REFRESH_MS = 1000

export type PermissionState = 'unknown' | 'granted' | 'foreground-only' | 'denied'

interface TrackingState {
  /** Sortie en cours, telle que la base la connaît. `null` si aucune. */
  activity: LocalActivity | null
  permission: PermissionState
  /** Vrai pendant la phase d'acquisition du signal. */
  acquiring: boolean
  starting: boolean
  error: string | null

  checkPermissions: () => Promise<PermissionState>
  requestPermissions: () => Promise<PermissionState>

  /** Reprend une sortie qu'Android aurait interrompue. */
  restore: () => Promise<void>

  start: (sport: SportCode) => Promise<boolean>
  pause: () => Promise<void>
  resume: () => Promise<void>
  stop: () => Promise<string | null>
  discard: () => Promise<void>

  /** Relit la base — appelé par la boucle de rafraîchissement. */
  refresh: () => Promise<void>
}

/**
 * Cadence de capture GPS, en secondes.
 *
 * Miroir de `cyclo.sports.*.sample_interval_s`. A 0,5 s, la trace est deux
 * fois plus fine qu'a 1 Hz : les virages serres et les demarrages se voient
 * mieux sur la carte et dans la video du parcours.
 *
 * Ce n'est pas gratuit : deux fois plus de points a stocker, a transmettre et
 * a filtrer, et une consommation de batterie sensiblement plus forte sur une
 * sortie longue.
 */
const SAMPLE_INTERVAL_S = 0.5

export const useTracking = create<TrackingState>((set, get) => ({
  activity: null,
  permission: 'unknown',
  acquiring: false,
  starting: false,
  error: null,

  async checkPermissions() {
    const foreground = await Location.getForegroundPermissionsAsync()

    if (!foreground.granted) {
      set({ permission: 'denied' })
      return 'denied'
    }

    const background = await Location.getBackgroundPermissionsAsync()
    const state: PermissionState = background.granted ? 'granted' : 'foreground-only'

    set({ permission: state })

    return state
  },

  /**
   * Demande les autorisations, dans l'ordre imposé par les systèmes.
   *
   * Android et iOS refusent tous deux d'accorder l'arrière-plan si le premier
   * plan n'a pas déjà été accordé. Les demander ensemble échouerait
   * silencieusement.
   */
  async requestPermissions() {
    const foreground = await Location.requestForegroundPermissionsAsync()

    if (!foreground.granted) {
      set({ permission: 'denied' })
      return 'denied'
    }

    const background = await Location.requestBackgroundPermissionsAsync()
    const state: PermissionState = background.granted ? 'granted' : 'foreground-only'

    set({ permission: state })

    return state
  },

  /**
   * Reprise après interruption.
   *
   * Le cas réel : Android tue l'application en pleine sortie (Xiaomi, Oppo et
   * Tecno le font agressivement). La trace est intacte en base — il ne reste
   * qu'à relancer la capture.
   */
  async restore() {
    const activity = await getOngoingActivity()

    if (activity === null) {
      set({ activity: null })
      return
    }

    set({ activity })

    if (activity.status === 'RECORDING') {
      setActiveThresholds(activity.sport as SportCode)

      const config = DEFAULT_THRESHOLDS[activity.sport as SportCode]

      await startLocationUpdates(
        activity.sport as SportCode,
        // 0,5 s pour tous les sports : le club a demande une trace deux fois
        // plus fine. Le cout est reel — batterie et volume de donnees — mais
        // il est assume, et le filtre ecarte de toute facon le bruit
        // supplementaire que cette cadence apporte.
        SAMPLE_INTERVAL_S,
        config.minSegmentM * 3,
      )
    }
  },

  async start(sport) {
    set({ starting: true, error: null })

    try {
      const permission = await get().checkPermissions()

      if (permission === 'denied') {
        set({ error: "L'accès à votre position est nécessaire pour enregistrer une sortie." })
        return false
      }

      /*
       * L'identifiant est généré ICI, sur le téléphone, avant tout contact
       * avec le serveur. C'est lui qui rend la synchronisation idempotente :
       * la sortie peut être ouverte hors ligne et transmise trois heures plus
       * tard, sans jamais risquer un doublon.
       */
      const uuid = Crypto.randomUUID()
      const startedAt = new Date().toISOString()

      await createLocalActivity(uuid, sport, startedAt)

      setActiveThresholds(sport)

      const thresholds = DEFAULT_THRESHOLDS[sport]

      await startLocationUpdates(
        sport,
        sport === 'HIKING' ? 3 : 1,
        // Filtre matériel : l'appareil ne réveille l'application que si l'on
        // a bougé d'autant. C'est la principale économie de batterie.
        sport === 'CYCLING' ? 5 : 3,
      )

      set({
        activity: await getLocalActivity(uuid),
        // Phase d'acquisition : on n'affiche pas de chiffres tant que le
        // signal n'est pas stable, plutôt que d'afficher n'importe quoi.
        acquiring: true,
      })

      void thresholds

      return true
    } catch (error) {
      set({ error: "L'enregistrement n'a pas pu démarrer." })
      console.warn('[GPS] démarrage impossible', error)
      return false
    } finally {
      set({ starting: false })
    }
  },

  /**
   * Pause.
   *
   * La capture CONTINUE : les points restent enregistrés, marqués `is_paused`.
   * Sans eux, la carte montrerait un trou si le membre se déplace pendant sa
   * pause — et on ne saurait pas où il s'est arrêté. Ils ne comptent
   * simplement ni en distance ni en temps actif.
   */
  async pause() {
    const activity = get().activity
    if (activity === null) return

    await setActivityStatus(activity.uuid, 'PAUSED')
    await get().refresh()
  },

  async resume() {
    const activity = get().activity
    if (activity === null) return

    await setActivityStatus(activity.uuid, 'RECORDING')
    await get().refresh()
  },

  /**
   * Termine la sortie et lance la transmission.
   *
   * @return l'identifiant de la sortie terminée, pour afficher son résumé.
   */
  async stop() {
    const activity = get().activity
    if (activity === null) return null

    await stopLocationUpdates()
    await finishLocalActivity(activity.uuid, new Date().toISOString())

    set({ activity: null, acquiring: false })

    // Transmission en tâche de fond : le membre voit son résumé tout de
    // suite, sans attendre le réseau. S'il n'y en a pas, la sortie repartira
    // plus tard — elle est en sécurité en base.
    void syncPending()

    return activity.uuid
  },

  /** Abandonne la sortie en cours sans la conserver. */
  async discard() {
    const activity = get().activity
    if (activity === null) return

    await stopLocationUpdates()
    await finishLocalActivity(activity.uuid, new Date().toISOString())
    // La ligne reste en base mais ne sera jamais transmise : la purge
    // hebdomadaire s'en chargera.

    set({ activity: null, acquiring: false })
  },

  async refresh() {
    const current = get().activity
    if (current === null) return

    const fresh = await getLocalActivity(current.uuid)

    set({
      activity: fresh,
      // L'acquisition est terminée dès que le premier point est enregistré.
      acquiring: get().acquiring && (fresh?.last_seq ?? 0) === 0,
    })
  },
}))

/**
 * Boucle de rafraîchissement de l'affichage.
 *
 * Une seconde : assez pour que le chronomètre paraisse vivant, assez peu pour
 * ne pas réveiller le processeur inutilement. À appeler depuis l'écran de
 * suivi, et à arrêter en le quittant.
 */
export function startRefreshLoop(): () => void {
  const timer = setInterval(() => {
    void useTracking.getState().refresh()
  }, REFRESH_MS)

  return () => clearInterval(timer)
}
