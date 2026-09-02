import { create } from 'zustand'
import { ApiError, api } from '@/lib/api'
import { EMPTY_STATS, RecordingSession, type LiveStats } from '@/lib/recording'
import type { SportCode } from '@/types/api'

/**
 * L'enregistrement d'une sortie, sorti du composant.
 *
 * POURQUOI CE MAGASIN EXISTE.
 *
 * L'enregistrement vivait dans l'état de `RecordPage` : la session, le suivi
 * GPS et les minuteries étaient des `useRef` du composant. Conséquence, un
 * membre qui touchait n'importe quel bouton de navigation — ou qui allait
 * simplement voir le tableau de bord — démontait la page, et le nettoyage de
 * l'effet **coupait le GPS**. La sortie s'arrêtait en silence, sans que rien
 * ne le dise, et la trace s'interrompait là.
 *
 * C'était un défaut de PROPRIÉTÉ, pas de nettoyage : le nettoyage faisait
 * exactement ce qu'on lui demandait. Une chose qui doit survivre à
 * l'affichage ne peut pas appartenir à l'affichage.
 *
 * Tout ce qui suit vit donc à la portée du MODULE, hors de React : le suivi
 * GPS continue de tourner pendant que le membre consulte ses cotisations, et
 * l'écran d'enregistrement n'est plus qu'une vue sur cet état.
 *
 * CE QUI RESTE VRAI, ET QU'AUCUN MAGASIN NE CORRIGERA.
 *
 * Un navigateur cesse de livrer des positions quand l'onglet passe en
 * arrière-plan ou que l'écran s'éteint. Naviguer DANS l'application n'y
 * change rien — c'est bien le sens de ce correctif — mais quitter le
 * navigateur, oui. Le mobile reste la bonne façon d'enregistrer.
 */

/** Rafraîchissement de l'affichage. Voir la minuterie plus bas. */
const DISPLAY_INTERVAL_MS = 500

/** Envoi des points au serveur. */
const UPLOAD_INTERVAL_MS = 10_000

/**
 * Marque laissée dans le navigateur pour retrouver une sortie orpheline.
 *
 * Le magasin survit à la navigation, pas à un rechargement de page : la
 * mémoire de l'onglet repart à zéro. La sortie, elle, reste OUVERTE côté
 * serveur. Sans cette marque, elle y resterait indéfiniment et le membre ne
 * saurait même pas qu'elle existe.
 */
const CLE_SORTIE = 'cyclo:sortie-en-cours'

export interface SortieOrpheline {
  uuid: string
  sport: SportCode
  debutMs: number
}

export type RecordingPhase = 'inactif' | 'demarrage' | 'course' | 'fin'

interface RecordingState {
  phase: RecordingPhase
  sport: SportCode
  stats: LiveStats
  paused: boolean

  /** L'onglet est passé en arrière-plan : la trace a un trou. */
  interrupted: boolean

  error: string | null

  /** Une sortie ouverte a été retrouvée après un rechargement. */
  orpheline: SortieOrpheline | null

  choisirSport: (sport: SportCode) => void
  demarrer: () => Promise<void>
  basculerPause: () => void
  arreter: () => void
  enregistrer: (titre: string) => Promise<string | null>
  abandonner: () => Promise<void>

  /** Reprend la main sur une sortie orpheline : la termine ou l'efface. */
  terminerOrpheline: () => Promise<string | null>
  oublierOrpheline: () => Promise<void>
  chercherOrpheline: () => void
}

/*
| État de portée MODULE.
|
| Il ne vit pas dans le magasin parce qu'il n'a rien à afficher : y mettre un
| identifiant de minuterie ferait re-rendre l'application à chaque tic.
*/
let session: RecordingSession | null = null
let watchId: number | null = null
let wakeLock: WakeLockSentinel | null = null
let horloge: number | null = null
let expedition: number | null = null

