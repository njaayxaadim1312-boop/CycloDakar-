import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * L'ENREGISTREMENT NE DOIT PAS APPARTENIR À L'ÉCRAN QUI L'AFFICHE.
 *
 * Signalement d'un membre : « quand je démarre une activité, si je clique sur
 * un autre bouton ou je quitte, ça coupe l'activité ».
 *
 * La cause n'était pas un nettoyage fautif — le nettoyage faisait exactement ce
 * qu'on lui demandait. C'était un défaut de PROPRIÉTÉ : la session, le suivi
 * GPS et les minuteries étaient des `useRef` de `RecordPage`, et React relâche
 * ce qui appartient à un composant démonté. Toucher n'importe quel bouton de
 * navigation coupait donc le GPS en silence.
 *
 * Ce fichier surveille les deux moitiés du correctif :
 *
 *  - le magasin, de portée module, garde la sortie vivante et ne relâche les
 *    capteurs que sur un ordre explicite du membre ;
 *  - la page ne touche plus jamais à la géolocalisation elle-même — c'est la
 *    garde qui empêchera quelqu'un de réintroduire la panne dans six mois, en
 *    « remettant un peu de propreté » dans un effet.
 */

/*
| Le magasin appelle l'API à l'ouverture et à la finalisation. On la remplace :
| ce qui est testé ici est la POSSESSION des capteurs, pas le dialogue réseau.
*/
vi.mock('@/lib/api', () => ({
  api: {
    post: vi.fn().mockResolvedValue({ data: {} }),
    patch: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn().mockResolvedValue({ data: {} }),
  },
  ApiError: class ApiError extends Error {},
}))

/** Compte les appels réellement passés à la géolocalisation du navigateur. */
interface Espion {
  watch: ReturnType<typeof vi.fn>
  clear: ReturnType<typeof vi.fn>
}

let espion: Espion

function installerNavigateur(): void {
  espion = {
    watch: vi.fn().mockReturnValue(42),
    clear: vi.fn(),
  }

  const stockage = new Map<string, string>()

  vi.stubGlobal('navigator', {
    geolocation: {
      watchPosition: espion.watch,
      clearWatch: espion.clear,
    },
    // Absent chez certains navigateurs : le magasin doit s'en passer.
    wakeLock: undefined,
  })

  vi.stubGlobal('localStorage', {
    getItem: (c: string) => stockage.get(c) ?? null,
    setItem: (c: string, v: string) => void stockage.set(c, v),
    removeItem: (c: string) => void stockage.delete(c),
  })

  vi.stubGlobal('window', {
    setInterval: () => 1,
    clearInterval: () => undefined,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
  })

  vi.stubGlobal('document', {
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    hidden: false,
  })

  vi.stubGlobal('crypto', {
    randomUUID: () => '11111111-2222-3333-4444-555555555555',
  })
}

describe('le magasin possède la sortie, pas l’écran', () => {
  beforeEach(() => {
    installerNavigateur()
    vi.resetModules()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  async function magasin() {
    const { useRecording } = await import('@/stores/recording')

    return useRecording
  }

  it('démarre le suivi et le laisse tourner', async () => {
    const useRecording = await magasin()

    await useRecording.getState().demarrer()

    expect(useRecording.getState().phase).toBe('course')
    expect(espion.watch).toHaveBeenCalledTimes(1)

    // LE POINT CENTRAL. Rien, en dehors d'un ordre du membre, ne doit relâcher
    // le GPS. C'est ce que faisait le démontage de la page.
    expect(espion.clear).not.toHaveBeenCalled()
  })

  it('survit à tout ce qui n’est pas un arrêt demandé', async () => {
    const useRecording = await magasin()
    await useRecording.getState().demarrer()

    // On simule la vie de l'application pendant que le membre roule : il
    // change d'écran, met en pause, reprend, l'onglet passe en arrière-plan.
    useRecording.getState().basculerPause()
    useRecording.getState().basculerPause()
    useRecording.setState({ interrupted: true })

    expect(useRecording.getState().phase).toBe('course')
    expect(espion.clear).not.toHaveBeenCalled()
  })

  it('ne relâche le GPS que lorsque le membre termine', async () => {
    const useRecording = await magasin()
    await useRecording.getState().demarrer()

    useRecording.getState().arreter()

    expect(useRecording.getState().phase).toBe('fin')
    expect(espion.clear).toHaveBeenCalledWith(42)
  })

  it('relâche aussi le GPS sur un abandon', async () => {
    const useRecording = await magasin()
    await useRecording.getState().demarrer()

    await useRecording.getState().abandonner()

    expect(useRecording.getState().phase).toBe('inactif')
    expect(espion.clear).toHaveBeenCalledWith(42)
  })

  it('laisse une marque qui permet de retrouver une sortie orpheline', async () => {
    const useRecording = await magasin()
    await useRecording.getState().demarrer()

    // Un rechargement de page : la mémoire de l'onglet repart à zéro, mais la
    // sortie reste ouverte côté serveur. Sans cette marque, elle y resterait
    // indéfiniment et le membre n'en saurait rien.
    vi.resetModules()
    const { useRecording: apresRechargement } = await import('@/stores/recording')

    apresRechargement.getState().chercherOrpheline()

    expect(apresRechargement.getState().orpheline?.uuid).toBe(
      '11111111-2222-3333-4444-555555555555',
    )
  })

  it('efface la marque une fois la sortie enregistrée', async () => {
    const useRecording = await magasin()
    await useRecording.getState().demarrer()
    useRecording.getState().arreter()
    await useRecording.getState().enregistrer('Corniche matin')

    vi.resetModules()
    const { useRecording: apres } = await import('@/stores/recording')
    apres.getState().chercherOrpheline()

    // Une sortie terminée n'est pas orpheline : la proposer à nouveau ferait
    // croire à un enregistrement perdu et sèmerait le doute.
    expect(apres.getState().orpheline).toBeNull()
  })

  it('ne propose pas de reprendre pendant qu’on enregistre', async () => {
    const useRecording = await magasin()
    await useRecording.getState().demarrer()

    useRecording.getState().chercherOrpheline()

    // La sortie en cours EST la nôtre. La confondre avec une orpheline
    // proposerait de terminer ce qu'on est en train d'enregistrer.
    expect(useRecording.getState().orpheline).toBeNull()
  })
})

describe('l’écran ne touche plus aux capteurs', () => {
  const source = readFileSync(
    fileURLToPath(new URL('../src/pages/record/RecordPage.tsx', import.meta.url)),
    'utf8',
  )

  it('ne manipule plus la géolocalisation', () => {
    /*
     | LA GARDE QUI COMPTE SUR LA DURÉE.
     |
     | Les tests ci-dessus prouvent que le magasin se comporte bien. Celui-ci
     | empêche de revenir en arrière : le jour où quelqu'un remettra un
     | `watchPosition` ou un `clearWatch` dans la page « pour faire propre »,
     | la panne reviendra, silencieuse, et personne ne la verra avant qu'un
     | membre ne perde une sortie.
     */
    expect(source).not.toContain('navigator.geolocation')
    expect(source).not.toContain('clearWatch')
    expect(source).not.toContain('wakeLock')
  })

  it('ne détient plus la session d’enregistrement', () => {
    // `RecordingSession` doit être construite par le magasin, jamais par la
    // page : une session tenue dans un `useRef` disparaît avec le composant.
    expect(source).not.toContain('new RecordingSession')
  })
})