function relacherCapteurs(): void {
  if (watchId !== null) {
    navigator.geolocation.clearWatch(watchId)
    watchId = null
  }

  void wakeLock?.release().catch(() => undefined)
  wakeLock = null

  if (horloge !== null) {
    window.clearInterval(horloge)
    horloge = null
  }

  if (expedition !== null) {
    window.clearInterval(expedition)
    expedition = null
  }

  window.removeEventListener('beforeunload', prevenirAvantFermeture)
  document.removeEventListener('visibilitychange', signalerInterruption)
}

/**
 * Le navigateur demande confirmation avant de fermer l'onglet.
 *
 * Quitter l'application pendant une sortie coûte les points non encore
 * envoyés — au pire dix secondes — et laisse une sortie ouverte. Une
 * confirmation vaut mieux qu'un onglet fermé par réflexe.
 *
 * Le texte est imposé par le navigateur : on ne peut que déclencher la
 * question, pas la formuler.
 */
function prevenirAvantFermeture(evenement: BeforeUnloadEvent): void {
  evenement.preventDefault()
  evenement.returnValue = ''
}

function signalerInterruption(): void {
  if (document.hidden) {
    // On ne prétend pas continuer : le navigateur va cesser de livrer des
    // positions, et la trace aura un trou. Le dire vaut mieux que rendre une
    // trace tronquée sans prévenir.
    useRecording.setState({ interrupted: true })
  }
}

function memoriser(sortie: SortieOrpheline): void {
  try {
    localStorage.setItem(CLE_SORTIE, JSON.stringify(sortie))
  } catch {
    // Navigation privée, quota plein : la reprise après rechargement sera
    // perdue, mais l'enregistrement en cours n'a aucune raison d'échouer
    // pour autant.
  }
}

function oublier(): void {
  try {
    localStorage.removeItem(CLE_SORTIE)
  } catch {
    // Sans conséquence : au pire une proposition de reprise sans objet.
  }
}

function lireMarque(): SortieOrpheline | null {
  try {
    const brut = localStorage.getItem(CLE_SORTIE)
    if (brut === null) return null

    const lu = JSON.parse(brut) as Partial<SortieOrpheline>

    if (typeof lu.uuid !== 'string' || typeof lu.debutMs !== 'number') return null

    return { uuid: lu.uuid, sport: (lu.sport ?? 'CYCLING') as SportCode, debutMs: lu.debutMs }
  } catch {
    return null
  }
}

export const useRecording = create<RecordingState>((set, get) => ({
  phase: 'inactif',
  sport: 'CYCLING',
  stats: { ...EMPTY_STATS },
  paused: false,
  interrupted: false,
  error: null,
  orpheline: null,

  choisirSport: (sport) => set({ sport }),

  chercherOrpheline: () => {
    // Une sortie EN COURS dans cet onglet n'est pas orpheline : c'est la
    // nôtre. Confondre les deux proposerait de terminer ce qu'on est en
    // train d'enregistrer.
    if (get().phase !== 'inactif') return

    set({ orpheline: lireMarque() })
  },

  demarrer: async () => {
    if (!('geolocation' in navigator)) {
      set({ error: "Ce navigateur ne donne pas accès à la position." })

      return
    }

    set({ error: null, phase: 'demarrage', interrupted: false, stats: { ...EMPTY_STATS } })

    const ouverte = new RecordingSession(get().sport)

    try {
      await ouverte.open()
    } catch (caught) {
      set({
        phase: 'inactif',
        error:
          caught instanceof ApiError
            ? caught.message
            : "Impossible d'ouvrir la sortie. Vérifiez votre connexion.",
      })

      return
    }

    session = ouverte
    memoriser({ uuid: ouverte.uuid, sport: ouverte.sport, debutMs: Date.now() })

    // Le verrou d'écran empêche la mise en veille. Il n'existe pas partout
    // (Safari iOS l'a depuis peu, certains navigateurs pas du tout) : son
    // absence ne doit pas empêcher d'enregistrer.
    try {
      wakeLock = (await navigator.wakeLock?.request('screen')) ?? null
    } catch {
      wakeLock = null
    }

    watchId = navigator.geolocation.watchPosition(
      (position) => {
        ouverte.push(position)
        set({ stats: ouverte.snapshot() })
      },
      (geoError) => {
        set({
          error:
            geoError.code === geoError.PERMISSION_DENIED
              ? 'Position refusée. Autorisez la localisation pour ce site, puis réessayez.'
              : 'Position indisponible pour le moment.',
        })
      },
      {
        // Le GPS réel, pas la triangulation réseau : sans cela, la précision
        // tourne autour de 1 km et le filtre rejette tout.
        enableHighAccuracy: true,
        maximumAge: 0,
        timeout: 20_000,
      },
    )

    /*
     | Rafraîchissement deux fois par seconde.
     |
     | Le navigateur livre une position quand il en a une — on ne commande pas
     | sa cadence. Mais l'affichage, lui, se recalcule : la durée avance sans
     | à-coup, et le bandeau des autres écrans reste juste.
     */
    horloge = window.setInterval(() => {
      if (session !== null) set({ stats: session.snapshot() })
    }, DISPLAY_INTERVAL_MS)

    expedition = window.setInterval(() => {
      void session?.flush().catch(() => undefined)
    }, UPLOAD_INTERVAL_MS)

    window.addEventListener('beforeunload', prevenirAvantFermeture)
    document.addEventListener('visibilitychange', signalerInterruption)

    set({ phase: 'course', paused: false })
  },

  basculerPause: () => {
    if (session === null) return

    if (session.isPaused) {
      session.resume()
      set({ paused: false })
    } else {
      session.pause()
      set({ paused: true })
    }
  },

  arreter: () => {
    // Les capteurs sont relâchés dès l'arrêt : le membre a fini de rouler, et
    // continuer à suivre sa position pendant qu'il saisit un titre serait
    // à la fois inutile et indiscret.
    relacherCapteurs()
    set({ phase: 'fin' })
  },

  enregistrer: async (titre) => {
    if (session === null) return null

    set({ error: null })

    try {
      const uuid = await session.finalize(titre)

      session = null
      oublier()
      set({ phase: 'inactif', stats: { ...EMPTY_STATS }, paused: false, interrupted: false })

      return uuid
    } catch (caught) {
      set({
        error:
          caught instanceof ApiError
            ? caught.message
            : "L'enregistrement a échoué. Réessayez : rien n'est perdu.",
      })

      return null
    }
  },

  abandonner: async () => {
    relacherCapteurs()
    await session?.discard().catch(() => undefined)
    session = null
    oublier()

    set({
      phase: 'inactif',
      stats: { ...EMPTY_STATS },
      paused: false,
      interrupted: false,
      error: null,
    })
  },

  /*
  | LES DEUX ISSUES D'UNE SORTIE ORPHELINE.
  |
  | Après un rechargement, on n'a plus la session — donc plus les points non
  | encore envoyés, au pire les dix dernières secondes. Mais le serveur a tout
  | le reste, et c'est lui qui recalcule les statistiques de toute façon.
  |
  | On ne prétend donc pas REPRENDRE la mesure : on propose de terminer avec
  | ce que le serveur détient, ou d'effacer. Prétendre reprendre donnerait une
  | trace avec un trou silencieux au milieu.
  */
  terminerOrpheline: async () => {
    const sortie = get().orpheline
    if (sortie === null) return null

    try {
      await api.post(`/activities/${sortie.uuid}/finalize`, {
        ended_at: new Date().toISOString(),
        // Volontairement absent : le compte de points attendus n'est connu
        // que de la session perdue. Le serveur finalise avec ce qu'il a
        // reçu, ce qui est exactement l'intention ici.
      })

      oublier()
      set({ orpheline: null })

      return sortie.uuid
    } catch (caught) {
      set({
        error:
          caught instanceof ApiError
            ? caught.message
            : "La sortie n'a pas pu être terminée.",
      })

      return null
    }
  },

  oublierOrpheline: async () => {
    const sortie = get().orpheline
    oublier()
    set({ orpheline: null })

    if (sortie !== null) {
      await api.delete(`/activities/${sortie.uuid}`).catch(() => undefined)
    }
  },
}))

/** Vrai tant qu'une sortie est en cours, quel que soit l'écran affiché. */
export function enregistrementEnCours(etat: RecordingState): boolean {
  return etat.phase === 'course' || etat.phase === 'fin'
}
